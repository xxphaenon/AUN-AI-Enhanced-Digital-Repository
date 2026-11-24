<?php

namespace Tests\Feature;

use App\Contracts\RetrievalService;
use App\Enums\DocumentType;
use App\Models\Artifact;
use App\Models\User;
use App\Support\RetrievedChunk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fields the Next.js frontend sends: a document type on uploads (also a search
 * filter) and an optional student/staff ID at sign-up.
 */
class DocumentTypeAndUniversityIdTest extends TestCase
{
    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('paper.pdf', "%PDF-1.4\nquestions\n%%EOF\n");
    }

    /* ── Student / staff ID ─────────────────────────────────────── */

    public function test_registration_stores_the_university_id(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ada Obi', 'email' => 'ada.obi@aun.edu.ng', 'universityId' => ' a00012345 ',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertCreated()->assertJsonPath('data.user.universityId', 'A00012345');

        $this->assertSame('A00012345', User::where('email', 'ada.obi@aun.edu.ng')->first()->university_id);
    }

    public function test_university_id_is_optional_and_validated(): void
    {
        $base = ['name' => 'Ada Obi', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'];

        $this->postJson('/api/auth/register', $base + ['email' => 'one@aun.edu.ng'])
            ->assertCreated()->assertJsonPath('data.user.universityId', null);

        $this->postJson('/api/auth/register', $base + ['email' => 'two@aun.edu.ng', 'universityId' => 'A001 <script>'])
            ->assertUnprocessable()->assertJsonValidationErrors('universityId');
    }

    /* ── Document type ──────────────────────────────────────────── */

    public function test_upload_stores_document_type(): void
    {
        Storage::fake('local');
        Queue::fake();
        $student = $this->actingAsStudent();

        $id = $this->post('/api/documents/upload', [
            'file' => $this->pdf(), 'title' => 'Compilers Final Exam', 'courseCode' => 'CSC 401',
            'academicYear' => '2024/2025', 'documentType' => 'research',
            'declarationAccepted' => '1', 'declarationName' => $student->name,
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data.artifactId');

        $this->assertSame(DocumentType::Research, Artifact::find($id)->document_type);
    }

    public function test_upload_rejects_unknown_document_type(): void
    {
        Storage::fake('local');
        Queue::fake();
        $student = $this->actingAsStudent();

        $this->post('/api/documents/upload', [
            'file' => $this->pdf(), 'title' => 'Compilers Final Exam', 'courseCode' => 'CSC 401',
            'academicYear' => '2024/2025', 'documentType' => 'past-paper',
            'declarationAccepted' => '1', 'declarationName' => $student->name,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('documentType');
    }

    public function test_type_appears_in_documents_and_filters_the_catalogue(): void
    {
        $project = Artifact::factory()->create(['document_type' => DocumentType::Project, 'title' => 'Smart Farm']);
        Artifact::factory()->create(['document_type' => DocumentType::Research]);

        $this->getJson('/api/documents?documentType=project')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.artifactId', (string) $project->id)
            ->assertJsonPath('data.0.documentType', 'project')
            ->assertJsonPath('data.0.documentTypeLabel', 'Senior Design Project');
    }

    public function test_admin_can_filter_and_edit_document_type_and_qdrant_gets_it(): void
    {
        $artifact = Artifact::factory()->embedded()->create(['document_type' => DocumentType::Project]);
        Artifact::factory()->create(['document_type' => DocumentType::Research]);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/documents?documentType=project')->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/admin/documents/{$artifact->id}", ['documentType' => 'research'])
            ->assertOk()->assertJsonPath('data.documentType', 'research');

        $this->assertSame(DocumentType::Research, $artifact->fresh()->document_type);
        $this->assertSame('research', end($this->vectorIndex()->calls)[2]['document_type']);
    }

    public function test_search_type_filter_limits_retrieval_to_that_type(): void
    {
        $research = Artifact::factory()->embedded()->create(['document_type' => DocumentType::Research, 'title' => 'Crop ML']);
        $project = Artifact::factory()->embedded()->create(['document_type' => DocumentType::Project]);
        Artifact::factory()->pending()->embedded()->create(['document_type' => DocumentType::Research]); // not public

        $retriever = new class implements RetrievalService
        {
            public ?array $ids = null;

            public array $chunks = [];

            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                $this->ids = $artifactIds;

                return $this->chunks;
            }
        };
        $retriever->chunks = [
            new RetrievedChunk((string) $research->id, 'research text', 0.9),
            new RetrievedChunk((string) $project->id, 'project text', 0.95), // a stale hit is still dropped
        ];
        $this->app->instance(RetrievalService::class, $retriever);

        $this->getJson('/api/search?q=crop+disease&type=research')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.artifactId', (string) $research->id)
            ->assertJsonPath('results.0.documentType', 'research');

        $this->assertSame([(string) $research->id], $retriever->ids);

        // "all" or no type: no restriction.
        $this->getJson('/api/search?q=crop+disease&type=all')->assertOk()->assertJsonCount(2, 'results');
        $this->assertSame([], $retriever->ids);
    }

    public function test_search_type_with_no_documents_returns_nothing_without_calling_the_ai(): void
    {
        $retriever = new class implements RetrievalService
        {
            public bool $called = false;

            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                $this->called = true;

                return [];
            }
        };
        $this->app->instance(RetrievalService::class, $retriever);

        $this->getJson('/api/search?q=anything&type=research')->assertOk()->assertJsonCount(0, 'results');
        $this->assertFalse($retriever->called);

        $this->getJson('/api/search?q=anything&type=report')->assertUnprocessable();
    }

    /* ── Proxy: per-user rate limits through the Next.js server ──── */

    public function test_login_rate_limit_is_per_forwarded_client_ip(): void
    {
        User::factory()->create(['email' => 'victim@aun.edu.ng']);
        $attempt = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', $ip)
            ->postJson('/api/auth/login', ['email' => 'victim@aun.edu.ng', 'password' => 'wrong-pass1']);

        for ($i = 0; $i < 5; $i++) {
            $attempt('41.58.1.1')->assertUnprocessable();
        }
        $attempt('41.58.1.1')->assertStatus(429);

        // Another student behind the same Next.js server is not locked out.
        $attempt('41.58.2.2')->assertUnprocessable();
    }

    public function test_forwarded_ip_is_ignored_from_untrusted_addresses(): void
    {
        User::factory()->create(['email' => 'victim@aun.edu.ng']);
        $attempt = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-For', $ip)
            ->postJson('/api/auth/login', ['email' => 'victim@aun.edu.ng', 'password' => 'wrong-pass1']);

        for ($i = 0; $i < 5; $i++) {
            $attempt("10.0.0.{$i}")->assertUnprocessable();
        }
        // Spoofing a new X-Forwarded-For does not reset the limit for a direct caller.
        $attempt('10.0.0.99')->assertStatus(429);
    }
}
