<?php

namespace App\Services\Ai;

use App\Contracts\RetrievalService;
use App\Support\RetrievedChunk;

/**
 * Live RetrievalService: embeds the query with Gemini and searches Qdrant.
 *
 * Students and guests: filtered to is_approved = true inside Qdrant.
 * $adminMode = true: no approval filter (pending, approved and rejected).
 * The backend re-checks every hit against MongoDB either way.
 */
class QdrantRetrievalService implements RetrievalService
{
    public function __construct(
        protected GeminiClient $gemini,
        protected QdrantClient $qdrant,
    ) {}

    public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
    {
        if (trim($query) === '' || $limit < 1) {
            return [];
        }

        $hits = $this->qdrant->query(
            $this->gemini->embedQuery($query),
            $limit,
            self::buildFilter($adminMode, $artifactIds),
        );

        $chunks = [];
        foreach ($hits as $hit) {
            $payload = $hit['payload'] ?? [];

            if (! isset($payload['artifactId'])) {
                continue;
            }

            $chunks[] = new RetrievedChunk(
                artifactId: (string) $payload['artifactId'],
                text: (string) ($payload['text'] ?? ''),
                score: (float) ($hit['score'] ?? 0),
                chunkIndex: isset($payload['chunkIndex']) ? (int) $payload['chunkIndex'] : null,
            );
        }

        usort($chunks, fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score);

        return $chunks;
    }

    /** The visibility rule, in one place (public for tests). */
    public static function buildFilter(bool $adminMode, array $artifactIds = []): ?array
    {
        $must = [];

        if (! $adminMode) {
            $must[] = ['key' => 'is_approved', 'match' => ['value' => true]];
        }

        $artifactIds = array_values(array_unique(array_map('strval', $artifactIds)));
        if ($artifactIds !== []) {
            $must[] = ['key' => 'artifactId', 'match' => ['any' => $artifactIds]];
        }

        return $must === [] ? null : ['must' => $must];
    }
}
