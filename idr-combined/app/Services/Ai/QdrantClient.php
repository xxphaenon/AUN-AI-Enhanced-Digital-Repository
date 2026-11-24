<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Thin wrapper around the Qdrant REST API: the only class that talks to Qdrant.
 *
 * Every document (Artifact) is stored as many chunk points. Each point's payload is
 * Artifact::vectorMetadata() (artifactId, title, author, course_code, academic_year,
 * status, is_approved) plus chunkIndex and text.
 *
 * Writes use ?wait=true, so a change is visible to the very next search.
 */
class QdrantClient
{
    /** Payload fields that filters run on; Qdrant Cloud needs an index for each. */
    public const PAYLOAD_INDEXES = [
        'artifactId' => 'keyword',
        'is_approved' => 'bool',
        'status' => 'keyword',
        'course_code' => 'keyword',
        'document_type' => 'keyword',
    ];

    protected string $url;

    protected ?string $key;

    protected string $collection;

    protected string $vectorName;

    protected int $timeout;

    public function __construct(?array $config = null)
    {
        $config ??= config('services.qdrant');

        $this->url = rtrim((string) ($config['url'] ?? ''), '/');
        $this->key = $config['key'] ?? null;
        $this->collection = (string) ($config['collection'] ?? 'idr_chunks');
        $this->vectorName = (string) ($config['vector_name'] ?? 'content');
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    public function collection(): string
    {
        return $this->collection;
    }

    /* ── Collection setup ─────────────────────────────────────────── */

    public function collectionExists(): bool
    {
        $response = $this->client()->get("/collections/{$this->collection}/exists");
        $this->throwIfFailed($response, 'check collection');

        return (bool) ($response->json('result.exists') ?? false);
    }

    public function createCollection(int $dimensions): void
    {
        $this->send('put', "/collections/{$this->collection}", [
            'vectors' => [
                $this->vectorName => ['size' => $dimensions, 'distance' => 'Cosine'],
            ],
        ]);
    }

    public function deleteCollection(): void
    {
        $this->send('delete', "/collections/{$this->collection}");
    }

    /** Create the payload indexes filters rely on. Safe to repeat. */
    public function ensurePayloadIndexes(): void
    {
        foreach (self::PAYLOAD_INDEXES as $field => $schema) {
            $this->send('put', "/collections/{$this->collection}/index", [
                'field_name' => $field,
                'field_schema' => $schema,
            ]);
        }
    }

    /* ── Points ───────────────────────────────────────────────────── */

    /**
     * @param  list<array{id: string, vector: list<float>, payload: array}>  $points
     */
    public function upsert(array $points): void
    {
        if ($points === []) {
            return;
        }

        // Keep each request a sensible size (3072 floats per point adds up).
        foreach (array_chunk($points, 64) as $batch) {
            $this->send('put', "/collections/{$this->collection}/points", [
                'points' => array_map(fn (array $p) => [
                    'id' => $p['id'],
                    'vector' => [$this->vectorName => $p['vector']],
                    'payload' => $p['payload'],
                ], $batch),
            ]);
        }
    }

    /**
     * Nearest-neighbour search.
     *
     * @param  array|null  $filter  A Qdrant filter object, e.g. ['must' => [...]]
     * @return list<array{id: string|int, score: float, payload: array}>
     */
    public function query(array $vector, int $limit, ?array $filter = null): array
    {
        $body = [
            'query' => $vector,
            'using' => $this->vectorName,
            'limit' => $limit,
            'with_payload' => true,
        ];

        if ($filter) {
            $body['filter'] = $filter;
        }

        return $this->send('post', "/collections/{$this->collection}/points/query", $body)['result']['points'] ?? [];
    }

    /**
     * Every stored section vector of one document, for the similarity check.
     *
     * @return array<int, list<float>> chunkIndex => vector
     */
    public function vectorsForArtifact(string $artifactId, int $pageSize = 128): array
    {
        return array_map(fn (array $s) => $s['vector'], $this->sectionsForArtifact($artifactId, $pageSize));
    }

    /**
     * Every stored section of one document with its vector and text, in order.
     *
     * @return array<int, array{vector: list<float>, text: string}> chunkIndex => section
     */
    public function sectionsForArtifact(string $artifactId, int $pageSize = 128): array
    {
        $sections = [];
        $offset = null;

        do {
            $body = [
                'filter' => self::artifactFilter($artifactId),
                'limit' => $pageSize,
                'with_payload' => ['chunkIndex', 'text'],
                'with_vector' => [$this->vectorName],
            ];
            if ($offset !== null) {
                $body['offset'] = $offset;
            }

            $result = $this->send('post', "/collections/{$this->collection}/points/scroll", $body)['result'] ?? [];

            foreach ($result['points'] ?? [] as $point) {
                $vector = $point['vector'][$this->vectorName] ?? $point['vector'] ?? null;
                if (is_array($vector) && $vector !== []) {
                    $sections[(int) ($point['payload']['chunkIndex'] ?? count($sections))] = [
                        'vector' => $vector,
                        'text' => (string) ($point['payload']['text'] ?? ''),
                    ];
                }
            }

            $offset = $result['next_page_offset'] ?? null;
        } while ($offset !== null);

        ksort($sections);

        return $sections;
    }

    /**
     * Many nearest-neighbour searches in one request per batch of 32.
     *
     * @param  list<list<float>>  $vectors
     * @return list<list<array{id: string|int, score: float, payload: array}>> one result list per vector, same order
     */
    public function queryBatch(array $vectors, int $limit, ?array $filter = null, ?float $scoreThreshold = null): array
    {
        $results = [];

        foreach (array_chunk($vectors, 32) as $batch) {
            $searches = array_map(fn (array $vector) => array_filter([
                'query' => $vector,
                'using' => $this->vectorName,
                'limit' => $limit,
                'with_payload' => ['artifactId', 'title', 'academic_year'],
                'filter' => $filter,
                'score_threshold' => $scoreThreshold,
            ], fn ($v) => $v !== null), $batch);

            $response = $this->send('post', "/collections/{$this->collection}/points/query/batch", ['searches' => $searches]);

            foreach ($response['result'] ?? [] as $result) {
                $results[] = $result['points'] ?? [];
            }
        }

        return $results;
    }

    /** Overwrite payload keys on every point of one document. No re-embedding. */
    public function setPayloadForArtifact(string $artifactId, array $payload): void
    {
        $this->send('post', "/collections/{$this->collection}/points/payload", [
            'payload' => $payload,
            'filter' => self::artifactFilter($artifactId),
        ]);
    }

    /** Delete every point of one document. A document with no points is a no-op. */
    public function deleteArtifact(string $artifactId): void
    {
        $this->send('post', "/collections/{$this->collection}/points/delete", [
            'filter' => self::artifactFilter($artifactId),
        ]);
    }

    /**
     * Delete specific points by their Qdrant point id(s).
     *
     * @param  string|int|list<string|int>  $pointIds
     */
    public function deletePoints(string|int|array $pointIds): void
    {
        $ids = array_values((array) $pointIds);

        if ($ids === []) {
            return;
        }

        $this->send('post', "/collections/{$this->collection}/points/delete", ['points' => $ids]);
    }

    public function countForArtifact(string $artifactId): int
    {
        $response = $this->send('post', "/collections/{$this->collection}/points/count", [
            'filter' => self::artifactFilter($artifactId),
            'exact' => true,
        ]);

        return (int) ($response['result']['count'] ?? 0);
    }

    /**
     * Every distinct artifactId that has points (used to find orphans).
     *
     * @return list<string>
     */
    public function artifactIds(int $pageSize = 256): array
    {
        $ids = [];
        $offset = null;

        do {
            $body = ['limit' => $pageSize, 'with_payload' => ['artifactId'], 'with_vector' => false];
            if ($offset !== null) {
                $body['offset'] = $offset;
            }

            $result = $this->send('post', "/collections/{$this->collection}/points/scroll", $body)['result'] ?? [];

            foreach ($result['points'] ?? [] as $point) {
                if (isset($point['payload']['artifactId'])) {
                    $ids[(string) $point['payload']['artifactId']] = true;
                }
            }

            $offset = $result['next_page_offset'] ?? null;
        } while ($offset !== null);

        return array_keys($ids);
    }

    /* ── Filters ──────────────────────────────────────────────────── */

    public static function artifactFilter(string $artifactId): array
    {
        if (trim($artifactId) === '') {
            // An empty id must never turn into "update/delete every point".
            throw new InvalidArgumentException('artifactId is required.');
        }

        return ['must' => [['key' => 'artifactId', 'match' => ['value' => $artifactId]]]];
    }

    /* ── HTTP ─────────────────────────────────────────────────────── */

    protected function client(): PendingRequest
    {
        if ($this->url === '') {
            throw new RuntimeException('QDRANT_URL is not configured.');
        }

        return Http::baseUrl($this->url)
            ->withOptions(TlsOptions::forHttpClient())
            ->withHeaders(array_filter(['api-key' => $this->key]))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($this->timeout);
    }

    protected function send(string $method, string $path, ?array $body = null): array
    {
        $url = $path.'?wait=true';
        $started = microtime(true);
        $response = $body === null
            ? $this->client()->{$method}($url)
            : $this->client()->{$method}($url, $body);

        SlowCallLog::record('Qdrant', $path, $started);
        $this->throwIfFailed($response, "{$method} {$path}");

        return $response->json() ?? [];
    }

    protected function throwIfFailed($response, string $what): void
    {
        if ($response->failed()) {
            throw new RuntimeException("Qdrant {$what} failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }
    }
}
