<?php

namespace App\Console\Commands;

use App\Services\Ai\GeminiClient;
use App\Services\Ai\QdrantClient;
use Illuminate\Console\Command;

/**
 * Create the Qdrant collection and the payload indexes the filters need.
 *
 *   php artisan idr:qdrant-setup           # create if missing, (re)create indexes
 *   php artisan idr:qdrant-setup --fresh   # DELETE the collection and start empty
 */
class QdrantSetup extends Command
{
    protected $signature = 'idr:qdrant-setup
                            {--fresh : Delete and recreate the collection (all vectors are lost; run idr:reindex afterwards)}';

    protected $description = 'Create the Qdrant collection and payload indexes for the AI pipeline';

    public function handle(QdrantClient $qdrant, GeminiClient $gemini): int
    {
        $name = $qdrant->collection();
        $dims = $gemini->dimensions();

        if ($this->option('fresh') && $qdrant->collectionExists()) {
            if (! $this->confirm("Delete collection [{$name}] and every vector in it?", false)) {
                return self::FAILURE;
            }
            $qdrant->deleteCollection();
            $this->warn("Deleted [{$name}].");
        }

        if ($qdrant->collectionExists()) {
            $this->info("Collection [{$name}] already exists.");
        } else {
            $qdrant->createCollection($dims);
            $this->info("Created collection [{$name}] ({$dims} dimensions, cosine).");
        }

        $qdrant->ensurePayloadIndexes();
        $this->info('Payload indexes ready: '.implode(', ', array_keys(QdrantClient::PAYLOAD_INDEXES)).'.');

        return self::SUCCESS;
    }
}
