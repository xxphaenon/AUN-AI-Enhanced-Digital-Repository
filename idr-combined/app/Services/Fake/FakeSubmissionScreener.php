<?php

namespace App\Services\Fake;

use App\Contracts\SubmissionScreener;
use App\Models\Artifact;
use App\Support\ScreeningResult;

/** Stand-in for the Gemini check (IDR_AI_DRIVER=fake): accepts everything. */
class FakeSubmissionScreener implements SubmissionScreener
{
    public function screen(Artifact $artifact): ScreeningResult
    {
        return new ScreeningResult(ScreeningResult::ACCEPT, 'Not checked: the AI driver is set to "fake".');
    }
}
