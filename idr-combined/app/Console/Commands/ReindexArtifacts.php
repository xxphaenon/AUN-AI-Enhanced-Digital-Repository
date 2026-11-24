<?php

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\Artifact;
use App\Services\DocumentService;
use Illuminate\Console\Command;

/**
 * Queue documents for (re-)embedding with the live AI pipeline. Needed once after
 * switching IDR_AI_DRIVER from fake to live: documents the fakes marked EMBEDDED
 * have no real vectors yet.
 *
 *   php artisan idr:reindex            # every document
 *   php artisan idr:reindex --failed   # only FAILED ones
 *
 * Keep `php artisan queue:work redis --queue=ingestion,default --timeout=600` running.
 */
class ReindexArtifacts extends Command
{
    protected $signature = 'idr:reindex {--failed : Only documents whose processing FAILED}';

    protected $description = 'Queue documents for embedding with Gemini + Qdrant';

    public function handle(DocumentService $documents): int
    {
        $query = Artifact::query()->when(
            $this->option('failed'),
            fn ($q) => $q->where('processing_status', ProcessingStatus::Failed->value),
        );

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to reindex.');

            return self::SUCCESS;
        }

        $queued = 0;
        $failed = 0;

        $query->orderBy('created_at')->each(function (Artifact $artifact) use ($documents, &$queued, &$failed) {
            $documents->reprocess($artifact) ? $queued++ : $failed++;
        });

        $this->info("Queued {$queued} of {$total} document(s) for embedding.");

        if ($failed > 0) {
            $this->error("{$failed} could not be queued. Is Redis (Memurai) running?");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
