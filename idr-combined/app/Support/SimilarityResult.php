<?php

namespace App\Support;

/**
 * How much of one submission closely matches earlier projects.
 *
 * $matches is sorted by overlap, highest first; each entry is
 * ['artifactId' => string, 'title' => ?string, 'academicYear' => ?string,
 *  'overlap' => float 0–1 (share of the submission's sections that closely
 *  match that project), 'matchedSections' => int].
 */
final class SimilarityResult
{
    /** @param  list<array{artifactId: string, title: ?string, academicYear: ?string, overlap: float, matchedSections: int}>  $matches */
    public function __construct(
        public readonly int $sectionsChecked,
        public readonly array $matches = [],
    ) {}

    /** Highest overlap with any single earlier project (0–1). */
    public function score(): float
    {
        return $this->matches[0]['overlap'] ?? 0.0;
    }

    public function topMatch(): ?array
    {
        return $this->matches[0] ?? null;
    }
}
