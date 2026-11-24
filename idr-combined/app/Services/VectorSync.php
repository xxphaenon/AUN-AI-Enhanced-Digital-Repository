<?php

namespace App\Services;

use App\Contracts\VectorIndex;
use App\Enums\ProcessingStatus;
use App\Jobs\DeleteArtifactVectors;
use App\Jobs\SyncArtifactVectorMetadata;
use App\Models\Artifact;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps Qdrant (Member 2) in step with MongoDB (Member 3).
 *
 * Each operation is tried immediately so the change takes effect at once.
 * If Qdrant is unreachable the admin's action still succeeds: the operation
 * is handed to a queued job that retries with back-off. MongoDB remains the
 * source of truth meanwhile (search results are always re-checked against
 * it), so a delayed sync can never expose a deleted or unapproved file.
 */
class VectorSync
{
    public function __construct(private readonly VectorIndex $index) {}

    /**
     * Push the file's current metadata (title, course code, approval...) to
     * its Qdrant points without re-embedding. "skipped" means the file is not
     * embedded yet; the ingestion job sends the latest metadata when it finishes.
     *
     * @return 'synced'|'queued'|'skipped'
     */
    public function syncMetadata(Artifact $artifact): string
    {
        if ($artifact->processing_status !== ProcessingStatus::Embedded) {
            return 'skipped';
        }

        try {
            $this->index->updateArtifactMetadata((string) $artifact->id, $artifact->vectorMetadata());

            return 'synced';
        } catch (Throwable $e) {
            Log::warning('Vector metadata sync failed; queued for retry.', ['artifactId' => (string) $artifact->id, 'error' => $e->getMessage()]);
            SyncArtifactVectorMetadata::dispatch((string) $artifact->id);

            return 'queued';
        }
    }

    /**
     * Permanently remove every vector point of a file from Qdrant.
     *
     * @return 'deleted'|'queued'
     */
    public function deleteArtifact(string $artifactId): string
    {
        try {
            $this->index->deleteArtifact($artifactId);

            return 'deleted';
        } catch (Throwable $e) {
            Log::warning('Vector deletion failed; queued for retry.', ['artifactId' => $artifactId, 'error' => $e->getMessage()]);
            DeleteArtifactVectors::dispatch($artifactId);

            return 'queued';
        }
    }
}
