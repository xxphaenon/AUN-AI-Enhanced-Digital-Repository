<?php

namespace App\Support;

/** What the AI check decided about one submission, and why. */
final class ScreeningResult
{
    public const ACCEPT = 'accept';

    public const REVIEW = 'review';

    public function __construct(
        public readonly string $verdict,
        public readonly string $reason,
    ) {}

    public function accepted(): bool
    {
        return $this->verdict === self::ACCEPT;
    }
}
