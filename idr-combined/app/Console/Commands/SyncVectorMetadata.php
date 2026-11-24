<?php

namespace App\Console\Commands;

use App\Contracts\VectorIndex;
use App\Enums\ProcessingStatus;
use App\Models\Artifact;
use App\Services\Ai\QdrantClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-push every embedded document's metadata (status, is_approved, title, course...)
 * from MongoDB to Qdrant, without re-embedding. A safety net if Qdrant was down
 * during approvals or edits.
 *
 *   php artisan idr:sync-vectors           # sync metadata
 *   php artisan idr:sync-vectors --prune   # also delete vectors of documents that no longer exist
 */
class SyncVectorMetadata extends Command
{
    protected $signature = 'idr:sync-vectors {--prune : Delete vectors whose document no longer exists in MongoDB}';

    protected $description = 'Copy approval status and metadata from MongoDB to the Qdrant payloads';

    public function handle(VectorIndex $index, QdrantClient $qdrant): int
    {
        $synced = 0;
        $failed = 0;

        Artifact::query()
            ->where('processing_status', ProcessingStatus::Embedded->value)
            ->each(function (Artifact $artifact) use ($index, &$synced, &$failed) {
                try {
                    $index->updateArtifactMetadata((string) $artifact->id, $artifact->vectorMetadata());
                    $synced++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("  {$artifact->id}: {$e->getMessage()}");
                }
            });

        $this->info("Synced {$synced} document(s)".($failed ? ", {$failed} failed" : '').'.');

        if ($this->option('prune')) {
            $known = Artifact::query()->get(['_id'])->mapWithKeys(fn (Artifact $a) => [(string) $a->id => true]);

            // Guard: if MongoDB returned nothing while Qdrant has data, stop rather than wipe the index.
            if ($known->isEmpty() && Artifact::query()->exists()) {
                $this->error('Could not read document ids from MongoDB; not pruning.');

                return self::FAILURE;
            }

            $orphans = array_values(array_filter($qdrant->artifactIds(), fn ($id) => ! $known->has($id)));

            foreach ($orphans as $id) {
                $index->deleteArtifact($id);
                $this->line("  pruned vectors of deleted document {$id}");
            }

            $this->info('Pruned '.count($orphans).' orphaned document(s).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
