<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    private function pdf(string $name = 'csc401-exam.pdf', string $body = 'exam questions'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$body}\n%%EOF\n");
    }

    public function test_a_real_pdf_passes_content_sniffing(): void
    {
        $this->actingAsStudent();

        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        $this->postJson('/api/documents/upload', $this->payload(['file' => new UploadedFile($path, 'real.pdf', null, null, true)]))
            ->assertStatus(202);
    }

    private function payload(array $overrides = []): array
    {
        // Students sign the declaration with their own name (see SubmissionDeclarationTest).
        $user = auth()->user();
        $declaration = $user !== null && ! $user->isAdmin()
            ? ['declarationAccepted' => '1', 'declarationName' => $user->name]
            : [];

        return array_merge([
            'file' => $this->pdf(),
            'title' => 'Data Structures Final Exam',
            'courseCode' => 'csc401',
            'academicYear' => '2024 / 2025',
            'author' => 'Dept. of Computer Science',
        ], $declaration, $overrides);
    }

    public function test_student_submission_is_saved_pending_and_queued(): void
    {
        $student = $this->actingAsStudent();

        $response = $this->post('/api/documents/upload', $this->payload(), ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.processingStatus', 'PENDING')
            ->assertJsonPath('data.reviewStatus', 'pending');

        $artifact = Artifact::find($response->json('data.artifactId'));
        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertSame(ProcessingStatus::Pending, $artifact->processing_status);
        $this->assertSame((string) $student->id, $artifact->uploaded_by);
        $this->assertNull($artifact->reviewed_by);
        $this->assertSame('CSC 401', $artifact->course_code);
        $this->assertSame('2024/2025', $artifact->academic_year);
        $this->assertSame('csc401-exam.pdf', $artifact->original_filename);
        $this->assertSame(64, strlen($artifact->file_hash));
        Storage::disk('local')->assertExists($artifact->file_path);
        $this->assertStringStartsWith('artifacts/', $artifact->file_path);

        Queue::assertPushedOn('ingestion', ProcessArtifactIngestion::class,
            fn (ProcessArtifactIngestion $job) => $job->artifactId === (string) $artifact->id);
    }

    public function test_admin_upload_is_approved_immediately(): void
    {
        $admin = $this->actingAsAdmin();

        $id = $this->postJson('/api/documents/upload', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.reviewStatus', 'approved')
            ->json('data.artifactId');

        $artifact = Artifact::find($id);
        $this->assertSame(ReviewStatus::Approved, $artifact->status);
        $this->assertSame((string) $admin->id, $artifact->reviewed_by);
        $this->assertNotNull($artifact->reviewed_at);
    }

    public function test_admin_auto_approval_can_be_switched_off(): void
    {
        config(['idr.review.auto_approve_admin_uploads' => false]);
        $this->actingAsAdmin();

        $this->postJson('/api/documents/upload', $this->payload())
            ->assertJsonPath('data.reviewStatus', 'pending');
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson('/api/documents/upload', $this->payload())->assertUnauthorized();

        $this->assertSame(0, Artifact::count());
        Queue::assertNothingPushed();
    }

    public function test_uploader_id_may_be_sent_but_must_be_the_callers_own(): void
    {
        $student = $this->actingAsStudent();
        $someoneElse = User::factory()->create();

        $this->postJson('/api/documents/upload', $this->payload(['uploaderId' => (string) $someoneElse->id]))
            ->assertForbidden();
        $this->assertSame(0, Artifact::count());

        $this->postJson('/api/documents/upload', $this->payload(['uploaderId' => (string) $student->id]))
            ->assertStatus(202);
    }

    public function test_role_cannot_be_smuggled_into_upload_fields(): void
    {
        $this->actingAsStudent();

        $id = $this->postJson('/api/documents/upload', $this->payload(['status' => 'approved', 'uploaded_by' => 'x', 'reviewed_by' => 'y']))
            ->assertStatus(202)->json('data.artifactId');

        $artifact = Artifact::find($id);
        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertNull($artifact->reviewed_by);
    }

    public function test_validation_errors(): void
    {
        $this->actingAsStudent();

        $this->postJson('/api/documents/upload', $this->payload([
            'file' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'title' => '',
            'courseCode' => 'Computer Science',
            'academicYear' => '2024/2026',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['file', 'title', 'courseCode', 'academicYear']);
    }

    public function test_a_renamed_non_pdf_is_rejected(): void
    {
        $this->actingAsStudent();

        // A real temp file (not UploadedFile::fake(), which reports its type
        // from the name), so the server sniffs the actual bytes like it
        // does for real uploads.
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "MZ\x90\x00\x03\x00\x00\x00 this is really a Windows executable");
        $renamed = new UploadedFile($path, 'virus.pdf', null, null, true);

        $this->postJson('/api/documents/upload', $this->payload(['file' => $renamed]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_same_file_cannot_be_submitted_twice_while_pending_or_approved(): void
    {
        $student = $this->actingAsStudent();

        $first = $this->postJson('/api/documents/upload', $this->payload())->assertStatus(202)->json('data.artifactId');

        $this->postJson('/api/documents/upload', $this->payload(['title' => 'Same file, new title']))
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data.artifactId', $first);

        // Another student hitting the same pending file learns nothing about it.
        $this->actingAsStudent();
        $this->postJson('/api/documents/upload', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('data', null);

        // Once rejected, the same file may be submitted again.
        Artifact::find($first)->forceFill(['status' => ReviewStatus::Rejected])->save();
        $this->actingAsStudent($student);
        $this->postJson('/api/documents/upload', $this->payload())->assertStatus(202);
    }

    public function test_dispatch_failure_marks_document_failed_and_returns_503(): void
    {
        $this->actingAsStudent();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Redis down'));
        Queue::shouldReceive('push', 'pushOn', 'later', 'laterOn')->andThrow(new \RuntimeException('Redis down'));

        $this->postJson('/api/documents/upload', $this->payload())->assertStatus(503);

        $this->assertSame(ProcessingStatus::Failed, Artifact::first()->processing_status);
    }
}
