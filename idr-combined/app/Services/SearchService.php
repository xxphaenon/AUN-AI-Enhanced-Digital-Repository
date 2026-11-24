<?php

namespace App\Services;

use App\Contracts\RetrievalService;
use App\Enums\DocumentType;
use App\Models\Artifact;
use App\Support\MatchScore;
use App\Support\RetrievedChunk;
use App\Support\Snippet;

/**
 * Backs GET /api/search.
 *
 * Asks the RetrievalService (Member 2) for raw chunk hits, groups them by
 * document, keeps only documents that exist in MongoDB and may be shown,
 * and shapes each into the contract's result object.
 *
 * Normal searches only return APPROVED documents, re-checked in MongoDB even
 * if the vector store's is_approved filter is stale. $adminMode (admins only,
 * GET /api/admin/search) returns every embedded document whatever its status.
 */
class SearchService
{
    public function __construct(private readonly RetrievalService $retrieval) {}

    /**
     * @return list<array{artifactId: string, title: string, author: ?string, courseCode: ?string, academicYear: ?string, documentType: ?string, documentTypeLabel: ?string, relevanceScore: float, matchPercent: int, relevantSnippets: list<string>}>
     */
    public function search(string $query, int $limit, bool $adminMode = false, ?DocumentType $type = null): array
    {
        // A type filter becomes an artifactIds filter on the vector search, so
        // Qdrant only ranks chunks from documents of that type.
        $onlyIds = [];
        if ($type !== null) {
            $onlyIds = Artifact::query()
                ->when($adminMode, fn ($q) => $q->embedded(), fn ($q) => $q->visibleToPublic())
                ->ofType($type)
                ->get(['_id'])
                ->map(fn (Artifact $a) => (string) $a->id)
                ->all();

            if ($onlyIds === []) {
                return [];
            }
        }

        $chunks = $this->retrieval->retrieve(
            $query,
            $limit * (int) config('idr.search.chunk_overfetch', 4),
            $onlyIds,
            $adminMode,
        );

        $minScore = (float) config('idr.search.min_score', 0.0);
        $maxSnippets = (int) config('idr.search.max_snippets_per_result', 3);

        /** @var array<string, array{score: float, snippets: list<string>}> $grouped */
        $grouped = [];

        foreach ($chunks as $chunk) {
            /** @var RetrievedChunk $chunk */
            $id = $chunk->artifactId;
            $grouped[$id] ??= ['score' => 0.0, 'snippets' => []];
            $grouped[$id]['score'] = max($grouped[$id]['score'], $chunk->score);

            // The matching sentences of the chunk, not its first 300 characters
            // (which are often the title page or half a sentence).
            $snippet = Snippet::make($chunk->text, $query);
            if ($snippet !== ''
                && count($grouped[$id]['snippets']) < $maxSnippets
                && ! in_array($snippet, $grouped[$id]['snippets'], true)) {
                $grouped[$id]['snippets'][] = $snippet;
            }
        }

        $grouped = array_filter($grouped, fn (array $g) => $g['score'] >= $minScore);

        if ($grouped === []) {
            return [];
        }

        // Join to MongoDB: drops hits for deleted, not-yet-embedded and (for
        // normal searches) unapproved artifacts.
        $artifacts = Artifact::query()
            ->when($adminMode, fn ($q) => $q->embedded(), fn ($q) => $q->visibleToPublic())
            ->ofType($type)
            ->whereIn('_id', array_map('strval', array_keys($grouped)))
            ->get(['_id', 'title', 'author', 'course_code', 'academic_year', 'document_type', 'status'])
            ->keyBy(fn (Artifact $a) => (string) $a->id);

        $results = [];

        foreach ($grouped as $artifactId => $group) {
            $artifact = $artifacts->get((string) $artifactId);

            if ($artifact === null) {
                continue;
            }

            $result = [
                'artifactId' => (string) $artifactId,
                'title' => $artifact->title,
                'author' => $artifact->author,
                'courseCode' => $artifact->course_code,
                'academicYear' => $artifact->academic_year,
                'documentType' => $artifact->document_type?->value,
                'documentTypeLabel' => $artifact->document_type?->label(),
                // Raw cosine similarity (kept for ranking and tuning) and the
                // calibrated 0-100 figure the UI shows as "% match".
                'relevanceScore' => round($group['score'], 4),
                'matchPercent' => MatchScore::percent($group['score']),
                'relevantSnippets' => $group['snippets'],
            ];

            if ($adminMode) {
                $result['reviewStatus'] = $artifact->status->value;
            }

            $results[] = $result;
        }

        usort($results, fn (array $a, array $b) => $b['relevanceScore'] <=> $a['relevanceScore']);

        return array_slice($results, 0, $limit);
    }
}
