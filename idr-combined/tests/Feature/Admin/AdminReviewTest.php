<?php

namespace Tests\Feature\Admin;

use App\Contracts\VectorIndex;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Jobs\DeleteArtifactVectors;
use App\Jobs\ProcessArtifactIngestion;
use App\Jobs\SyncArtifactVectorMetadata;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AdminReviewTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->actingAsAdmin();
    }

    /** A VectorIndex whose every call fails, as if Qdrant were down. */
    private function qdrantIsDown(): void
    {
        $this->app->instance(VectorIndex::class, new class implements VectorIndex
        {
            public function deleteArtifact(string $artifactId): void
            {
                throw new RuntimeException('Qdrant unreachable');
            }

            public function updateArtifactMetadata(string $artifactId, array $metadata): void
            {
                throw new RuntimeException('Qdrant unreachable');
            }
        });
    }

    // ── approve / reject ────────────────────────────────────────────────

    public function test_admin_approves_a_pending_submission(): void
    {
        $doc = Artifact::factory()->pending()->embedded()->create();

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('message', 'Document approved.')
            ->assertJsonPath('data.reviewStatus', 'approved')
            ->assertJsonPath('data.reviewedBy', (string) $this->admin->id)
            ->assertJsonPath('vectorSync', 'synced');

        $doc->refresh();
        $this->assertSame(ReviewStatus::Approved, $doc->status);
        $this->assertNotNull($doc->reviewed_at);

        // Qdrant payload updated without re-embedding.
        [$op, $id, $metadata] = $this->vectorIndex()->calls[0];
        $this->assertSame(['update', (string) $doc->id], [$op, $id]);
        $this->assertTrue($metadata['is_approved']);
        $this->assertSame('approved', $metadata['status']);
    }

    public function test_admin_rejects_with_a_reason_the_student_can_see(): void
    {
        $owner = User::factory()->create();
        $doc = Artifact::factory()->pending()->embedded()->uploadedBy($owner)->create();

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'Rejected', 'reason' => 'Wrong course code'])
            ->assertOk()
            ->assertJsonPath('data.reviewStatus', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Wrong course code');

        $this->assertFalse($this->vectorIndex()->calls[0][2]['is_approved']);

        $this->actingAsStudent($owner);
        $this->getJson('/api/documents/mine')->assertJsonPath('data.0.rejectionReason', 'Wrong course code');
    }

    public function test_approving_clears_an_old_rejection_reason(): void
    {
        $doc = Artifact::factory()->rejected('Blurry')->create();

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'approved'])
            ->assertJsonPath('data.rejectionReason', null);
    }

    public function test_status_must_be_approved_or_rejected(): void
    {
        $doc = Artifact::factory()->pending()->create();

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->patchJson("/api/admin/documents/{$doc->id}/status", [])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_not_yet_embedded_files_skip_the_vector_update(): void
    {
        $doc = Artifact::factory()->pending()->create(); // processing still PENDING

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'approved'])
            ->assertJsonPath('vectorSync', 'skipped');

        $this->assertSame([], $this->vectorIndex()->calls);
    }

    public function test_vector_update_is_retried_from_the_queue_when_qdrant_is_down(): void
    {
        Queue::fake();
        $this->qdrantIsDown();
        $doc = Artifact::factory()->pending()->embedded()->create();

        $this->patchJson("/api/admin/documents/{$doc->id}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('vectorSync', 'queued');

        // The approval itself still went through.
        $this->assertSame(ReviewStatus::Approved, $doc->refresh()->status);
        Queue::assertPushed(SyncArtifactVectorMetadata::class, fn ($job) => $job->artifactId === (string) $doc->id);
    }

    // ── edit details ────────────────────────────────────────────────────

    public function test_admin_edits_details_and_qdrant_payload_follows(): void
    {
        $doc = Artifact::factory()->embedded()->create(['title' => 'Old Title', 'course_code' => 'CSC 101', 'academic_year' => '2022/2023']);

        $this->patchJson("/api/admin/documents/{$doc->id}", [
            'title' => '  Compiler   Construction ',
            'courseCode' => 'csc401',
            'academicYear' => '2024/2025',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Compiler Construction')
            ->assertJsonPath('data.courseCode', 'CSC 401')
            ->assertJsonPath('data.academicYear', '2024/2025')
            ->assertJsonPath('vectorSync', 'synced');

        $doc->refresh();
        $this->assertSame('Compiler Construction', $doc->title);
        $this->assertSame('CSC 401', $doc->course_code);

        [$op, , $metadata] = $this->vectorIndex()->calls[0];
        $this->assertSame('update', $op);
        $this->assertSame(['Compiler Construction', 'CSC 401', '2024/2025'], [$metadata['title'], $metadata['course_code'], $metadata['academic_year']]);
    }

    public function test_put_works_too_and_partial_updates_keep_other_fields(): void
    {
        $doc = Artifact::factory()->create(['title' => 'Keep Me', 'course_code' => 'CSC 101']);

        $this->putJson("/api/admin/documents/{$doc->id}", ['academicYear' => '2023/2024'])->assertOk();

        $doc->refresh();
        $this->assertSame('Keep Me', $doc->title);
        $this->assertSame('CSC 101', $doc->course_code);
        $this->assertSame('2023/2024', $doc->academic_year);
    }

    public function test_edit_cannot_touch_protected_fields(): void
    {
        $doc = Artifact::factory()->pending()->create();
        $originalPath = $doc->file_path;

        $this->patchJson("/api/admin/documents/{$doc->id}", [
            'title' => 'New title',
            'status' => 'approved',
            'file_path' => '../../.env',
            'uploaded_by' => (string) $this->admin->id,
        ])->assertOk();

        $doc->refresh();
        $this->assertSame(ReviewStatus::Pending, $doc->status);
        $this->assertSame($originalPath, $doc->file_path);
        $this->assertNotSame((string) $this->admin->id, $doc->uploaded_by);
    }

    public function test_edit_validation(): void
    {
        $doc = Artifact::factory()->create();

        $this->patchJson("/api/admin/documents/{$doc->id}", [])->assertStatus(422);
        $this->patchJson("/api/admin/documents/{$doc->id}", ['courseCode' => 'nonsense', 'title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['courseCode', 'title']);
    }

    // ── delete ──────────────────────────────────────────────────────────

    public function test_delete_removes_record_file_and_vectors(): void
    {
        $doc = Artifact::factory()->embedded()->create();
        $this->storeFileFor($doc);

        $this->deleteJson("/api/admin/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Document permanently deleted.')
            ->assertJsonPath('data.steps', ['database' => 'deleted', 'file' => 'deleted', 'vectors' => 'deleted']);

        $this->assertNull(Artifact::find($doc->id));
        Storage::disk('local')->assertMissing($doc->file_path);
        $this->assertSame([['delete', (string) $doc->id]], $this->vectorIndex()->calls);

        // Gone everywhere.
        $this->getJson("/api/documents/{$doc->id}")->assertNotFound();
        $this->getJson("/api/admin/documents/{$doc->id}")->assertNotFound();
    }

    public function test_delete_still_succeeds_when_qdrant_is_down(): void
    {
        Queue::fake();
        $this->qdrantIsDown();
        $doc = Artifact::factory()->embedded()->create();
        $this->storeFileFor($doc);

        $this->deleteJson("/api/admin/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.vectors', 'queued');

        $this->assertNull(Artifact::find($doc->id));
        Queue::assertPushed(DeleteArtifactVectors::class, fn ($job) => $job->artifactId === (string) $doc->id);
    }

    public function test_delete_reports_an_already_missing_file(): void
    {
        $doc = Artifact::factory()->create();

        $this->deleteJson("/api/admin/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.file', 'missing');
    }

    public function test_deleting_twice_is_404(): void
    {
        $doc = Artifact::factory()->create();

        $this->deleteJson("/api/admin/documents/{$doc->id}")->assertOk();
        $this->deleteJson("/api/admin/documents/{$doc->id}")->assertNotFound();
    }

    // ── admin preview + reprocess ───────────────────────────────────────

    public function test_admin_previews_pending_file_inline(): void
    {
        $doc = Artifact::factory()->pending()->create();
        $this->storeFileFor($doc, "%PDF-1.4\nsecret pending body\n%%EOF\n");

        $response = $this->get("/api/admin/documents/{$doc->id}/preview")->assertOk();

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('secret pending body', $response->streamedContent());
    }

    public function test_reprocess_requeues_a_failed_file(): void
    {
        Queue::fake();
        $doc = Artifact::factory()->failed('Gemini 503')->create();

        $this->postJson("/api/admin/documents/{$doc->id}/reprocess")
            ->assertStatus(202)
            ->assertJsonPath('data.processingStatus', 'PENDING');

        $doc->refresh();
        $this->assertSame(ProcessingStatus::Pending, $doc->processing_status);
        $this->assertNull($doc->processing_error);
        Queue::assertPushed(ProcessArtifactIngestion::class);
    }

    public function test_reprocess_refuses_a_file_already_in_the_queue(): void
    {
        $doc = Artifact::factory()->create(); // processing PENDING

        $this->postJson("/api/admin/documents/{$doc->id}/reprocess")->assertStatus(409);
    }

    public function test_admin_show_includes_uploader(): void
    {
        $owner = User::factory()->create(['name' => 'Chidi']);
        $doc = Artifact::factory()->pending()->uploadedBy($owner)->create();

        $this->getJson("/api/admin/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonPath('data.uploader.name', 'Chidi')
            ->assertJsonPath('data.filePath', $doc->file_path);
    }
}
