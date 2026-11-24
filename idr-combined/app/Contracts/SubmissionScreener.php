<?php

namespace App\Contracts;

use App\Models\Artifact;
use App\Support\ScreeningResult;

/**
 * The AI check that runs before a declared student submission is published
 * automatically: does this file look like a senior design or senior research
 * project? "accept" lets it go live; "review" keeps it pending for an admin.
 *
 * Called by DocumentService::autoApproveIfEligible() from the ingestion job,
 * after the file was embedded. Throwing is fine: the submission then stays
 * pending with the error recorded, so a Gemini outage never publishes unchecked work.
 */
interface SubmissionScreener
{
    public function screen(Artifact $artifact): ScreeningResult;
}
