<?php

namespace App\Contracts;

use App\Models\Artifact;
use App\Support\SimilarityResult;

/**
 * Compares a newly processed submission with every other project already in
 * the repository and reports how much of it closely matches each one.
 *
 * Runs in the ingestion job for declared student submissions, after the file
 * was embedded and before it can be published automatically. A high overlap
 * keeps the submission pending for an admin (see DocumentService).
 */
interface SimilarityChecker
{
    public function check(Artifact $artifact): SimilarityResult;
}
