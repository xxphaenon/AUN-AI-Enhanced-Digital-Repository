<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may see, preview and download what.
 *
 *                     approved   own pending   others' pending/rejected
 *   guest  show/preview   yes        —              404
 *   guest  download       401        —              401
 *   student download      yes        yes            404
 *   admin  everything     yes        yes            yes
 */
class DocumentAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function withFile(Artifact $artifact): Artifact
    {
        $this->storeFileFor($artifact, "%PDF-1.4\nbody of {$artifact->title}\n%%EOF\n");

        return $artifact;
    }

    // ── public catalogue ────────────────────────────────────────────────

    public function test_public_list_shows_only_approved_documents(): void
    {
        $approved = Artifact::factory()->create(['title' => 'Approved Paper', 'course_code' => 'CSC 401']);
        Artifact::factory()->pending()->create(['title' => 'Pending Paper']);
        Artifact::factory()->rejected()->create(['title' => 'Rejected Paper']);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.artifactId', (string) $approved->id)
            ->assertJsonPath('data.0.courseCode', 'CSC 401')
            ->assertJsonMissingPath('data.0.reviewStatus')
            ->assertJsonMissingPath('data.0.filePath');
    }

    public function test_public_list_filters(): void
    {
        Artifact::factory()->create(['title' => 'Compilers', 'course_code' => 'CSC 401', 'academic_year' => '2023/2024']);
        Artifact::factory()->create(['title' => 'Networks', 'course_code' => 'CSC 305', 'academic_year' => '2024/2025']);

        $this->getJson('/api/documents?courseCode=csc401')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Compilers');
        $this->getJson('/api/documents?academicYear=2024/2025')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Networks');
        $this->getJson('/api/documents?q=netw')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Networks');
    }

    public function test_show_hides_unapproved_documents_from_others(): void
    {
        $owner = User::factory()->create();
        $pending = Artifact::factory()->pending()->uploadedBy($owner)->create();

        $this->getJson("/api/documents/{$pending->id}")->assertNotFound();

        $this->actingAsStudent();
        $this->getJson("/api/documents/{$pending->id}")->assertNotFound();

        $this->actingAsStudent($owner);
        $this->getJson("/api/documents/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.reviewStatus', 'pending');
    }

    // ── preview ─────────────────────────────────────────────────────────

    public function test_anyone_can_preview_an_approved_document_inline(): void
    {
        $doc = $this->withFile(Artifact::factory()->create(['title' => 'Compilers', 'course_code' => 'CSC 401']));

        $response = $this->get("/api/documents/{$doc->id}/preview");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CSC 401 Compilers.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('body of Compilers', $response->streamedContent());
    }

    public function test_guests_cannot_preview_pending_files_but_the_uploader_can(): void
    {
        $owner = User::factory()->create();
        $doc = $this->withFile(Artifact::factory()->pending()->uploadedBy($owner)->create());

        $this->getJson("/api/documents/{$doc->id}/preview")->assertNotFound();

        $this->actingAsStudent($owner);
        $this->get("/api/documents/{$doc->id}/preview")->assertOk();
    }

    public function test_missing_file_gives_a_clear_404(): void
    {
        $doc = Artifact::factory()->create(); // no file stored

        $this->getJson("/api/documents/{$doc->id}/preview")
            ->assertNotFound()
            ->assertJsonPath('message', 'The file for this document is missing from storage.');
    }

    public function test_malformed_ids_are_404(): void
    {
        $this->getJson('/api/documents/not-an-id/preview')->assertNotFound()->assertJsonPath('status', 'error');
        $this->getJson('/api/documents/66f0000000000000000000ff')->assertNotFound();
    }

    // ── download ────────────────────────────────────────────────────────

    public function test_download_requires_login(): void
    {
        $doc = $this->withFile(Artifact::factory()->create());

        $this->getJson("/api/documents/{$doc->id}/download")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated. Please log in.');

        // Even a browser-style request (no JSON Accept header) gets 401, not a redirect.
        $this->get("/api/documents/{$doc->id}/download")->assertUnauthorized();
    }

    public function test_logged_in_user_downloads_approved_file_as_attachment(): void
    {
        $doc = $this->withFile(Artifact::factory()->create(['title' => 'Compilers', 'course_code' => 'CSC 401']));
        $this->actingAsStudent();

        $response = $this->get("/api/documents/{$doc->id}/download");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('body of Compilers', $response->streamedContent());
    }

    public function test_download_works_with_a_real_bearer_token(): void
    {
        $doc = $this->withFile(Artifact::factory()->create());
        $token = User::factory()->create()->createToken('web', ['*'], now()->addHour())->plainTextToken;

        $this->withToken($token)->get("/api/documents/{$doc->id}/download")->assertOk();
    }

    public function test_students_cannot_download_other_peoples_unapproved_files(): void
    {
        $doc = $this->withFile(Artifact::factory()->pending()->create());
        $this->actingAsStudent();

        $this->getJson("/api/documents/{$doc->id}/download")->assertNotFound();
    }

    public function test_rejected_files_stay_private(): void
    {
        $owner = User::factory()->create();
        $doc = $this->withFile(Artifact::factory()->rejected()->uploadedBy($owner)->create());

        $this->actingAsStudent();
        $this->getJson("/api/documents/{$doc->id}/download")->assertNotFound();

        $this->actingAsStudent($owner);
        $this->get("/api/documents/{$doc->id}/download")->assertOk();
    }

    // ── my submissions ─────────────────────────────────────────────────

    public function test_my_submissions_lists_only_my_files_in_every_state(): void
    {
        $me = User::factory()->create();
        Artifact::factory()->pending()->uploadedBy($me)->create(['title' => 'Mine pending']);
        Artifact::factory()->rejected('Wrong year')->uploadedBy($me)->create(['title' => 'Mine rejected']);
        Artifact::factory()->create(['title' => 'Someone else']);

        $this->actingAsStudent($me);

        $response = $this->getJson('/api/documents/mine')->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing(['pending', 'rejected'], array_column($response->json('data'), 'reviewStatus'));
        $this->assertContains('Wrong year', array_column($response->json('data'), 'rejectionReason'));
    }
}
