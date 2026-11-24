<?php

namespace App\Jobs;

use App\Contracts\VectorIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry path for VectorSync::deleteArtifact(): removes a deleted file's
 * points from Qdrant once it is reachable again.
 */
class DeleteArtifactVectors implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public string $artifactId)
    {
        $this->onQueue(config('idr.queue.vector_sync'));
    }

    /** 10s, 1 min, 5 min, then every 15 min. */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(VectorIndex $index): void
    {
        $index->deleteArtifact($this->artifactId);
    }

    public function failed(?Throwable $exception): void
    {
        // The MongoDB record is already gone, so these orphaned points can
        // never appear in results (every hit is joined against MongoDB);
        // they only waste space. Log loudly so someone can clean them up.
        Log::critical('Could not delete Qdrant points for a deleted file.', [
            'artifactId' => $this->artifactId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
