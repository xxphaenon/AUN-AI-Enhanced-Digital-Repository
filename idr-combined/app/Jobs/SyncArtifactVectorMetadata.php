<?php

namespace App\Jobs;

use App\Contracts\VectorIndex;
use App\Enums\ProcessingStatus;
use App\Models\Artifact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retry path for VectorSync::syncMetadata(). Reads the artifact when it runs,
 * so it always sends the LATEST metadata, even if several edits happened
 * while Qdrant was down.
 */
class SyncArtifactVectorMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $artifactId)
    {
        $this->onQueue(config('idr.queue.vector_sync'));
    }

    /** 10s, 1 min, 5 min, 15 min between attempts. */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(VectorIndex $index): void
    {
        $artifact = Artifact::find($this->artifactId);

        // Deleted since (its vectors are handled by DeleteArtifactVectors) or
        // not embedded yet (ingestion sends metadata when it finishes).
        if ($artifact === null || $artifact->processing_status !== ProcessingStatus::Embedded) {
            return;
        }

        $index->updateArtifactMetadata($this->artifactId, $artifact->vectorMetadata());
    }
}
