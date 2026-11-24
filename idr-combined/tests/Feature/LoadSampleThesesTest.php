<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** php artisan idr:load-samples [--fresh] */
class LoadSampleThesesTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'idr-samples-'.uniqid();
        mkdir($this->folder);

        $manifest = [];
        foreach (['Smart Irrigation Controller' => 'project', 'Mobile Money Adoption' => 'research'] as $title => $type) {
            $file = str($title)->slug().'.pdf';
            file_put_contents($this->folder.DIRECTORY_SEPARATOR.$file, "%PDF-1.4\n% {$title}\n%%EOF\n");
            $manifest[] = ['file' => $file, 'title' => $title, 'author' => 'Sample Author', 'courseCode' => 'CSC 499',
                'academicYear' => '2024/2025', 'documentType' => $type];
        }
        file_put_contents($this->folder.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder.DIRECTORY_SEPARATOR.'*'));
        rmdir($this->folder);

        parent::tearDown();
    }

    private function existingDocument(): Artifact
    {
        $artifact = Artifact::factory()->create();
        $this->storeFileFor($artifact);

        return $artifact;
    }

    public function test_it_loads_the_samples_as_approved_and_queues_them(): void
    {
        User::factory()->admin()->create();

        $this->artisan('idr:load-samples', ['--path' => $this->folder])->assertSuccessful();

        $this->assertSame(2, Artifact::count());
        foreach (Artifact::all() as $artifact) {
            $this->assertSame(ReviewStatus::Approved, $artifact->status);
        }
        $this->assertSame(DocumentType::Research, Artifact::where('title', 'Mobile Money Adoption')->first()->document_type);
        Queue::assertPushed(ProcessArtifactIngestion::class, 2);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        User::factory()->admin()->create();

        $this->artisan('idr:load-samples', ['--path' => $this->folder])->assertSuccessful();
        $this->artisan('idr:load-samples', ['--path' => $this->folder])->assertSuccessful();

        $this->assertSame(2, Artifact::count());
    }

    public function test_fresh_deletes_existing_documents_after_confirmation(): void
    {
        User::factory()->admin()->create();
        $old = $this->existingDocument();

        $this->artisan('idr:load-samples', ['--path' => $this->folder, '--fresh' => true])
            ->expectsConfirmation('Permanently delete ALL 1 existing document(s), with their PDF files and search vectors? This cannot be undone. Accounts and chats are kept.', 'yes')
            ->assertSuccessful();

        $this->assertNull(Artifact::find($old->id));
        Storage::disk($old->storage_disk)->assertMissing($old->file_path);
        $this->assertSame(2, Artifact::count());
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        User::factory()->admin()->create();
        $old = $this->existingDocument();

        $this->artisan('idr:load-samples', ['--path' => $this->folder, '--fresh' => true])
            ->expectsConfirmation('Permanently delete ALL 1 existing document(s), with their PDF files and search vectors? This cannot be undone. Accounts and chats are kept.', 'no')
            ->assertFailed();

        $this->assertNotNull(Artifact::find($old->id));
        $this->assertSame(1, Artifact::count());
    }

    public function test_it_needs_an_admin_account(): void
    {
        $this->artisan('idr:load-samples', ['--path' => $this->folder])->assertFailed();

        $this->assertSame(0, Artifact::count());
    }
}
