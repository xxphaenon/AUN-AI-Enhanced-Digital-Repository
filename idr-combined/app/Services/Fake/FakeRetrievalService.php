<?php

namespace App\Services\Fake;

use App\Contracts\RetrievalService;
use App\Models\Artifact;
use App\Support\RetrievedChunk;
use Illuminate\Support\Str;

/**
 * Stand-in for Member 2's Qdrant vector search (IDR_AI_DRIVER=fake).
 *
 * Scores EMBEDDED artifacts by keyword overlap between the query and the
 * title/author/course code, so search and chat return real repository
 * documents while the AI pipeline is still being built. Honours $adminMode
 * the same way the real implementation must: without it, only approved files.
 */
class FakeRetrievalService implements RetrievalService
{
    private const STOP_WORDS = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'are',
        'how', 'what', 'does', 'do', 'can', 'you', 'me', 'about', 'with', 'by', 'from', 'this', 'that', 'work'];

    public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
    {
        $terms = $this->terms($query);

        $artifacts = Artifact::query()
            ->embedded()
            ->when(! $adminMode, fn ($q) => $q->approved())
            ->when($artifactIds !== [], fn ($q) => $q->whereIn('_id', $artifactIds))
            ->latest()
            ->limit(200)
            ->get(['_id', 'title', 'author', 'course_code']);

        $chunks = [];

        foreach ($artifacts as $artifact) {
            $haystack = $this->terms($artifact->title.' '.$artifact->author.' '.$artifact->course_code);
            $overlap = $terms === [] ? 0 : count(array_intersect($terms, $haystack)) / count($terms);

            // With no keyword match, still surface recent documents with a low
            // score so chat always has *some* context in fake mode.
            $score = $overlap > 0 ? 0.5 + 0.49 * $overlap : 0.12;

            $chunks[] = new RetrievedChunk(
                artifactId: (string) $artifact->id,
                text: "[fake snippet] \"{$artifact->title}\" by {$artifact->author} discusses ".Str::limit($query, 80).'…',
                score: round($score, 4),
                chunkIndex: 0,
            );
        }

        usort($chunks, fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score);

        return array_slice($chunks, 0, $limit);
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_diff($words, self::STOP_WORDS)));
    }
}
