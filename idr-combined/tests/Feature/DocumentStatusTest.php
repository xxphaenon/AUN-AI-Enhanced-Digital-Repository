<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\User;
use Tests\TestCase;

/**
 * GET /api/documents/{artifactId}/status — polled by the frontend after an
 * upload. (The ingestion job itself is covered in IngestionJobTest.)
 */
class DocumentStatusTest extends TestCase
{
    public function test_status_is_for_the_uploader_and_admins_only(): void
    {
        $owner = User::factory()->create();
        $doc = Artifact::factory()->rejected('Blurry scan')->embedded(45)->uploadedBy($owner)->create();

        $this->getJson("/api/documents/{$doc->id}/status")->assertUnauthorized();

        $this->actingAsStudent();
        $this->getJson("/api/documents/{$doc->id}/status")->assertNotFound();

        $this->actingAsStudent($owner);
        $this->getJson("/api/documents/{$doc->id}/status")
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'artifactId' => (string) $doc->id,
                    'processingStatus' => 'EMBEDDED',
                    'chunksProcessed' => 45,
                    'reviewStatus' => 'rejected',
                    'rejectionReason' => 'Blurry scan',
                ],
            ]);

        $this->actingAsAdmin();
        $this->getJson("/api/documents/{$doc->id}/status")->assertOk();
    }

    public function test_failed_processing_includes_error(): void
    {
        $owner = User::factory()->create();
        $doc = Artifact::factory()->failed('Gemini quota exceeded')->uploadedBy($owner)->create();
        $this->actingAsStudent($owner);

        $this->getJson("/api/documents/{$doc->id}/status")
            ->assertJsonPath('data.processingStatus', 'FAILED')
            ->assertJsonPath('data.error', 'Gemini quota exceeded');
    }
}
