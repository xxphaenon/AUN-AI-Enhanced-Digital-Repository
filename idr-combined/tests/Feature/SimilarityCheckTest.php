<?php

namespace Tests\Feature;

use App\Contracts\SimilarityChecker;
use App\Contracts\SubmissionScreener;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Services\Ai\QdrantClient;
use App\Services\Ai\QdrantSimilarityChecker;
use App\Support\ScreeningResult;
use App\Support\SimilarityResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Before a declared submission is published automatically, its sections are
 * compared with every other project. If 30%+ of it closely matches one earlier
 * project it stays pending, flagged for an admin with the matching project.
 */
class SimilarityCheckTest extends TestCase
{
    public int $screenerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $test = $this;
        $this->app->instance(SubmissionScreener::class, new class($test) implements SubmissionScreener
        {
            public function __construct(private SimilarityCheckTest $test) {}

            public function screen(Artifact $artifact): ScreeningResult
            {
                $this->test->screenerCalls++;

                return new ScreeningResult(ScreeningResult::ACCEPT, 'Looks like a capstone report.');
            }
        });
    }

    private function similarity(SimilarityResult|\Throwable $result): void
    {
        $this->app->instance(SimilarityChecker::class, new class($result) implements SimilarityChecker
        {
            public function __construct(private SimilarityResult|\Throwable $result) {}

            public function check(Artifact $artifact): SimilarityResult
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    private function processDeclaredSubmission(): Artifact
    {
        $artifact = Artifact::factory()->pending()->declared()->create();
        $this->storeFileFor($artifact);
        app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);

        return $artifact->fresh();
    }

    private function match(string $id, float $overlap, int $sections): array
    {
        return ['artifactId' => $id, 'title' => 'Smart Irrigation Using IoT Sensors', 'academicYear' => '2024/2025', 'overlap' => $overlap, 'matchedSections' => $sections];
    }

    public function test_a_submission_too_similar_to_an_earlier_project_stays_pending_and_is_flagged(): void
    {
        $earlier = Artifact::factory()->create(['title' => 'Smart Irrigation Using IoT Sensors']);
        $this->similarity(new SimilarityResult(10, [$this->match((string) $earlier->id, 0.4, 4)]));

        $artifact = $this->processDeclaredSubmission();

        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertTrue($artifact->similarity_flagged);
        $this->assertSame(0.4, $artifact->similarity_score);
        $this->assertSame(0, $this->screenerCalls, 'no Gemini check needed once it is held anyway');

        $this->actingAsAdmin();
        $this->getJson('/api/admin/documents?view=flagged')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.similarity.flagged', true)
            ->assertJsonPath('data.0.similarity.matches.0.artifactId', (string) $earlier->id)
            ->assertJsonPath('data.0.similarity.matches.0.title', 'Smart Irrigation Using IoT Sensors');
    }

    public function test_a_low_overlap_is_recorded_and_the_submission_is_published(): void
    {
        $this->similarity(new SimilarityResult(10, [$this->match('66f0000000000000000000a1', 0.1, 1)]));

        $artifact = $this->processDeclaredSubmission();

        $this->assertSame(ReviewStatus::Approved, $artifact->status);
        $this->assertFalse($artifact->similarity_flagged);
        $this->assertSame(0.1, $artifact->similarity_score);
        $this->assertSame(1, $this->screenerCalls);
    }

    public function test_the_threshold_is_a_setting(): void
    {
        config(['idr.similarity.flag_overlap' => 0.5]);
        $this->similarity(new SimilarityResult(10, [$this->match('66f0000000000000000000a1', 0.4, 4)]));

        $this->assertSame(ReviewStatus::Approved, $this->processDeclaredSubmission()->status);
    }

    public function test_if_the_check_cannot_run_the_submission_waits_for_an_admin(): void
    {
        $this->similarity(new RuntimeException('Qdrant timed out'));

        $artifact = $this->processDeclaredSubmission();

        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertTrue($artifact->similarity_flagged);
        $this->assertStringContainsString('Qdrant timed out', $artifact->similarity_error);
    }

    public function test_the_check_can_be_switched_off(): void
    {
        config(['idr.similarity.enabled' => false]);
        $this->similarity(new RuntimeException('should not be called'));

        $artifact = $this->processDeclaredSubmission();

        $this->assertSame(ReviewStatus::Approved, $artifact->status);
        $this->assertNull($artifact->similarity_checked_at);
    }

    /* ── The live Qdrant comparison, with Qdrant faked ─────────────── */

    public function test_qdrant_checker_counts_closely_matching_sections_per_earlier_project(): void
    {
        config(['services.qdrant.url' => 'https://qdrant.test', 'services.qdrant.key' => 'k', 'services.qdrant.collection' => 'idr_chunks']);
        $this->app->forgetInstance(QdrantClient::class);
        $artifact = Artifact::factory()->pending()->declared()->create();
        $id = (string) $artifact->id;

        $hit = fn (string $other, float $score) => ['id' => uniqid(), 'score' => $score, 'payload' => ['artifactId' => $other, 'title' => "Project {$other}", 'academic_year' => '2024/2025']];

        Http::preventStrayRequests();
        Http::fake([
            '*/points/scroll*' => Http::response(['result' => ['points' => array_map(
                fn ($i) => ['id' => "p{$i}", 'payload' => ['chunkIndex' => $i], 'vector' => ['content' => [0.1 * $i, 0.2, 0.3]]],
                range(0, 3),
            ), 'next_page_offset' => null]]),
            '*/points/query/batch*' => Http::response(['result' => [
                ['points' => [$hit('A', 0.97), $hit('B', 0.95)]],
                ['points' => [$hit('A', 0.96)]],
                ['points' => [$hit('B', 0.80)]], // same topic only: below the threshold
                ['points' => []],
            ]]),
        ]);

        $result = $this->app->make(QdrantSimilarityChecker::class)->check($artifact);

        $this->assertSame(4, $result->sectionsChecked);
        $this->assertSame('A', $result->topMatch()['artifactId']);
        $this->assertSame(0.5, $result->score());          // 2 of 4 sections match project A
        $this->assertSame(0.25, $result->matches[1]['overlap']); // 1 of 4 match project B
        $this->assertSame('Project A', $result->topMatch()['title']);

        Http::assertSent(function (Request $r) use ($id) {
            if (! str_contains($r->url(), '/points/query/batch')) {
                return false;
            }
            $search = $r['searches'][0];

            return count($r['searches']) === 4
                && $search['filter']['must_not'][0]['match']['value'] === $id // never compared with itself
                && $search['score_threshold'] === 0.92
                && $search['using'] === 'content';
        });
    }

    public function test_title_pages_and_reference_lists_are_not_compared(): void
    {
        config(['services.qdrant.url' => 'https://qdrant.test', 'services.qdrant.key' => 'k', 'services.qdrant.collection' => 'idr_chunks']);
        $this->app->forgetInstance(QdrantClient::class);
        $artifact = Artifact::factory()->pending()->declared()->create();

        $prose = str_repeat('The controller opened the valve when the soil was dry. ', 40);
        $texts = [
            0 => 'AMERICAN UNIVERSITY OF NIGERIA '.str_repeat('CERTIFICATION This is to certify that this project was carried out by the student. ', 30),
            1 => 'Abstract '.$prose,
            2 => $prose,
            3 => 'References '.str_repeat('Okafor, A. B. (2019). Irrigation in the North East. Journal of Agriculture, 12(3), 45-67. ', 30),
        ];
        $hit = fn (string $other) => ['id' => uniqid(), 'score' => 0.97, 'payload' => ['artifactId' => $other, 'title' => "Project {$other}"]];

        Http::preventStrayRequests();
        Http::fake([
            '*/points/scroll*' => Http::response(['result' => ['points' => array_map(
                fn ($i) => ['id' => "p{$i}", 'payload' => ['chunkIndex' => $i, 'text' => $texts[$i]], 'vector' => ['content' => [0.1 * $i, 0.2, 0.3]]],
                array_keys($texts),
            ), 'next_page_offset' => null]]),
            // Only sections 1 and 2 are searched: one closely matches project A.
            '*/points/query/batch*' => Http::response(['result' => [['points' => [$hit('A')]], ['points' => []]]]),
        ]);

        $result = $this->app->make(QdrantSimilarityChecker::class)->check($artifact);

        $this->assertSame(2, $result->sectionsChecked);   // title page and references left out
        $this->assertSame(0.5, $result->score());          // 1 of the 2 real sections
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/points/query/batch') && count($r['searches']) === 2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/points/scroll') && in_array('text', $r['with_payload'], true));
    }
}
