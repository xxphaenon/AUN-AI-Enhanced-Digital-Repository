<?php

namespace Tests\Feature\Ai;

use App\Contracts\ChatResponder;
use App\Contracts\DocumentIngestor;
use App\Contracts\RetrievalService;
use App\Contracts\VectorIndex;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Services\Ai\GeminiChatResponder;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiDocumentIngestor;
use App\Services\Ai\QdrantClient;
use App\Services\Ai\QdrantRetrievalService;
use App\Services\Ai\QdrantVectorIndex;
use App\Services\Ai\TextChunker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The live Gemini + Qdrant implementations, wired into the real backend
 * (routes, review workflow, MongoDB), with the two external APIs faked.
 */
class LiveAiPipelineTest extends TestCase
{
    private const DIMS = 4;

    /** Qdrant hits the fake returns for /points/query. */
    private array $qdrantHits = [];

    /** Points the fake Qdrant returns for /points/scroll. */
    private array $scrollPoints = [];

    /** Whether the fake Qdrant says the collection exists. */
    private bool $collectionExists = true;

    /** Answer text the fake Gemini chat model returns. */
    private string $chatAnswer = 'No answer.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.embedding_dimensions' => self::DIMS,
            'services.gemini.embedding_batch_size' => 2,
            'services.qdrant.url' => 'https://qdrant.test',
            'services.qdrant.key' => 'test-qdrant-key',
            'services.qdrant.collection' => 'idr_chunks',
            'services.chunking.chunk_words' => 500,
            'services.chunking.overlap_words' => 100,
        ]);

        // phpunit.xml runs with IDR_AI_DRIVER=fake; bind the live classes here.
        $this->app->forgetInstance(GeminiClient::class);
        $this->app->forgetInstance(QdrantClient::class);
        foreach ([
            DocumentIngestor::class => GeminiDocumentIngestor::class,
            RetrievalService::class => QdrantRetrievalService::class,
            ChatResponder::class => GeminiChatResponder::class,
            VectorIndex::class => QdrantVectorIndex::class,
        ] as $contract => $live) {
            $this->app->forgetInstance($contract);
            $this->app->singleton($contract, $live);
        }

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, ':batchEmbedContents')) {
                return Http::response(['embeddings' => array_map(
                    fn () => ['values' => array_fill(0, self::DIMS, 0.1)],
                    $request['requests'],
                )]);
            }
            if (str_contains($url, ':embedContent')) {
                return Http::response(['embedding' => ['values' => [0.4, 0.3, 0.2, 0.1]]]);
            }
            if (str_contains($url, ':generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => $this->chatAnswer]]]]]]);
            }
            if (str_contains($url, '/points/scroll')) {
                return Http::response(['result' => ['points' => $this->scrollPoints, 'next_page_offset' => null]]);
            }
            if (str_contains($url, '/exists')) {
                return Http::response(['result' => ['exists' => $this->collectionExists]]);
            }
            if (str_contains($url, '/points/query')) {
                return Http::response(['result' => ['points' => $this->qdrantHits]]);
            }

            return Http::response(['result' => ['status' => 'completed'], 'status' => 'ok']);
        });
    }

    private function qdrantCalls(string $pathPart): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_starts_with($r->url(), 'https://qdrant.test') && str_contains($r->url(), $pathPart))
            ->values()
            ->all();
    }

    private function hit(Artifact $artifact, float $score, string $text): array
    {
        return ['id' => (string) Str::uuid(), 'score' => $score, 'payload' => [
            'artifactId' => (string) $artifact->id, 'text' => $text, 'chunkIndex' => 0,
        ]];
    }

    /* ── Ingestion ──────────────────────────────────────────────── */

    public function test_ingestion_embeds_pdf_chunks_with_approval_metadata(): void
    {
        $artifact = Artifact::factory()->pending()->create([
            'title' => 'Smart Irrigation', 'course_code' => 'CSC 499', 'academic_year' => '2025/2026',
        ]);
        $this->storeFileFor($artifact, file_get_contents(base_path('tests/Fixtures/sample-project.pdf')));

        app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);

        $artifact->refresh();
        $this->assertSame(ProcessingStatus::Embedded, $artifact->processing_status);
        $this->assertGreaterThanOrEqual(3, $artifact->chunks_processed, '~1200 words → several 500-word chunks');

        // Old points are removed before new ones are written (idempotent re-runs).
        $writes = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'qdrant.test') && (str_contains($r->url(), '/points/delete') || $r->method() === 'PUT'))
            ->values();
        $this->assertStringContainsString('/points/delete', $writes[0]->url());
        $this->assertSame('PUT', $writes[1]->method());

        $points = collect($this->qdrantCalls('/collections/idr_chunks/points?'))->flatMap(fn (Request $r) => $r['points']);
        $this->assertCount($artifact->chunks_processed, $points);

        $payload = $points[0]['payload'];
        $this->assertSame((string) $artifact->id, $payload['artifactId']);
        $this->assertSame('pending', $payload['status']);
        $this->assertFalse($payload['is_approved']);
        $this->assertSame('CSC 499', $payload['course_code']);
        $this->assertSame('2025/2026', $payload['academic_year']);
        $this->assertSame(0, $payload['chunkIndex']);
        $this->assertStringContainsString('Irrigation', $payload['text']);
        $this->assertCount(self::DIMS, $points[0]['vector']['content']);

        // Gemini was asked for document embeddings, in batches of 2.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'gemini-embedding-001:batchEmbedContents')
            && $r->hasHeader('x-goog-api-key', 'test-gemini-key')
            && $r['requests'][0]['taskType'] === 'RETRIEVAL_DOCUMENT');

        // After ingestion the backend re-sends current metadata (set payload).
        $this->assertCount(1, $this->qdrantCalls('/points/payload'));
    }

    public function test_ingestion_rejects_pdf_without_text(): void
    {
        $artifact = Artifact::factory()->pending()->create();
        $this->storeFileFor($artifact, file_get_contents(base_path('tests/Fixtures/blank-scan.pdf')));

        $this->expectExceptionMessage('No readable text');
        app(DocumentIngestor::class)->ingest($artifact);
    }

    public function test_unreadable_pdf_marks_artifact_failed_and_writes_no_vectors(): void
    {
        $artifact = Artifact::factory()->pending()->create();
        $this->storeFileFor($artifact, "%PDF-1.4\n% not really a pdf\n%%EOF\n");

        try {
            app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);
            $this->fail('Expected the ingestion to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not read the PDF', $e->getMessage());
            (new ProcessArtifactIngestion((string) $artifact->id))->failed($e); // what the queue does after the last retry
        }

        $this->assertSame(ProcessingStatus::Failed, $artifact->fresh()->processing_status);
        Http::assertNothingSent();
    }

    /* ── Search visibility ──────────────────────────────────────── */

    public function test_student_search_filters_on_is_approved_in_qdrant(): void
    {
        $approved = Artifact::factory()->embedded()->create(['title' => 'Approved Project']);
        $pending = Artifact::factory()->pending()->embedded()->create(['title' => 'Pending Project']);
        // Simulate a stale payload: Qdrant returns the pending one anyway.
        $this->qdrantHits = [$this->hit($approved, 0.91, 'approved text'), $this->hit($pending, 0.95, 'pending text')];

        $this->getJson('/api/search?q=irrigation')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.artifactId', (string) $approved->id);

        $query = $this->qdrantCalls('/points/query')[0];
        $this->assertSame(['must' => [['key' => 'is_approved', 'match' => ['value' => true]]]], $query['filter']);
        $this->assertSame('content', $query['using']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':embedContent') && $r['taskType'] === 'RETRIEVAL_QUERY');
    }

    public function test_admin_search_has_no_approval_filter_and_shows_status(): void
    {
        $approved = Artifact::factory()->embedded()->create();
        $pending = Artifact::factory()->pending()->embedded()->create();
        $rejected = Artifact::factory()->rejected()->embedded()->create();
        $this->qdrantHits = [
            $this->hit($pending, 0.95, 'p'), $this->hit($approved, 0.9, 'a'), $this->hit($rejected, 0.85, 'r'),
        ];

        $this->actingAsAdmin();
        $res = $this->getJson('/api/admin/search?q=irrigation')->assertOk();

        $this->assertEqualsCanonicalizing(['pending', 'approved', 'rejected'], array_column($res->json('results'), 'reviewStatus'));
        $this->assertArrayNotHasKey('filter', $this->qdrantCalls('/points/query')[0]->data());
    }

    public function test_students_cannot_use_admin_search(): void
    {
        $this->actingAsStudent();
        $this->getJson('/api/admin/search?q=x')->assertForbidden();
        $this->assertSame([], $this->qdrantCalls('/points/query'));
    }

    public function test_retrieval_can_be_limited_to_specific_documents(): void
    {
        $this->assertSame(
            ['must' => [
                ['key' => 'is_approved', 'match' => ['value' => true]],
                ['key' => 'artifactId', 'match' => ['any' => ['a1', 'a2']]],
            ]],
            QdrantRetrievalService::buildFilter(false, ['a1', 'a2', 'a1']),
        );
        $this->assertNull(QdrantRetrievalService::buildFilter(true));
    }

    /* ── Review workflow → Qdrant sync ──────────────────────────── */

    public function test_approving_updates_qdrant_payload_without_reembedding(): void
    {
        $artifact = Artifact::factory()->pending()->embedded()->create();

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/documents/{$artifact->id}/status", ['status' => 'approved'])->assertOk();

        $calls = $this->qdrantCalls('/points/payload');
        $this->assertCount(1, $calls);
        $this->assertSame('approved', $calls[0]['payload']['status']);
        $this->assertTrue($calls[0]['payload']['is_approved']);
        $this->assertSame([['key' => 'artifactId', 'match' => ['value' => (string) $artifact->id]]], $calls[0]['filter']['must']);
        $this->assertArrayNotHasKey('text', $calls[0]['payload']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_admin_edit_updates_title_and_course_code_in_qdrant(): void
    {
        $artifact = Artifact::factory()->embedded()->create(['title' => 'Old', 'course_code' => 'CSC 401']);

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/documents/{$artifact->id}", ['title' => 'Corrected Title', 'courseCode' => 'csc402'])->assertOk();

        $payload = $this->qdrantCalls('/points/payload')[0]['payload'];
        $this->assertSame('Corrected Title', $payload['title']);
        $this->assertSame('CSC 402', $payload['course_code']);
    }

    public function test_deleting_a_file_deletes_its_vectors(): void
    {
        $artifact = Artifact::factory()->embedded()->create();
        $this->storeFileFor($artifact);

        $this->actingAsAdmin();
        $this->deleteJson("/api/admin/documents/{$artifact->id}")->assertOk();

        $delete = $this->qdrantCalls('/points/delete')[0];
        $this->assertSame(['must' => [['key' => 'artifactId', 'match' => ['value' => (string) $artifact->id]]]], $delete['filter']);
        $this->assertNull(Artifact::find($artifact->id));
    }

    public function test_vector_index_keeps_is_approved_consistent_with_status(): void
    {
        app(VectorIndex::class)->updateArtifactMetadata('66f0000000000000000000aa', [
            'artifactId' => '66f0000000000000000000aa', 'title' => 'T', 'author' => null,
            'course_code' => 'CSC 401', 'academic_year' => '2024/2025', 'status' => 'rejected', 'is_approved' => true,
        ]);

        $this->assertFalse($this->qdrantCalls('/points/payload')[0]['payload']['is_approved']);
    }

    public function test_empty_artifact_id_never_becomes_a_delete_all(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(VectorIndex::class)->deleteArtifact('');
        } finally {
            $this->assertSame([], $this->qdrantCalls('/points/delete'));
        }
    }

    /* ── Chat ───────────────────────────────────────────────────── */

    public function test_chat_is_grounded_and_reports_cited_sources(): void
    {
        $a = Artifact::factory()->embedded()->create(['title' => 'Smart Irrigation', 'author' => 'Ada Obi']);
        $b = Artifact::factory()->embedded()->create(['title' => 'Crop Yield ML']);
        $hidden = Artifact::factory()->pending()->embedded()->create(['title' => 'Secret Draft']);
        $this->qdrantHits = [
            $this->hit($a, 0.9, 'Soil moisture sensors trigger irrigation.'),
            $this->hit($hidden, 0.88, 'unapproved text'),
            $this->hit($b, 0.8, 'Random forests predicted yield.'),
        ];
        $this->chatAnswer = 'Sensors control watering [1]. Yield was predicted with random forests [2].';

        $this->actingAsStudent();
        $res = $this->postJson('/api/chat/message', ['message' => 'How do the irrigation projects work?'])->assertOk();

        $this->assertSame([(string) $a->id, (string) $b->id], $res->json('sourcesReferenced'));
        $this->assertSame($this->chatAnswer, $res->json('message.content'));

        $sent = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), ':generateContent'));
        $system = $sent['systemInstruction']['parts'][0]['text'];
        $this->assertStringContainsString('[1] "Smart Irrigation" by Ada Obi', $system);
        $this->assertStringContainsString('Soil moisture sensors', $system);
        $this->assertStringNotContainsString('unapproved text', $system);
        $this->assertSame('user', $sent['contents'][0]['role']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/points/query') && $r['filter']['must'][0]['key'] === 'is_approved');
    }

    public function test_chat_citations_are_renumbered_to_match_the_sources_list(): void
    {
        $a = Artifact::factory()->embedded()->create(['title' => 'Air Quality']);
        $b = Artifact::factory()->embedded()->create(['title' => 'Smart Irrigation']);
        $c = Artifact::factory()->embedded()->create(['title' => 'Never cited']);
        $this->qdrantHits = [
            $this->hit($a, 0.9, 'Each station costs NGN 95,000.'),
            $this->hit($c, 0.85, 'Unrelated.'),
            $this->hit($b, 0.8, 'Each node costs NGN 68,000.'),
        ];
        // Prompt numbers: [1] Air Quality, [2] Never cited, [3] Smart Irrigation.
        $this->chatAnswer = 'Irrigation nodes cost NGN 68,000 [3]. Air stations cost NGN 95,000 [1]. Both [3, 1]. Odd [9].';

        $this->actingAsStudent();
        $res = $this->postJson('/api/chat/message', ['message' => 'How much did each node cost?'])->assertOk();

        // The UI shows sourcesReferenced as [1], [2], … so the text must use those numbers.
        $this->assertSame([(string) $b->id, (string) $a->id], $res->json('sourcesReferenced'));
        $this->assertSame('Irrigation nodes cost NGN 68,000 [1]. Air stations cost NGN 95,000 [2]. Both [1, 2]. Odd [9].', $res->json('message.content'));
    }

    public function test_chat_history_is_sent_as_alternating_turns(): void
    {
        $this->chatAnswer = 'I could not find that in the repository.';
        $responder = app(ChatResponder::class);

        $reply = $responder->respond('And the second?', [
            ['role' => 'model', 'content' => 'dangling model turn', 'timestamp' => 't0'],
            ['role' => 'user', 'content' => 'First question', 'timestamp' => 't1'],
            ['role' => 'model', 'content' => 'First answer [1]', 'timestamp' => 't2'],
        ], []);

        $this->assertSame([], $reply->sourcesReferenced);
        $sent = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), ':generateContent'));
        $this->assertSame(['user', 'model', 'user'], array_column($sent['contents'], 'role'));
        $this->assertStringContainsString('none matched', $sent['systemInstruction']['parts'][0]['text']);
    }

    /* ── Commands ───────────────────────────────────────────────── */

    public function test_qdrant_setup_creates_collection_and_indexes(): void
    {
        $this->collectionExists = false;
        config(['services.gemini.embedding_dimensions' => 3072]);

        $this->artisan('idr:qdrant-setup')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/collections/idr_chunks?')
            && $r['vectors'] === ['content' => ['size' => 3072, 'distance' => 'Cosine']]);
        $indexed = collect($this->qdrantCalls('/index'))->map(fn (Request $r) => $r['field_name'].':'.$r['field_schema'])->all();
        $this->assertSame(['artifactId:keyword', 'is_approved:bool', 'status:keyword', 'course_code:keyword', 'document_type:keyword'], $indexed);
    }

    public function test_reindex_embeds_documents_the_fake_driver_marked_embedded(): void
    {
        $artifact = Artifact::factory()->embedded()->create(); // e.g. seeded while IDR_AI_DRIVER=fake
        $this->storeFileFor($artifact, file_get_contents(base_path('tests/Fixtures/sample-project.pdf')));

        $this->artisan('idr:reindex')->expectsOutputToContain('Queued 1 of 1')->assertSuccessful();

        $this->assertSame(ProcessingStatus::Embedded, $artifact->fresh()->processing_status);
        $this->assertNotEmpty($this->qdrantCalls('/collections/idr_chunks/points?'));
        $this->assertTrue($this->qdrantCalls('/points/payload')[0]['payload']['is_approved']);
    }

    public function test_sync_vectors_pushes_metadata_and_prunes_orphans(): void
    {
        $kept = Artifact::factory()->embedded()->create();
        Artifact::factory()->create(); // not embedded: skipped
        $this->scrollPoints = [
            ['payload' => ['artifactId' => (string) $kept->id]],
            ['payload' => ['artifactId' => '66f0000000000000000000dd']],
        ];

        $this->artisan('idr:sync-vectors --prune')->expectsOutputToContain('Synced 1 document')->assertSuccessful();

        $this->assertCount(1, $this->qdrantCalls('/points/payload'));
        $deletes = $this->qdrantCalls('/points/delete');
        $this->assertCount(1, $deletes);
        $this->assertSame('66f0000000000000000000dd', $deletes[0]['filter']['must'][0]['match']['value']);
    }

    /* ── Building blocks ────────────────────────────────────────── */

    public function test_chunker_overlaps_and_does_not_emit_a_pure_overlap_tail(): void
    {
        $words = array_map(fn ($i) => "w{$i}", range(1, 900));
        $chunks = (new TextChunker(500, 100))->chunk(implode("  \n ", $words));

        $this->assertCount(2, $chunks);
        $this->assertStringStartsWith('w1 ', $chunks[0]);
        $this->assertStringStartsWith('w401 ', $chunks[1]);
        $this->assertStringEndsWith('w900', $chunks[1]);
        $this->assertSame([], (new TextChunker)->chunk("  \n "));
    }

    public function test_wrong_embedding_size_is_rejected(): void
    {
        config(['services.gemini.embedding_dimensions' => 3072]);
        $this->expectExceptionMessage('expected 3072');
        (new GeminiClient)->embedQuery('hello');
    }
}
