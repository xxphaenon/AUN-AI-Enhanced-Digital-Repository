<?php

namespace Tests\Feature;

use App\Contracts\DocumentIngestor;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class IngestionJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function runJob(Artifact $artifact): void
    {
        app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);
    }

    public function test_job_marks_artifact_embedded_and_syncs_metadata(): void
    {
        $artifact = Artifact::factory()->pending()->create();
        $this->storeFileFor($artifact);

        $this->runJob($artifact);

        $artifact->refresh();
        $this->assertSame(ProcessingStatus::Embedded, $artifact->processing_status);
        $this->assertGreaterThan(0, $artifact->chunks_processed);
        $this->assertSame(
            [['update', (string) $artifact->id, $artifact->vectorMetadata()]],
            $this->vectorIndex()->calls,
        );
        $this->assertFalse($this->vectorIndex()->calls[0][2]['is_approved']);
    }

    public function test_approval_during_embedding_reaches_qdrant(): void
    {
        $artifact = Artifact::factory()->pending()->create();
        $this->storeFileFor($artifact);

        // The ingestor runs while an admin approves the file.
        $this->app->instance(DocumentIngestor::class, new class implements DocumentIngestor
        {
            public function ingest(Artifact $artifact): int
            {
                Artifact::find($artifact->id)->forceFill(['status' => ReviewStatus::Approved])->save();

                return 7;
            }
        });

        $this->runJob($artifact);

        $this->assertTrue($this->vectorIndex()->calls[0][2]['is_approved']);
    }

    public function test_deletion_during_embedding_removes_the_new_vectors(): void
    {
        $artifact = Artifact::factory()->create();
        $this->storeFileFor($artifact);

        $this->app->instance(DocumentIngestor::class, new class implements DocumentIngestor
        {
            public function ingest(Artifact $artifact): int
            {
                Artifact::find($artifact->id)->delete();

                return 7;
            }
        });

        $this->runJob($artifact);

        $this->assertSame([['delete', (string) $artifact->id]], $this->vectorIndex()->calls);
    }

    public function test_job_failure_marks_artifact_failed(): void
    {
        $artifact = Artifact::factory()->create();

        (new ProcessArtifactIngestion((string) $artifact->id))->failed(new RuntimeException('PDF has no extractable text'));

        $artifact->refresh();
        $this->assertSame(ProcessingStatus::Failed, $artifact->processing_status);
        $this->assertSame('PDF has no extractable text', $artifact->processing_error);
    }

    public function test_missing_file_makes_the_fake_ingestor_throw(): void
    {
        $artifact = Artifact::factory()->create(); // no file stored

        $this->expectException(RuntimeException::class);
        $this->runJob($artifact);
    }
}
