<?php

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use App\Exceptions\DuplicateDocumentException;
use App\Models\Artifact;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Load the sample senior projects in database/samples (20 fictional theses,
 * listed in manifest.json) as approved documents and queue them for embedding.
 *
 *   php artisan idr:load-samples           # add the samples
 *   php artisan idr:load-samples --fresh   # delete EVERY document first (asks to confirm)
 *
 * --fresh permanently deletes all documents: their records, stored PDFs and
 * search vectors. User accounts and chat history are kept.
 *
 * Keep `php artisan queue:work redis --queue=ingestion,default --timeout=600`
 * running so the samples are embedded and become searchable.
 */
class LoadSampleTheses extends Command
{
    protected $signature = 'idr:load-samples
        {--fresh : Permanently delete every existing document first}
        {--force : Skip the confirmation question (for scripts)}
        {--path= : Folder holding manifest.json and the PDFs (default: database/samples)}';

    protected $description = 'Load the sample theses into the repository';

    public function handle(DocumentService $documents): int
    {
        $folder = rtrim($this->option('path') ?: database_path('samples'), '/\\');
        $manifestPath = $folder.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("No manifest.json in {$folder}.");

            return self::FAILURE;
        }

        $samples = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($samples) || $samples === []) {
            $this->error('manifest.json is empty or not valid JSON.');

            return self::FAILURE;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('created_at')->first();

        if ($admin === null) {
            $this->error('No admin account found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->deleteEverything($documents)) {
            return self::FAILURE;
        }

        $loaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($samples as $sample) {
            $file = $folder.DIRECTORY_SEPARATOR.$sample['file'];

            if (! is_file($file)) {
                $this->warn("Missing file: {$sample['file']}");
                $failed++;

                continue;
            }

            try {
                $artifact = $documents->submit($admin, new UploadedFile($file, $sample['file'], 'application/pdf', null, true), [
                    'title' => $sample['title'],
                    'author' => $sample['author'],
                    'course_code' => $sample['courseCode'],
                    'academic_year' => $sample['academicYear'],
                    'document_type' => DocumentType::from($sample['documentType']),
                ]);
            } catch (DuplicateDocumentException) {
                $this->line("  already loaded: {$sample['title']}");
                $skipped++;

                continue;
            } catch (Throwable $e) {
                $this->warn("  could not load {$sample['file']}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            // Samples are published straight away, whatever the admin-upload setting.
            if ($artifact->status !== ReviewStatus::Approved) {
                $artifact->forceFill([
                    'status' => ReviewStatus::Approved,
                    'reviewed_by' => (string) $admin->id,
                    'reviewed_at' => now(),
                ])->save();
            }

            if (! $documents->queueIngestion($artifact)) {
                $this->warn("  saved but not queued (is Redis/Memurai running?): {$sample['title']}");
            }

            $this->line("  loaded: {$sample['title']}");
            $loaded++;
        }

        $this->info("Loaded {$loaded} sample(s), {$skipped} already present, {$failed} failed.");

        if ($loaded > 0) {
            $this->line('They become searchable once the queue worker has embedded them (about 10-30 seconds each).');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function deleteEverything(DocumentService $documents): bool
    {
        $total = Artifact::count();

        if ($total === 0) {
            $this->info('No existing documents to delete.');

            return true;
        }

        $question = "Permanently delete ALL {$total} existing document(s), with their PDF files and search vectors? "
            .'This cannot be undone. Accounts and chats are kept.';

        if (! $this->option('force') && ! $this->confirm($question)) {
            $this->warn('Nothing was deleted or loaded.');

            return false;
        }

        $deleted = 0;

        foreach (Artifact::all() as $artifact) {
            try {
                $documents->delete($artifact);
                $deleted++;
            } catch (Throwable $e) {
                $this->warn("  could not delete {$artifact->id}: {$e->getMessage()}");
            }
        }

        $this->info("Deleted {$deleted} of {$total} document(s).");

        return $deleted === $total;
    }
}
