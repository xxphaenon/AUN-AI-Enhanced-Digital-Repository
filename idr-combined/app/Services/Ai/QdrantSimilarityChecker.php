<?php

namespace App\Services\Ai;

use App\Contracts\SimilarityChecker;
use App\Models\Artifact;
use App\Support\BoilerplateSections;
use App\Support\SimilarityResult;

/**
 * Live SimilarityChecker. Reuses the vectors already stored in Qdrant for
 * search: each ~500-word section of the new submission is compared with the
 * sections of every OTHER document. A section "closely matches" a project when
 * one of that project's sections scores at least idr.similarity.section_score
 * (cosine). The overlap with a project is the share of the submission's
 * sections that closely match it.
 *
 * Shared topics score well below the section threshold; copied or lightly
 * reworded passages score above it. Front matter (title page, certification…)
 * and reference lists are left out of the comparison and of the share: every
 * thesis has them and they look alike (App\Support\BoilerplateSections).
 * No Gemini calls are made.
 */
class QdrantSimilarityChecker implements SimilarityChecker
{
    public function __construct(protected QdrantClient $qdrant) {}

    public function check(Artifact $artifact): SimilarityResult
    {
        $id = (string) $artifact->id;
        $sections = $this->qdrant->sectionsForArtifact($id);
        $boilerplate = BoilerplateSections::find(array_map(fn (array $s) => $s['text'], $sections));
        $vectors = array_map(fn (array $s) => $s['vector'], array_diff_key($sections, $boilerplate));

        if ($vectors === []) {
            return new SimilarityResult(sectionsChecked: 0);
        }

        $threshold = (float) config('idr.similarity.section_score', 0.92);
        $neighbours = max(1, (int) config('idr.similarity.neighbours', 5));
        $notSelf = ['must_not' => [['key' => 'artifactId', 'match' => ['value' => $id]]]];

        $hits = $this->qdrant->queryBatch(array_values($vectors), $neighbours, $notSelf, $threshold);

        // artifactId => [set of this submission's section numbers it matched, payload]
        $matched = [];
        foreach ($hits as $sectionNo => $points) {
            foreach ($points as $point) {
                $other = (string) ($point['payload']['artifactId'] ?? '');
                if ($other === '' || $other === $id || (float) ($point['score'] ?? 0) < $threshold) {
                    continue;
                }
                $matched[$other]['sections'][$sectionNo] = true;
                $matched[$other]['payload'] ??= $point['payload'];
            }
        }

        $total = count($vectors);
        $matches = [];
        foreach ($matched as $other => $data) {
            $count = count($data['sections']);
            $matches[] = [
                'artifactId' => $other,
                'title' => $data['payload']['title'] ?? null,
                'academicYear' => $data['payload']['academic_year'] ?? null,
                'overlap' => round($count / $total, 4),
                'matchedSections' => $count,
            ];
        }

        usort($matches, fn ($a, $b) => $b['overlap'] <=> $a['overlap']);

        return new SimilarityResult($total, array_slice($matches, 0, 3));
    }
}
