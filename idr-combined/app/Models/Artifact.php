<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use Database\Factories\ArtifactFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use RuntimeException;
use Throwable;

/**
 * Collection: artifacts, one document per submitted file (senior project
 * report, past paper, ...).
 *
 * The binary lives on the configured Storage disk (never web-accessible);
 * this record holds its metadata, where to find it, who submitted it, the
 * review decision, and the AI ingestion status that the queued job updates.
 *
 * Qdrant points carry `artifactId` = (string) $artifact->id in their payload,
 * so search hits are always joined back to this collection, which stays the
 * source of truth for what exists and who may see it.
 *
 * @property string $id
 * @property string $title
 * @property string|null $author display string of all authors, e.g. "Ada Obi, Musa Bello & Grace Eze" (search, chat and Qdrant use it)
 * @property list<array{name: string, studentId: ?string}> $authors one entry per author; setting it also sets $author
 * @property string|null $course_code
 * @property string|null $academic_year
 * @property DocumentType|null $document_type
 * @property ReviewStatus $status
 * @property string $uploaded_by
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string $storage_disk
 * @property string $file_path
 * @property string $file_hash
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property ProcessingStatus $processing_status
 * @property int $chunks_processed
 * @property string|null $processing_error
 * @property int $processing_attempts
 * @property Carbon|null $embedded_at
 * @property bool $auto_approved approved automatically after processing (declared submission)
 * @property string|null $submission_code e.g. "IDR-2026-7KQ4MX"; only on declared submissions
 * @property string|null $declared_name name the student typed when accepting the declaration
 * @property string|null $declared_university_id
 * @property string|null $declaration_text the exact statement the student accepted
 * @property Carbon|null $declared_at
 * @property Carbon|null $hard_copy_received_at when the Dean's office confirmed the hard copy
 * @property string|null $hard_copy_received_by admin who confirmed it
 * @property string|null $ai_screening_verdict accept | review | error (AI check before auto-publishing)
 * @property string|null $ai_screening_reason
 * @property Carbon|null $ai_screened_at
 * @property float|null $similarity_score highest share (0–1) of sections closely matching one earlier project
 * @property array|null $similarity_matches up to 3 closest projects, see App\Support\SimilarityResult
 * @property bool $similarity_flagged true = too similar (or check failed): waits for an admin
 * @property string|null $similarity_error
 * @property Carbon|null $similarity_checked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'artifacts';

    /**
     * Only descriptive metadata is mass-assignable. Ownership, review and
     * pipeline fields are set explicitly with forceFill() so request input
     * can never change them.
     */
    protected $fillable = [
        'title',
        'author',
        'authors',
        'course_code',
        'academic_year',
        'document_type',
    ];

    protected $attributes = [
        'status' => 'pending',
        'processing_status' => 'PENDING',
        'chunks_processed' => 0,
        'processing_error' => null,
        'processing_attempts' => 0,
        'reviewed_by' => null,
        'reviewed_at' => null,
        'rejection_reason' => null,
    ];

    protected function casts(): array
    {
        return [
            'uploaded_by' => 'string',
            'reviewed_by' => 'string',
            'status' => ReviewStatus::class,
            'document_type' => DocumentType::class,
            'processing_status' => ProcessingStatus::class,
            'chunks_processed' => 'integer',
            'processing_attempts' => 'integer',
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
            'embedded_at' => 'datetime',
            'auto_approved' => 'boolean',
            'declared_at' => 'datetime',
            'hard_copy_received_at' => 'datetime',
            'hard_copy_received_by' => 'string',
            'ai_screened_at' => 'datetime',
            'similarity_score' => 'float',
            'similarity_flagged' => 'boolean',
            'similarity_checked_at' => 'datetime',
        ];
    }

    // ── Authors ─────────────────────────────────────────────────────────

    /** Most authors one document may list (a group project's members). */
    public const MAX_AUTHORS = 10;

    /**
     * The author list. Records from before multiple authors existed only have
     * the `author` string, which is split into names when read. Setting the
     * list also rewrites `author`, the single string search, chat and Qdrant use.
     */
    protected function authors(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => is_array($value)
                ? self::cleanAuthors($value)
                : self::parseAuthors($attributes['author'] ?? null),
            set: function (mixed $value) {
                $list = self::cleanAuthors(is_array($value) ? $value : self::parseAuthors(is_string($value) ? $value : null));

                return ['authors' => $list, 'author' => self::formatAuthors($list)];
            },
        );
    }

    /**
     * Setting the plain `author` string (admin edit, older clients) splits it into the list.
     *
     * The getter must exist: laravel-mongodb treats a model method with no
     * getter as an embedded relation and would return null for `$artifact->author`.
     */
    protected function author(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value,
            set: function (mixed $value) {
                $list = self::parseAuthors(is_string($value) ? $value : null);

                return ['author' => self::formatAuthors($list), 'authors' => $list];
            },
        );
    }

    /**
     * "Ada Obi, Musa Bello and Grace Eze" → three authors. Splits on ";", "&"
     * and "and"; splits on commas only when every part is a full name, so
     * "Obi, A." stays one author.
     *
     * @return list<array{name: string, studentId: null}>
     */
    public static function parseAuthors(?string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ($text === '') {
            return [];
        }

        $names = [];
        foreach (preg_split('/\s*(?:;|&|\band\b)\s*/iu', $text) ?: [] as $part) {
            $pieces = array_values(array_filter(array_map('trim', explode(',', $part)), fn (string $p) => $p !== ''));
            $fullNames = count($pieces) > 1 && ! array_filter($pieces, fn (string $p) => count(explode(' ', $p)) < 2);

            foreach ($fullNames ? $pieces : [trim($part, " ,")] as $name) {
                if ($name !== '') {
                    $names[] = ['name' => $name, 'studentId' => null];
                }
            }
        }

        return self::cleanAuthors($names);
    }

    /**
     * Tidy an author list: trimmed names, upper-case student IDs, no blanks
     * or repeated names, at most MAX_AUTHORS.
     *
     * @return list<array{name: string, studentId: ?string}>
     */
    public static function cleanAuthors(array $authors): array
    {
        $clean = [];
        $seen = [];

        foreach ($authors as $author) {
            $author = is_string($author) ? ['name' => $author] : (array) $author;
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($author['name'] ?? '')) ?? '');
            $studentId = strtoupper(trim((string) ($author['studentId'] ?? '')));
            $key = mb_strtolower($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $clean[] = ['name' => $name, 'studentId' => $studentId !== '' ? $studentId : null];
        }

        return array_slice($clean, 0, self::MAX_AUTHORS);
    }

    /** ["Ada Obi", "Musa Bello", "Grace Eze"] → "Ada Obi, Musa Bello & Grace Eze" */
    public static function formatAuthors(array $authors): ?string
    {
        $names = array_column(self::cleanAuthors($authors), 'name');

        return match (count($names)) {
            0 => null,
            1 => $names[0],
            default => implode(', ', array_slice($names, 0, -1)).' & '.end($names),
        };
    }

    /** Names only: what the public sees (student IDs stay with the uploader, admins and the certificate). */
    public function publicAuthors(): array
    {
        return array_map(fn (array $a) => ['name' => $a['name']], $this->authors);
    }

    // ── Relations ───────────────────────────────────────────────────────

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeApproved($query)
    {
        return $query->where('status', ReviewStatus::Approved->value);
    }

    public function scopeEmbedded($query)
    {
        return $query->where('processing_status', ProcessingStatus::Embedded->value);
    }

    public function scopeOfType($query, DocumentType|string|null $type)
    {
        $value = $type instanceof DocumentType ? $type->value : $type;

        return $value === null ? $query : $query->where('document_type', $value);
    }

    /**
     * Hard copy state for the admin filter: awaiting | overdue | received.
     * Only declared submissions (those with a submission code) expect one, and
     * rejected ones no longer do.
     */
    public function scopeHardCopy($query, ?string $state)
    {
        if ($state === null) {
            return $query;
        }

        if ($state === 'received') {
            return $query->whereNotNull('hard_copy_received_at');
        }

        $query->where('submission_code', 'exists', true)
            ->whereNull('hard_copy_received_at')
            ->where('status', '!=', ReviewStatus::Rejected->value);

        $deadline = now()->subDays(config('idr.submission.hard_copy_days'));

        return $state === 'overdue'
            ? $query->where('created_at', '<', $deadline)
            : $query->where('created_at', '>=', $deadline);
    }

    /** Admin views: "recent" = published automatically lately; "flagged" = held back by the AI check. */
    public function scopeAdminView($query, ?string $view)
    {
        return match ($view) {
            'recent' => $query->where('auto_approved', true)
                ->where('status', ReviewStatus::Approved->value)
                ->where('reviewed_at', '>=', now()->subDays(config('idr.review.recent_days'))),
            'flagged' => $query->where('status', ReviewStatus::Pending->value)
                ->where(fn ($q) => $q->whereIn('ai_screening_verdict', ['review', 'error'])
                    ->orWhere('similarity_flagged', true)),
            default => $query,
        };
    }

    /** What students and guests may find through search and chat. */
    public function scopeVisibleToPublic($query)
    {
        return $query->approved()->embedded();
    }

    // ── Access rules ────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->status === ReviewStatus::Approved;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (string) $this->uploaded_by === (string) $user->id;
    }

    /**
     * Approved files are open to everyone; anything else only to its
     * uploader and to admins. Callers answer 404 (not 403) otherwise, so the
     * existence of an unapproved file is not revealed.
     */
    public function isVisibleTo(?User $user): bool
    {
        return $this->isApproved()
            || $this->isOwnedBy($user)
            || ($user !== null && $user->isAdmin());
    }

    // ── Declaration and hard copy ───────────────────────────────────────

    public function hasDeclaration(): bool
    {
        return $this->submission_code !== null && $this->declared_at !== null;
    }

    /** @return 'not_required'|'awaiting'|'overdue'|'received' */
    public function hardCopyStatus(): string
    {
        return match (true) {
            ! $this->hasDeclaration() => 'not_required',
            $this->hard_copy_received_at !== null => 'received',
            $this->status === ReviewStatus::Rejected => 'not_required',
            $this->hardCopyDueBy()?->isPast() === true => 'overdue',
            default => 'awaiting',
        };
    }

    public function hardCopyDueBy(): ?Carbon
    {
        return $this->hasDeclaration() && $this->created_at !== null
            ? Carbon::instance($this->created_at)->addDays(config('idr.submission.hard_copy_days'))
            : null;
    }

    /** A code that is easy to read aloud and type: no 0/O or 1/I/L. */
    public static function newSubmissionCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = 'IDR-'.now()->format('Y').'-'.$suffix;

            if (! static::where('submission_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique submission code.');
    }

    /** "idr 2026 7kq4mx" → "IDR-2026-7KQ4MX" */
    public static function normalizeSubmissionCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return preg_match('/^IDR(\d{4})([A-Z0-9]+)$/', $code, $m) ? "IDR-{$m[1]}-{$m[2]}" : $code;
    }

    // ── Storage ─────────────────────────────────────────────────────────

    public function fileExists(): bool
    {
        return $this->file_path !== null && Storage::disk($this->storage_disk)->exists($this->file_path);
    }

    /** Raw bytes of the stored file (local or s3). Used by the ingestion pipeline. */
    public function fileContents(): string
    {
        $contents = Storage::disk($this->storage_disk)->get($this->file_path);

        if ($contents === null) {
            throw new RuntimeException("Stored file for artifact {$this->id} is missing at {$this->storage_disk}:{$this->file_path}.");
        }

        return $contents;
    }

    /** @return 'deleted'|'missing'|'failed' */
    public function deleteStoredFile(): string
    {
        try {
            $disk = Storage::disk($this->storage_disk);

            if (! $disk->exists($this->file_path)) {
                return 'missing';
            }

            return $disk->delete($this->file_path) ? 'deleted' : 'failed';
        } catch (Throwable $e) {
            Log::error('Could not delete stored file.', ['artifactId' => (string) $this->id, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /** A safe "Course-Title.pdf" style name for downloads. */
    public function downloadFilename(): string
    {
        $base = trim(implode(' ', array_filter([$this->course_code, $this->title])));
        $base = preg_replace('/[^\pL\pN\-_. ]+/u', '', $base) ?: 'document';
        $base = preg_replace('/\s+/', ' ', $base);

        return mb_substr($base, 0, 150).'.pdf';
    }

    // ── Pipeline state ──────────────────────────────────────────────────

    public function markEmbedded(int $chunksProcessed): void
    {
        $this->forceFill([
            'processing_status' => ProcessingStatus::Embedded,
            'chunks_processed' => $chunksProcessed,
            'processing_error' => null,
            'embedded_at' => now(),
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'processing_status' => ProcessingStatus::Failed,
            'processing_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    /**
     * The metadata Member 2 stores in every Qdrant point payload for this
     * file. `is_approved` is what student-facing vector search filters on.
     *
     * @return array{artifactId: string, title: string, author: ?string, course_code: ?string, academic_year: ?string, document_type: ?string, status: string, is_approved: bool}
     */
    public function vectorMetadata(): array
    {
        return [
            'artifactId' => (string) $this->id,
            'title' => (string) $this->title,
            'author' => $this->author,
            'course_code' => $this->course_code,
            'academic_year' => $this->academic_year,
            'document_type' => $this->document_type?->value,
            'status' => $this->status->value,
            'is_approved' => $this->isApproved(),
        ];
    }

    // ── API shapes ──────────────────────────────────────────────────────

    /** Payload for GET /api/documents/{artifactId}/status */
    public function toStatusPayload(): array
    {
        $payload = [
            'artifactId' => (string) $this->id,
            'processingStatus' => $this->processing_status->value,
            'chunksProcessed' => (int) $this->chunks_processed,
            'reviewStatus' => $this->status->value,
        ];

        // Additive to the contract: tells the UI why a FAILED file failed.
        if ($this->processing_status === ProcessingStatus::Failed) {
            $payload['error'] = $this->processing_error;
        }

        if ($this->status === ReviewStatus::Rejected) {
            $payload['rejectionReason'] = $this->rejection_reason;
        }

        return $payload;
    }

    /** What anyone may see about an approved file. */
    public function toPublicArray(): array
    {
        $id = (string) $this->id;

        return [
            'artifactId' => $id,
            'title' => $this->title,
            'author' => $this->author,
            'authors' => $this->publicAuthors(),
            'courseCode' => $this->course_code,
            'academicYear' => $this->academic_year,
            'documentType' => $this->document_type?->value,
            'documentTypeLabel' => $this->document_type?->label(),
            'originalFilename' => $this->original_filename,
            'sizeBytes' => (int) $this->size_bytes,
            'createdAt' => self::iso($this->created_at),
            'links' => [
                'preview' => route('documents.preview', $id),
                'download' => route('documents.download', $id),
            ],
        ];
    }

    /** The uploader also sees how their submission is doing. */
    public function toOwnerArray(): array
    {
        $public = $this->toPublicArray();
        $public['authors'] = $this->authors; // with student IDs

        return $public + [
            'reviewStatus' => $this->status->value,
            'rejectionReason' => $this->rejection_reason,
            'reviewedAt' => self::iso($this->reviewed_at),
            'processingStatus' => $this->processing_status->value,
            'chunksProcessed' => (int) $this->chunks_processed,
            'submissionCode' => $this->submission_code,
            'hardCopy' => [
                'status' => $this->hardCopyStatus(),
                'dueBy' => self::iso($this->hardCopyDueBy()),
                'receivedAt' => self::iso($this->hard_copy_received_at),
            ],
        ];
    }

    /** Everything, for the admin dashboard. */
    public function toAdminArray(): array
    {
        $data = $this->toOwnerArray() + [
            'uploadedBy' => $this->uploaded_by,
            'reviewedBy' => $this->reviewed_by,
            'filePath' => $this->file_path,
            'storageDisk' => $this->storage_disk,
            'mimeType' => $this->mime_type,
            'fileHash' => $this->file_hash,
            'processingError' => $this->processing_error,
            'processingAttempts' => (int) $this->processing_attempts,
            'embeddedAt' => self::iso($this->embedded_at),
            'updatedAt' => self::iso($this->updated_at),
            'autoApproved' => (bool) $this->auto_approved,
            'aiScreening' => $this->ai_screening_verdict === null ? null : [
                'verdict' => $this->ai_screening_verdict,
                'reason' => $this->ai_screening_reason,
                'checkedAt' => self::iso($this->ai_screened_at),
            ],
            'similarity' => $this->similarity_checked_at === null ? null : [
                'score' => $this->similarity_score,
                'flagged' => (bool) $this->similarity_flagged,
                'matches' => array_values((array) ($this->similarity_matches ?? [])),
                'error' => $this->similarity_error,
                'checkedAt' => self::iso($this->similarity_checked_at),
            ],
            'declaration' => $this->hasDeclaration() ? [
                'name' => $this->declared_name,
                'universityId' => $this->declared_university_id,
                'text' => $this->declaration_text,
                'acceptedAt' => self::iso($this->declared_at),
            ] : null,
        ];

        $data['hardCopy']['receivedBy'] = $this->hard_copy_received_by;

        if ($this->relationLoaded('uploader')) {
            $data['uploader'] = $this->uploader?->toSummaryArray();
        }

        $data['links']['adminPreview'] = route('admin.documents.preview', (string) $this->id);

        return $data;
    }

    /** What the printable submission certificate shows. */
    public function toCertificateArray(): array
    {
        return [
            'artifactId' => (string) $this->id,
            'submissionCode' => $this->submission_code,
            'title' => $this->title,
            'author' => $this->author,
            'authors' => $this->authors,
            'courseCode' => $this->course_code,
            'academicYear' => $this->academic_year,
            'documentType' => $this->document_type?->value,
            'documentTypeLabel' => $this->document_type?->label(),
            'originalFilename' => $this->original_filename,
            'fileHash' => $this->file_hash,
            'submittedAt' => self::iso($this->created_at),
            'declaration' => [
                'name' => $this->declared_name,
                'universityId' => $this->declared_university_id,
                'text' => $this->declaration_text,
                'acceptedAt' => self::iso($this->declared_at),
            ],
            'reviewStatus' => $this->status->value,
            'hardCopy' => [
                'status' => $this->hardCopyStatus(),
                'dueBy' => self::iso($this->hardCopyDueBy()),
                'receivedAt' => self::iso($this->hard_copy_received_at),
            ],
        ];
    }

    /** Pick the right shape for whoever is asking. */
    public function toArrayFor(?User $user): array
    {
        return match (true) {
            $user?->isAdmin() === true => $this->toAdminArray(),
            $this->isOwnedBy($user) => $this->toOwnerArray(),
            default => $this->toPublicArray(),
        };
    }

    public static function iso(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    // ── Input normalisation ─────────────────────────────────────────────

    /** "csc401", "CSC  401" → "CSC 401" */
    public static function normalizeCourseCode(string $code): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code));

        return preg_replace('/^([A-Z]+)(\d.*)$/', '$1 $2', $code);
    }

    /** "2024 / 2025" → "2024/2025" */
    public static function normalizeAcademicYear(string $year): string
    {
        return preg_replace('/\s+/', '', $year);
    }
}
