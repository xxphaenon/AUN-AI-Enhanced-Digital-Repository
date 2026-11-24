<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only class that talks to the Gemini API (REST, v1beta).
 *
 *  - embedDocuments(): many chunks per call (batchEmbedContents), task type RETRIEVAL_DOCUMENT
 *  - embedQuery():     one search query, task type RETRIEVAL_QUERY
 *  - generate():       chat answer from a system prompt + conversation. If the main
 *                      chat model is overloaded (429/5xx), timed out or retired (404),
 *                      the next model in chat_fallback_models is tried instead.
 *
 * Indexing and querying must use the same embedding model; changing it means
 * recreating the Qdrant collection and re-ingesting (php artisan idr:reindex).
 */
class GeminiClient
{
    protected string $key;

    protected string $baseUrl;

    protected string $embeddingModel;

    protected int $dimensions;

    protected int $batchSize;

    protected string $chatModel;

    protected int $timeout;

    protected ?string $thinkingLevel;

    /** @var list<string> */
    protected array $fallbackModels;

    protected int $chatBudget;

    /** Statuses that mean "this model can't answer right now", so another model may. */
    protected const FALLBACK_STATUSES = [404, 429, 500, 502, 503, 504];

    public function __construct(?array $config = null)
    {
        $config ??= config('services.gemini');

        $this->key = (string) ($config['key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->embeddingModel = (string) ($config['embedding_model'] ?? 'gemini-embedding-001');
        $this->dimensions = (int) ($config['embedding_dimensions'] ?? 3072);
        $this->batchSize = max(1, min(100, (int) ($config['embedding_batch_size'] ?? 50)));
        $this->chatModel = (string) ($config['chat_model'] ?? 'gemini-3.8-flash');
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->thinkingLevel = ($config['chat_thinking_level'] ?? 'low') ?: null;
        $fallbacks = $config['chat_fallback_models'] ?? [];
        $fallbacks = is_string($fallbacks) ? explode(',', $fallbacks) : (array) $fallbacks;
        $this->fallbackModels = array_values(array_diff(array_filter(array_map('trim', $fallbacks)), [$this->chatModel]));
        $this->chatBudget = (int) ($config['chat_budget_seconds'] ?? 60);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> One vector per text, same order.
     */
    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), $this->batchSize) as $batch) {
            $response = $this->post("models/{$this->embeddingModel}:batchEmbedContents", [
                'requests' => array_map(fn (string $text) => $this->embedRequest($text, 'RETRIEVAL_DOCUMENT') + [
                    'model' => "models/{$this->embeddingModel}",
                ], $batch),
            ]);

            $embeddings = $response['embeddings'] ?? [];

            if (count($embeddings) !== count($batch)) {
                throw new RuntimeException('Gemini returned '.count($embeddings).' embeddings for '.count($batch).' texts.');
            }

            foreach ($embeddings as $embedding) {
                $vectors[] = $this->checkVector($embedding['values'] ?? null);
            }
        }

        return $vectors;
    }

    /** @return list<float> */
    public function embedQuery(string $text): array
    {
        $response = $this->post(
            "models/{$this->embeddingModel}:embedContent",
            $this->embedRequest($text, 'RETRIEVAL_QUERY'),
        );

        return $this->checkVector($response['embedding']['values'] ?? null);
    }

    /**
     * @param  list<array{role: 'user'|'model', text: string}>  $contents  Oldest first; must end with a user turn.
     */
    public function generate(string $systemInstruction, array $contents, float $temperature = 0.3): string
    {
        $models = array_values(array_unique([$this->chatModel, ...$this->fallbackModels]));
        $deadline = microtime(true) + $this->chatBudget;
        $lastError = null;

        foreach ($models as $i => $model) {
            $remaining = (int) floor($deadline - microtime(true));

            if ($i > 0 && $remaining < 5) {
                break; // Not enough time left for another model to answer.
            }

            try {
                $response = $this->postOnce("models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents' => array_map(fn (array $c) => [
                        'role' => $c['role'],
                        'parts' => [['text' => $c['text']]],
                    ], $contents),
                    'generationConfig' => $this->generationConfig($model, $temperature),
                ], max(5, min($this->timeout, $remaining)));
            } catch (ConnectionException $e) {
                $lastError = new RuntimeException("Gemini {$model} timed out or was unreachable: {$e->getMessage()}", 0, $e);
                $this->logFallback($model, $models[$i + 1] ?? null, 'timeout');

                continue;
            }

            if ($response->failed()) {
                $lastError = new RuntimeException("Gemini models/{$model}:generateContent failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));

                if (in_array($response->status(), self::FALLBACK_STATUSES, true)) {
                    $this->logFallback($model, $models[$i + 1] ?? null, (string) $response->status());

                    continue;
                }

                throw $lastError; // 400/401/403: a setup problem another model won't fix.
            }

            return $this->extractText($response->json() ?? []);
        }

        throw $lastError ?? new RuntimeException('No Gemini chat model was tried.');
    }

    protected function extractText(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = trim(implode('', array_map(fn ($p) => $p['text'] ?? '', $parts)));

        if ($text === '') {
            $reason = $response['candidates'][0]['finishReason'] ?? ($response['promptFeedback']['blockReason'] ?? 'unknown');
            throw new RuntimeException("Gemini returned no text (reason: {$reason}).");
        }

        return $text;
    }

    protected function logFallback(string $model, ?string $next, string $why): void
    {
        Log::warning('Gemini chat model unavailable'.($next ? ', trying fallback.' : '; no fallback left.'), [
            'model' => $model, 'reason' => $why, 'next' => $next,
        ]);
    }

    /* ── Internals ────────────────────────────────────────────────── */

    protected function generationConfig(string $model, float $temperature): array
    {
        $config = ['temperature' => $temperature];

        // thinkingLevel only exists on Gemini 3.x models; older ones reject it.
        if ($this->thinkingLevel !== null && str_starts_with($model, 'gemini-3')) {
            $config['thinkingConfig'] = ['thinkingLevel' => $this->thinkingLevel];
        }

        return $config;
    }

    protected function embedRequest(string $text, string $taskType): array
    {
        return [
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => $this->dimensions,
        ];
    }

    protected function checkVector(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            throw new RuntimeException('Gemini returned an empty embedding.');
        }

        if (count($values) !== $this->dimensions) {
            throw new RuntimeException('Gemini returned a '.count($values)."-dimension vector; expected {$this->dimensions}.");
        }

        return array_map('floatval', $values);
    }

    protected function client(?int $timeout = null): PendingRequest
    {
        if ($this->key === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withOptions(TlsOptions::forHttpClient())
            ->withHeaders(['x-goog-api-key' => $this->key])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($timeout ?? $this->timeout);
    }

    /** One chat attempt, no retry: on overload generate() moves to another model instead. */
    protected function postOnce(string $path, array $body, int $timeout): Response
    {
        $started = microtime(true);

        try {
            return $this->client($timeout)->post($path, $body);
        } finally {
            SlowCallLog::record('Gemini', $path, $started);
        }
    }

    protected function post(string $path, array $body): array
    {
        $started = microtime(true);
        $response = $this->client()
            // Embeddings: rate limits and brief outages get one more try before giving up.
            ->retry(2, 1000, fn ($e) => $e instanceof RequestException
                && in_array($e->response->status(), [429, 500, 502, 503, 504], true), throw: false)
            ->post($path, $body);
        SlowCallLog::record('Gemini', $path, $started);

        if ($response->failed()) {
            throw new RuntimeException("Gemini {$path} failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }

        return $response->json() ?? [];
    }
}
