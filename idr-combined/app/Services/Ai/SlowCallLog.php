<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Logs AI calls that take noticeably long, so "the site feels slow" can be
 * traced to Gemini or Qdrant in storage/logs/laravel.log.
 */
final class SlowCallLog
{
    public static function record(string $service, string $path, float $startedAt, int $thresholdMs = 3000): void
    {
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        if ($ms >= $thresholdMs) {
            Log::warning("Slow {$service} call", ['path' => $path, 'ms' => $ms]);
        }
    }
}
