<?php

namespace App\Jobs;

use App\Contracts\DocumentIngestor;
use App\Contracts\SimilarityChecker;
use App\Contracts\SubmissionScreener;
use App\Enums\ProcessingStatus;
use App\Models\Artifact;
use App\Services\DocumentService;
use App\Services\VectorSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued by POST /api/documents/upload. Runs on the "ingestion" queue
 * (a dedicated Horizon supervisor with a long timeout).
 *
 * Member 3 owns this wrapper: status transitions, retries, failure recording.
 * Member 2 owns the actual work behind App\Contracts\DocumentIngestor.
 *
 *   PENDING --(ingest ok)--> EMBEDDED
 *   PENDING --(all retries fail)--> FAILED
 *
 * A declared student submission still waiting for review is published here,
 * once embedding succeeded and the AI check accepted it
 * (DocumentService::autoApproveIfEligible); otherwise it waits for an admin.
 */
class ProcessArtifactIngestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Attempts before the artifact is marked FAILED. */
    public int $tries = 3;

    /** Seconds; must stay below queue.connections.redis.retry_after (660). */
    public int $timeout = 600;

    /** Hold the uniqueness lock for at most this long. */
    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public string $artifactId)
    {
        $this->onQueue(config('idr.queue.ingestion'));
    }

    /** One ingestion per artifact at a time. */
    public function uniqueId(): string
    {
        return $this->artifactId;
    }

    /** Wait 30s, then 2 min, between attempts (Gemini rate limits, transient errors). */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DocumentIngestor $ingestor, VectorSync $vectorSync, DocumentService $documents, SubmissionScreener $screener, SimilarityChecker $similarity): void
    {
        $artifact = Artifact::find($this->artifactId);

        if ($artifact === null) {
            Log::warning('Ingestion skipped: artifact no longer exists.', ['artifactId' => $this->artifactId]);

            return;
        }

        if ($artifact->processing_status === ProcessingStatus::Embedded) {
            return; // already done (e.g. duplicate dispatch) — stay idempotent
        }

        $artifact->increment('processing_attempts');

        $chunks = $ingestor->ingest($artifact);

        // Re-read: an admin may have deleted, approved or edited the file
        // while it was being embedded.
        $artifact = Artifact::find($this->artifactId);

        if ($artifact === null) {
            Log::info('Artifact deleted during ingestion; removing its fresh vectors.', ['artifactId' => $this->artifactId]);
            $vectorSync->deleteArtifact($this->artifactId);

            return;
        }

        $artifact->markEmbedded($chunks);

        $documents->recordSimilarity($artifact, $similarity);

        if ($documents->autoApproveIfEligible($artifact, $screener)) {
            Log::info('Declared submission published automatically.', ['artifactId' => $this->artifactId]);
        }

        // Make sure Qdrant holds the CURRENT approval status and details, not
        // the ones read when ingestion started.
        $vectorSync->syncMetadata($artifact);

        Log::info('Artifact embedded.', ['artifactId' => $this->artifactId, 'chunks' => $chunks]);
    }

    /** Called by the queue after the final attempt fails (or on timeout). */
    public function failed(?Throwable $exception): void
    {
        Log::error('Artifact ingestion failed.', [
            'artifactId' => $this->artifactId,
            'error' => $exception?->getMessage(),
        ]);

        Artifact::find($this->artifactId)?->markFailed(
            $exception?->getMessage() ?: 'Ingestion failed for an unknown reason.'
        );
    }
}
