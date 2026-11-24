<?php

namespace App\Services;

use App\Contracts\SimilarityChecker;
use App\Contracts\SubmissionScreener;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\DuplicateDocumentException;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Models\User;
use App\Support\ScreeningResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The document lifecycle, in one place:
 *
 *   submit ──► pending ──(admin)──► approved ──► visible to everyone
 *                   └─────(admin)──► rejected     (reason shown to uploader)
 *
 * A student's submission carries a signed declaration and a submission code.
 * With idr.review.auto_approve_submissions on, the ingestion job approves it
 * as soon as processing succeeds; the Dean's office then confirms the printed
 * certificate + hard copy (markHardCopyReceived), and admins can still reject.
 *
 * plus metadata edits, reprocessing and permanent deletion. Every change an
 * AI component cares about is pushed to Qdrant through VectorSync.
 */
class DocumentService
{
    public function __construct(private readonly VectorSync $vectorSync) {}

    /**
     * Store the file, create the MongoDB record and queue AI ingestion.
     *
     * Students' files start "pending"; an admin's are auto-approved unless
     * idr.review.auto_approve_admin_uploads is false.
     *
     * @param  array{title: string, authors?: list<array{name: string, studentId: ?string}>, author?: ?string, course_code: string, academic_year: string}  $metadata
     * @param  array{name: string, university_id: ?string}|null  $declaration  the statement the uploader accepted
     *
     * @throws DuplicateDocumentException when the same file is already pending/approved
     * @throws RuntimeException when the file could not be stored
     */
    public function submit(User $uploader, UploadedFile $file, array $metadata, ?array $declaration = null): Artifact
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Artifact::where('file_hash', $hash)
            ->whereIn('status', [ReviewStatus::Pending->value, ReviewStatus::Approved->value])
            ->first();

        if ($existing !== null) {
            throw new DuplicateDocumentException($existing);
        }

        $disk = config('idr.storage.disk');
        $directory = config('idr.storage.directory').'/'.now()->format('Y/m');
        $path = $file->storeAs($directory, Str::uuid().'.pdf', $disk);

        if ($path === false) {
            throw new RuntimeException('The file could not be saved.');
        }

        $autoApprove = $uploader->isAdmin() && config('idr.review.auto_approve_admin_uploads');

        try {
            $artifact = new Artifact($metadata);
            $artifact->forceFill([
                'uploaded_by' => (string) $uploader->id,
                'status' => $autoApprove ? ReviewStatus::Approved : ReviewStatus::Pending,
                'reviewed_by' => $autoApprove ? (string) $uploader->id : null,
                'reviewed_at' => $autoApprove ? now() : null,
                'storage_disk' => $disk,
                'file_path' => $path,
                'file_hash' => $hash,
                'original_filename' => $this->cleanFilename($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'processing_status' => ProcessingStatus::Pending,
            ]);

            if ($declaration !== null) {
                $artifact->forceFill([
                    'submission_code' => Artifact::newSubmissionCode(),
                    'declared_name' => $declaration['name'],
                    'declared_university_id' => $declaration['university_id'] ?? null,
                    // A group project's uploader signs on behalf of every listed author.
                    'declaration_text' => str_replace(':name', $declaration['name'], (string) config(
                        count($artifact->authors) > 1 ? 'idr.submission.declaration_group' : 'idr.submission.declaration'
                    )),
                    'declared_at' => now(),
                ]);
            }

            $artifact->save();
        } catch (Throwable $e) {
            // Never leave a file in storage without a record pointing to it.
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        return $artifact;
    }

    /**
     * Queue (or re-queue) AI ingestion. Returns false if the queue is down,
     * in which case the artifact is marked FAILED so it doesn't sit in
     * PENDING forever; an admin can "reprocess" it later.
     */
    public function queueIngestion(Artifact $artifact): bool
    {
        try {
            ProcessArtifactIngestion::dispatch((string) $artifact->id);

            return true;
        } catch (Throwable $e) {
            Log::error('Could not queue artifact for ingestion.', ['artifactId' => (string) $artifact->id, 'error' => $e->getMessage()]);
            $artifact->markFailed('Could not queue the document for processing: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Approve or reject. Records who decided and when, then updates the
     * Qdrant payload (is_approved) so student searches change immediately.
     *
     * @return 'synced'|'queued'|'skipped' what happened to the vector metadata
     */
    public function review(Artifact $artifact, User $admin, ReviewStatus $status, ?string $reason = null): string
    {
        $artifact->forceFill([
            'status' => $status,
            'reviewed_by' => (string) $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $status === ReviewStatus::Rejected ? ($reason ?: null) : null,
        ])->save();

        return $this->vectorSync->syncMetadata($artifact);
    }

    /**
     * Called by the ingestion job once a declared submission is embedded:
     * compares it with every other project and records the closest matches.
     * Flags it (so it stays pending) when its overlap with one earlier project
     * reaches idr.similarity.flag_overlap, or when the check can't run.
     */
    public function recordSimilarity(Artifact $artifact, SimilarityChecker $checker): void
    {
        if (! config('idr.similarity.enabled') || ! $artifact->hasDeclaration()) {
            return;
        }

        try {
            $result = $checker->check($artifact);
        } catch (Throwable $e) {
            Log::warning('Similarity check failed; left for an admin.', ['artifactId' => (string) $artifact->id, 'error' => $e->getMessage()]);
            $artifact->forceFill([
                'similarity_score' => null,
                'similarity_matches' => [],
                'similarity_flagged' => true,
                'similarity_error' => 'The similarity check could not run: '.mb_substr($e->getMessage(), 0, 200),
                'similarity_checked_at' => now(),
            ])->save();

            return;
        }

        $artifact->forceFill([
            'similarity_score' => $result->score(),
            'similarity_matches' => $result->matches,
            'similarity_flagged' => $result->score() >= (float) config('idr.similarity.flag_overlap', 0.3),
            'similarity_error' => null,
            'similarity_checked_at' => now(),
        ])->save();
    }

    /**
     * Called by the ingestion job once a file is embedded: publishes a declared
     * student submission that nobody has reviewed yet, if the AI check accepts
     * it. Anything the check is unsure about (or can't run on) stays pending,
     * with the reason recorded for the admin. Returns true if it published.
     */
    public function autoApproveIfEligible(Artifact $artifact, ?SubmissionScreener $screener = null): bool
    {
        if (! config('idr.review.auto_approve_submissions')
            || ! $artifact->hasDeclaration()
            || $artifact->status !== ReviewStatus::Pending
            || $artifact->reviewed_at !== null
            || $artifact->similarity_flagged) {
            return false; // a flagged similarity also waits for an admin (no Gemini call needed)
        }

        if (config('idr.screening.enabled') && $screener !== null) {
            try {
                $result = $screener->screen($artifact);
            } catch (Throwable $e) {
                Log::warning('AI submission check failed; left for an admin.', ['artifactId' => (string) $artifact->id, 'error' => $e->getMessage()]);
                $result = new ScreeningResult('error', 'The AI check could not run: '.mb_substr($e->getMessage(), 0, 200));
            }

            $artifact->forceFill([
                'ai_screening_verdict' => $result->verdict,
                'ai_screening_reason' => $result->reason,
                'ai_screened_at' => now(),
            ])->save();

            if (! $result->accepted()) {
                return false;
            }
        }

        $artifact->forceFill([
            'status' => ReviewStatus::Approved,
            'reviewed_by' => null,
            'reviewed_at' => now(),
            'auto_approved' => true,
        ])->save();

        return true;
    }

    /** The Dean's office has the printed certificate and the hard copy. */
    public function markHardCopyReceived(Artifact $artifact, User $admin): void
    {
        $artifact->forceFill([
            'hard_copy_received_at' => now(),
            'hard_copy_received_by' => (string) $admin->id,
        ])->save();
    }

    /** Undo a hard copy confirmation made by mistake. */
    public function clearHardCopy(Artifact $artifact): void
    {
        $artifact->forceFill([
            'hard_copy_received_at' => null,
            'hard_copy_received_by' => null,
        ])->save();
    }

    /**
     * Correct title / course code / academic year / author in MongoDB, then
     * patch the Qdrant payload. The document is NOT re-embedded.
     *
     * @param  array<string, mixed>  $changes  MongoDB field => value
     * @return 'synced'|'queued'|'skipped'|'unchanged'
     */
    public function updateMetadata(Artifact $artifact, array $changes): string
    {
        $artifact->fill($changes);

        if (! $artifact->isDirty()) {
            return 'unchanged';
        }

        $artifact->save();

        return $this->vectorSync->syncMetadata($artifact);
    }

    /**
     * Permanent deletion, in three steps:
     *   1. remove the MongoDB record  (from now on no endpoint can return it,
     *      because every search hit is re-checked against MongoDB)
     *   2. delete the physical file from storage
     *   3. delete its vectors from Qdrant (Member 2), retried from the queue
     *      if Qdrant is unavailable right now
     *
     * @return array{database: 'deleted', file: 'deleted'|'missing'|'failed', vectors: 'deleted'|'queued'}
     */
    public function delete(Artifact $artifact): array
    {
        $id = (string) $artifact->id;

        $artifact->delete();
        $file = $artifact->deleteStoredFile();
        $vectors = $this->vectorSync->deleteArtifact($id);

        Log::info('Artifact deleted.', ['artifactId' => $id, 'file' => $file, 'vectors' => $vectors]);

        return ['database' => 'deleted', 'file' => $file, 'vectors' => $vectors];
    }

    /** Run AI ingestion again (after a FAILED attempt, or a pipeline change). */
    public function reprocess(Artifact $artifact): bool
    {
        $artifact->forceFill([
            'processing_status' => ProcessingStatus::Pending,
            'processing_error' => null,
            'chunks_processed' => 0,
            'embedded_at' => null,
        ])->save();

        return $this->queueIngestion($artifact);
    }

    /** Keep the client's file name for display, minus anything path-like. */
    private function cleanFilename(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr(trim($name), 0, 255) ?: 'document.pdf';
    }
}
