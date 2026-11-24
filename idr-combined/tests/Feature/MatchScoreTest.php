<?php

namespace Tests\Feature;

use App\Contracts\RetrievalService;
use App\Models\Artifact;
use App\Support\MatchScore;
use App\Support\RetrievedChunk;
use Tests\TestCase;

/**
 * Search shows a calibrated "% match": raw cosine similarity is squeezed into
 * ~0.5-0.85, so it is rescaled with config('idr.search.match_scale').
 */
class MatchScoreTest extends TestCase
{
    private const SCALE = [[0.50, 0], [0.60, 35], [0.70, 70], [0.80, 92], [0.88, 100]];

    public function test_raw_scores_are_rescaled_between_the_points(): void
    {
        $this->assertSame(0, MatchScore::percent(0.3, self::SCALE));
        $this->assertSame(0, MatchScore::percent(0.5, self::SCALE));
        $this->assertSame(35, MatchScore::percent(0.6, self::SCALE));
        $this->assertSame(53, MatchScore::percent(0.65, self::SCALE));
        $this->assertSame(88, MatchScore::percent(0.78, self::SCALE));
        $this->assertSame(100, MatchScore::percent(0.88, self::SCALE));
        $this->assertSame(100, MatchScore::percent(0.97, self::SCALE));
    }

    public function test_higher_raw_scores_never_show_a_lower_percent(): void
    {
        $previous = -1;
        for ($raw = 0.0; $raw <= 1.0; $raw += 0.01) {
            $percent = MatchScore::percent($raw, self::SCALE);
            $this->assertGreaterThanOrEqual($previous, $percent);
            $previous = $percent;
        }
    }

    public function test_search_results_carry_both_scores_and_weak_hits_are_hidden(): void
    {
        config(['idr.search.match_scale' => self::SCALE, 'idr.search.min_score' => 0.55]);

        $strong = Artifact::factory()->embedded()->create(['title' => 'Smart irrigation']);
        $weak = Artifact::factory()->embedded()->create(['title' => 'Unrelated']);

        $this->app->instance(RetrievalService::class, new class([
            new RetrievedChunk((string) $strong->id, 'LoRaWAN drip irrigation', 0.78),
            new RetrievedChunk((string) $weak->id, 'something else', 0.52),
        ]) implements RetrievalService
        {
            public function __construct(private array $chunks) {}

            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                return $this->chunks;
            }
        });

        $this->getJson('/api/search?q=lorawan+irrigation')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.artifactId', (string) $strong->id)
            ->assertJsonPath('results.0.relevanceScore', 0.78)
            ->assertJsonPath('results.0.matchPercent', 88);
    }
}
