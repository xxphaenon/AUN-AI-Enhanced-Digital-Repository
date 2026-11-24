<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Students sign a declaration on upload, get a submission code and a printable
 * certificate, are published automatically once processing succeeds, and the
 * Dean's office confirms the hard copy by looking up the code.
 */
class SubmissionDeclarationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function upload(User $student, array $overrides = [])
    {
        return $this->postJson('/api/documents/upload', array_merge([
            'file' => UploadedFile::fake()->createWithContent('smart-farm.pdf', "%PDF-1.4\nsmart farm\n%%EOF\n"),
            'title' => 'Smart Farm Irrigation',
            'courseCode' => 'CSC 499',
            'academicYear' => '2025/2026',
            'documentType' => 'project',
            'declarationAccepted' => '1',
            'declarationName' => $student->name,
        ], $overrides));
    }

    private function runJob(Artifact $artifact): void
    {
        app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);
    }

    public function test_students_must_tick_the_declaration_and_type_their_name(): void
    {
        Queue::fake();
        $student = $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));

        $this->upload($student, ['declarationAccepted' => null, 'declarationName' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['declarationAccepted', 'declarationName']);

        $this->upload($student, ['declarationName' => 'Someone Else'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['declarationName']);

        $this->assertSame(0, Artifact::count());

        // Letter case and extra spaces don't matter.
        $this->upload($student, ['declarationName' => '  ada   OBI '])->assertStatus(202);
    }

    public function test_declared_upload_stores_the_signature_and_a_submission_code(): void
    {
        Queue::fake();
        $student = $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi', 'university_id' => 'A00012345']));

        $response = $this->upload($student)
            ->assertStatus(202)
            ->assertJsonPath('data.reviewStatus', 'pending');

        $artifact = Artifact::find($response->json('data.artifactId'));

        $this->assertMatchesRegularExpression('/^IDR-\d{4}-[2-9A-HJKMNP-Z]{6}$/', $artifact->submission_code);
        $response->assertJsonPath('data.submissionCode', $artifact->submission_code);
        $this->assertStringContainsString('certificate', $response->json('message'));
        $this->assertSame('Ada Obi', $artifact->declared_name);
        $this->assertSame('A00012345', $artifact->declared_university_id);
        $this->assertStringStartsWith('I, Ada Obi, hereby confirm', $artifact->declaration_text);
        $this->assertNotNull($artifact->declared_at);
        $this->assertSame('awaiting', $artifact->hardCopyStatus());
    }

    public function test_admins_may_upload_without_a_declaration(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $id = $this->postJson('/api/documents/upload', [
            'file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nadmin\n%%EOF\n"),
            'title' => 'Archive Upload', 'courseCode' => 'CSC 499', 'academicYear' => '2025/2026',
        ])->assertStatus(202)->assertJsonPath('data.submissionCode', null)->json('data.artifactId');

        $this->assertSame('not_required', Artifact::find($id)->hardCopyStatus());
    }

    public function test_declared_submission_is_published_once_processing_succeeds(): void
    {
        $student = User::factory()->create();
        $artifact = Artifact::factory()->pending()->declared($student)->uploadedBy($student)->create();
        $this->storeFileFor($artifact);

        $this->runJob($artifact);

        $artifact->refresh();
        $this->assertSame(ReviewStatus::Approved, $artifact->status);
        $this->assertTrue($artifact->auto_approved);
        $this->assertNull($artifact->reviewed_by);
        $this->assertNotNull($artifact->reviewed_at);
        // Qdrant learns the new status in the same run, so it is searchable at once.
        $this->assertTrue(end($this->vectorIndex()->calls)[2]['is_approved']);
    }

    public function test_auto_publishing_can_be_switched_off(): void
    {
        config(['idr.review.auto_approve_submissions' => false]);
        $artifact = Artifact::factory()->pending()->declared()->create();
        $this->storeFileFor($artifact);

        $this->runJob($artifact);

        $this->assertSame(ReviewStatus::Pending, $artifact->fresh()->status);
    }

    public function test_only_unreviewed_declared_submissions_are_auto_published(): void
    {
        $undeclared = Artifact::factory()->pending()->create();
        $rejected = Artifact::factory()->rejected()->declared()->create();
        foreach ([$undeclared, $rejected] as $artifact) {
            $this->storeFileFor($artifact);
            $this->runJob($artifact);
        }

        $this->assertSame(ReviewStatus::Pending, $undeclared->fresh()->status);
        $this->assertSame(ReviewStatus::Rejected, $rejected->fresh()->status);
    }

    public function test_failed_processing_leaves_the_submission_pending(): void
    {
        $artifact = Artifact::factory()->pending()->declared()->create();

        (new ProcessArtifactIngestion((string) $artifact->id))->failed(new \RuntimeException('PDF has no extractable text'));

        $this->assertSame(ReviewStatus::Pending, $artifact->fresh()->status);
    }

    public function test_certificate_is_for_the_uploader_and_admins_only(): void
    {
        $student = User::factory()->create(['name' => 'Ada Obi']);
        $artifact = Artifact::factory()->pending()->declared($student)->uploadedBy($student)->create(['title' => 'Smart Farm']);

        $this->actingAsStudent($student);
        $this->getJson("/api/documents/{$artifact->id}/certificate")
            ->assertOk()
            ->assertJsonPath('data.submissionCode', $artifact->submission_code)
            ->assertJsonPath('data.title', 'Smart Farm')
            ->assertJsonPath('data.declaration.name', 'Ada Obi')
            ->assertJsonPath('data.hardCopy.status', 'awaiting');

        $this->actingAsStudent();
        $this->getJson("/api/documents/{$artifact->id}/certificate")->assertNotFound();

        $this->actingAsAdmin();
        $this->getJson("/api/documents/{$artifact->id}/certificate")->assertOk();

        $legacy = Artifact::factory()->create();
        $this->getJson("/api/documents/{$legacy->id}/certificate")->assertNotFound();
    }

    public function test_dean_office_finds_a_submission_by_code_and_confirms_the_hard_copy(): void
    {
        $artifact = Artifact::factory()->declared()->create(['submission_code' => 'IDR-2026-7KQ4MX']);
        $admin = $this->actingAsAdmin();

        $this->getJson('/api/admin/documents/by-code/idr2026-7kq4mx')
            ->assertOk()
            ->assertJsonPath('data.artifactId', (string) $artifact->id)
            ->assertJsonPath('data.hardCopy.status', 'awaiting');
        $this->getJson('/api/admin/documents/by-code/IDR-2026-ZZZZZZ')->assertNotFound();

        $this->postJson("/api/admin/documents/{$artifact->id}/hard-copy")
            ->assertOk()
            ->assertJsonPath('data.hardCopy.status', 'received')
            ->assertJsonPath('data.hardCopy.receivedBy', (string) $admin->id);

        $this->deleteJson("/api/admin/documents/{$artifact->id}/hard-copy")
            ->assertOk()
            ->assertJsonPath('data.hardCopy.status', 'awaiting');

        $legacy = Artifact::factory()->create();
        $this->postJson("/api/admin/documents/{$legacy->id}/hard-copy")->assertStatus(409);
    }

    public function test_students_cannot_use_the_dean_office_tools(): void
    {
        $artifact = Artifact::factory()->declared()->create();
        $this->actingAsStudent();

        $this->getJson("/api/admin/documents/by-code/{$artifact->submission_code}")->assertForbidden();
        $this->postJson("/api/admin/documents/{$artifact->id}/hard-copy")->assertForbidden();
    }

    public function test_admin_list_filters_by_hard_copy_state(): void
    {
        $days = config('idr.submission.hard_copy_days');
        $awaiting = Artifact::factory()->declared()->create();
        $overdue = Artifact::factory()->declared()->create();
        $overdue->forceFill(['created_at' => now()->subDays($days + 1)])->save();
        $received = Artifact::factory()->declared()->create(['hard_copy_received_at' => now()]);
        Artifact::factory()->create(); // no declaration: no hard copy expected
        Artifact::factory()->rejected()->declared()->create(); // rejected: no longer expected
        $this->actingAsAdmin();

        $ids = fn (string $state) => array_column($this->getJson("/api/admin/documents?hardCopy={$state}")->assertOk()->json('data'), 'artifactId');

        $this->assertSame([(string) $awaiting->id], $ids('awaiting'));
        $this->assertSame([(string) $overdue->id], $ids('overdue'));
        $this->assertSame([(string) $received->id], $ids('received'));
        $this->assertSame('overdue', $overdue->fresh()->hardCopyStatus());
    }
}
