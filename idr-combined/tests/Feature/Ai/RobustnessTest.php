<?php

namespace Tests\Feature\Ai;

use App\Models\Artifact;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiDocumentIngestor;
use App\Services\Ai\QdrantClient;
use App\Services\Ai\SlowCallLog;
use App\Services\Ai\TlsOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Problems seen on a real Windows machine: no CA certificates for PHP's cURL,
 * failed jobs colliding on the unique uuid index, and large PDFs running out of memory.
 */
class RobustnessTest extends TestCase
{
    public function test_https_calls_use_the_bundled_ca_list_by_default(): void
    {
        $bundle = TlsOptions::forHttpClient()['verify'] ?? null;

        $this->assertSame(resource_path('certs/cacert.pem'), $bundle);
        $this->assertFileExists($bundle);
        $this->assertGreaterThan(100, substr_count(file_get_contents($bundle), 'BEGIN CERTIFICATE'));
        // Google (Gemini) and Let's Encrypt / Amazon (Qdrant Cloud) roots are included.
        foreach (['GTS Root R1', 'ISRG Root X1', 'Amazon Root CA 1'] as $root) {
            $this->assertStringContainsString("Label: \"{$root}\"", file_get_contents($bundle));
        }
    }

    public function test_ca_bundle_can_be_switched_to_php_settings_or_another_file(): void
    {
        config(['services.ca_bundle' => 'system']);
        $this->assertSame([], TlsOptions::forHttpClient());

        config(['services.ca_bundle' => '/nope/cacert.pem']);
        $this->expectException(RuntimeException::class);
        TlsOptions::forHttpClient();
    }

    public function test_gemini_and_qdrant_clients_send_the_verify_option(): void
    {
        $seen = [];
        Http::globalRequestMiddleware(fn ($request) => $request); // ensure middleware stack exists
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = $options['verify'] ?? null;

            return Http::response(['embedding' => ['values' => [0.1, 0.2]], 'result' => ['exists' => true]]);
        });

        (new GeminiClient(['key' => 'k', 'embedding_dimensions' => 2]))->embedQuery('hello');
        (new QdrantClient(['url' => 'https://qdrant.test']))->collectionExists();

        $this->assertSame([resource_path('certs/cacert.pem'), resource_path('certs/cacert.pem')], $seen);
    }

    public function test_every_failed_job_is_recorded_with_its_own_uuid(): void
    {
        // The real failed_jobs collection has a unique index on uuid.
        DB::connection('mongodb')->getCollection('failed_jobs')->createIndex(['uuid' => 1], ['unique' => true]);
        $failer = app('queue.failer');

        foreach ([1, 2] as $n) {
            $failer->log('redis', 'ingestion', json_encode(['uuid' => "job-{$n}", 'displayName' => 'Test']), new RuntimeException("boom {$n}"));
        }

        $uuids = DB::connection('mongodb')->table('failed_jobs')->pluck('uuid')->all();
        $this->assertEqualsCanonicalizing(['job-1', 'job-2'], $uuids);
    }

    public function test_pdf_parsing_gets_more_memory_then_restores_the_limit(): void
    {
        Storage::fake('local');
        config([
            'services.gemini.key' => 'k', 'services.gemini.embedding_dimensions' => 2,
            'services.qdrant.url' => 'https://qdrant.test', 'services.chunking.pdf_memory_limit' => '512M',
        ]);
        $artifact = Artifact::factory()->pending()->create();
        $this->storeFileFor($artifact, file_get_contents(base_path('tests/Fixtures/sample-project.pdf')));

        $limitDuringParse = null;
        Http::fake(function ($request) use (&$limitDuringParse) {
            $limitDuringParse ??= ini_get('memory_limit');

            return str_contains($request->url(), 'batchEmbedContents')
                ? Http::response(['embeddings' => array_map(fn () => ['values' => [0.1, 0.2]], $request['requests'])])
                : Http::response(['result' => true]);
        });

        $before = ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        try {
            $chunks = app(GeminiDocumentIngestor::class)->ingest($artifact);
            $this->assertGreaterThan(0, $chunks);
            $this->assertSame('256M', ini_get('memory_limit'), 'limit is restored after parsing');
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    public function test_gemini_3_chat_uses_low_thinking_and_older_models_do_not(): void
    {
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request['generationConfig'];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);
        });

        (new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-3.8-flash', 'chat_thinking_level' => 'low']))
            ->generate('system', [['role' => 'user', 'text' => 'hi']]);
        (new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-2.5-flash', 'chat_thinking_level' => 'low']))
            ->generate('system', [['role' => 'user', 'text' => 'hi']]);
        (new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-3.8-flash', 'chat_thinking_level' => '']))
            ->generate('system', [['role' => 'user', 'text' => 'hi']]);

        $this->assertSame(['thinkingLevel' => 'low'], $sent[0]['thinkingConfig']);
        $this->assertArrayNotHasKey('thinkingConfig', $sent[1]);
        $this->assertArrayNotHasKey('thinkingConfig', $sent[2]);
    }

    public function test_chat_and_search_requests_get_more_than_30_seconds(): void
    {
        $this->assertSame(120, config('idr.ai_request_seconds'));
        // query embedding + vector search + chat (all models together) fit in the limit.
        $this->assertLessThan(120, config('services.gemini.timeout') + config('services.qdrant.timeout') + config('services.gemini.chat_budget_seconds'));
    }

    public function test_chat_falls_back_to_another_model_when_gemini_is_overloaded(): void
    {
        Log::spy();
        $models = [];
        Http::fake(function ($request) use (&$models) {
            preg_match('#models/([^:]+):generateContent#', $request->url(), $m);
            $models[] = $m[1];

            return $m[1] === 'gemini-3.8-flash'
                ? Http::response(['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']], 503)
                : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'answer from fallback']]]]]]);
        });

        $text = (new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-3.8-flash', 'chat_fallback_models' => 'gemini-3.6-flash, gemini-3.5-flash-lite']))
            ->generate('system', [['role' => 'user', 'text' => 'hi']]);

        $this->assertSame('answer from fallback', $text);
        $this->assertSame(['gemini-3.8-flash', 'gemini-3.6-flash'], $models, 'no same-model retry; stops at the first model that answers');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'trying fallback') && $ctx['reason'] === '503')->once();
    }

    public function test_chat_gives_up_after_every_model_is_busy(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 503]], 503)]);
        $client = new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-3.8-flash', 'chat_fallback_models' => 'gemini-3.6-flash']);

        try {
            $client->generate('system', [['role' => 'user', 'text' => 'hi']]);
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gemini-3.6-flash', $e->getMessage());
            $this->assertStringContainsString('(503)', $e->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_chat_does_not_fall_back_on_a_bad_request(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400)]);

        try {
            (new GeminiClient(['key' => 'k', 'chat_model' => 'gemini-3.8-flash', 'chat_fallback_models' => 'gemini-3.6-flash']))
                ->generate('system', [['role' => 'user', 'text' => 'hi']]);
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(400)', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_slow_ai_calls_are_logged(): void
    {
        Log::spy();

        SlowCallLog::record('Gemini', 'models/x:generateContent', microtime(true) - 4.2);
        SlowCallLog::record('Qdrant', '/points/query', microtime(true) - 0.2);

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn ($message, $context) => $message === 'Slow Gemini call' && $context['ms'] >= 4000);
    }
}
