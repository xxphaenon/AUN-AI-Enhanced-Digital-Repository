<?php

namespace App\Support;

/**
 * Turns the raw cosine similarity of a search hit into the "% match" users see.
 *
 * Raw scores from the embedding model sit in a narrow band: a query and a
 * passage are never identical, so even a perfect hit scores about 0.8, while
 * unrelated text still scores 0.5-0.6. Shown as-is, a strong hit looks like
 * "78%" and junk like "55%". This maps the band onto 0-100 using the points in
 * config('idr.search.match_scale'), interpolating linearly between them.
 *
 * Ranking is never affected: the mapping only rises, so order is preserved.
 */
final class MatchScore
{
    /**
     * @param  list<array{0: float, 1: int|float}>|null  $scale  [raw score, percent] points, raw ascending
     */
    public static function percent(float $raw, ?array $scale = null): int
    {
        $scale ??= config('idr.search.match_scale');
        usort($scale, fn (array $a, array $b) => $a[0] <=> $b[0]);

        [$firstRaw, $firstPct] = $scale[0];
        if ($raw <= $firstRaw) {
            return (int) round($firstPct);
        }

        for ($i = 1, $n = count($scale); $i < $n; $i++) {
            [$hiRaw, $hiPct] = $scale[$i];
            [$loRaw, $loPct] = $scale[$i - 1];

            if ($raw <= $hiRaw) {
                $share = ($raw - $loRaw) / ($hiRaw - $loRaw);

                return (int) round($loPct + $share * ($hiPct - $loPct));
            }
        }

        return (int) round(end($scale)[1]);
    }
}
