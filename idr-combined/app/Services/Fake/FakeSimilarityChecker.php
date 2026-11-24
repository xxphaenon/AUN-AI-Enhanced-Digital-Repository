<?php

namespace App\Services\Fake;

use App\Contracts\SimilarityChecker;
use App\Models\Artifact;
use App\Support\SimilarityResult;

/** Stand-in for the Qdrant comparison (IDR_AI_DRIVER=fake): finds no similar projects. */
class FakeSimilarityChecker implements SimilarityChecker
{
    public function check(Artifact $artifact): SimilarityResult
    {
        return new SimilarityResult(sectionsChecked: 0);
    }
}
