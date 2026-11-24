<?php

namespace Tests\Feature\Admin;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDocumentListTest extends TestCase
{
    private User $alice;

    private User $bola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@aun.edu.ng']);
        $this->bola = User::factory()->create(['name' => 'Bola', 'email' => 'bola@aun.edu.ng']);

        $this->make('pending', $this->alice, 'Compilers Exam', 'CSC401-2024.pdf', '2026-10-01 09:00');
        $this->make('pending', $this->bola, 'Networks Notes', 'networks.pdf', '2026-10-02 23:30'); // 00:30 next day in Lagos
        $this->make('approved', $this->alice, 'Algorithms Project', 'algo-final.pdf', '2026-09-15 12:00');
        $this->make('rejected', $this->bola, 'Blurry OS Scan', 'os.pdf', '2026-09-20 12:00');

        $this->actingAsAdmin();
    }

    private function make(string $status, User $by, string $title, string $file, string $createdUtc): Artifact
    {
        $artifact = Artifact::factory()->{$status === 'approved' ? 'embedded' : $status}()->uploadedBy($by)->create([
            'title' => $title,
            'original_filename' => $file,
        ]);
        // created_at is managed by Eloquent; set it directly for the date filters.
        $artifact->forceFill(['created_at' => Carbon::parse($createdUtc, 'UTC')])->save();

        return $artifact;
    }

    private function titles(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'title');
    }

    public function test_lists_everything_newest_first_with_uploader_and_counts(): void
    {
        $response = $this->getJson('/api/admin/documents')->assertOk();

        $this->assertSame(['Networks Notes', 'Compilers Exam', 'Blurry OS Scan', 'Algorithms Project'], array_column($response->json('data'), 'title'));
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.counts', ['pending' => 2, 'approved' => 1, 'rejected' => 1])
            ->assertJsonPath('data.0.uploader.name', 'Bola')
            ->assertJsonPath('data.0.reviewStatus', 'pending')
            ->assertJsonStructure(['data' => [['filePath', 'uploadedBy', 'fileHash', 'links' => ['adminPreview']]]]);
    }

    public function test_filter_by_status(): void
    {
        $this->assertSame(['Algorithms Project'], $this->titles('/api/admin/documents?status=approved'));
        $this->assertSame(['Blurry OS Scan'], $this->titles('/api/admin/documents?status=REJECTED'));
    }

    public function test_filter_by_uploader(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Compilers Exam', 'Algorithms Project'],
            $this->titles('/api/admin/documents?uploadedBy='.$this->alice->id),
        );
    }

    public function test_filter_by_upload_date_uses_lagos_days(): void
    {
        // 2026-10-02 23:30 UTC is 2026-10-03 00:30 in Lagos.
        $this->assertSame(['Networks Notes'], $this->titles('/api/admin/documents?uploadedFrom=2026-10-03&uploadedTo=2026-10-03'));
        $this->assertSame(['Compilers Exam'], $this->titles('/api/admin/documents?uploadedFrom=2026-10-01&uploadedTo=2026-10-02'));
        $this->assertEqualsCanonicalizing(
            ['Algorithms Project', 'Blurry OS Scan'],
            $this->titles('/api/admin/documents?uploadedTo=2026-09-30'),
        );
    }

    public function test_filter_by_exact_filename_ignores_case_but_not_partial_matches(): void
    {
        $this->assertSame(['Compilers Exam'], $this->titles('/api/admin/documents?filename=csc401-2024.PDF'));
        $this->assertSame([], $this->titles('/api/admin/documents?filename=CSC401'));
        // Regex characters in the name are taken literally.
        $this->assertSame([], $this->titles('/api/admin/documents?filename=.*'));
    }

    public function test_quick_search_matches_part_of_title_or_filename(): void
    {
        $this->assertSame(['Networks Notes'], $this->titles('/api/admin/documents?q=network'));
        $this->assertSame(['Algorithms Project'], $this->titles('/api/admin/documents?q=algo-final'));
    }

    public function test_filters_combine_and_counts_follow_them(): void
    {
        $this->getJson('/api/admin/documents?uploadedBy='.$this->bola->id.'&status=pending')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Networks Notes')
            ->assertJsonPath('meta.counts', ['pending' => 1, 'approved' => 0, 'rejected' => 1]);
    }

    public function test_pagination(): void
    {
        $this->getJson('/api/admin/documents?perPage=3&page=2')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.lastPage', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_pending_queue_is_oldest_first(): void
    {
        $this->assertSame(['Compilers Exam', 'Networks Notes'], $this->titles('/api/admin/documents/pending'));
    }

    public function test_bad_filters_are_rejected(): void
    {
        $this->getJson('/api/admin/documents?status=deleted&uploadedBy=xyz&uploadedFrom=03-10-2026&perPage=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'uploadedBy', 'uploadedFrom', 'perPage']);

        $this->getJson('/api/admin/documents?uploadedFrom=2026-10-05&uploadedTo=2026-10-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['uploadedTo']);
    }
}
