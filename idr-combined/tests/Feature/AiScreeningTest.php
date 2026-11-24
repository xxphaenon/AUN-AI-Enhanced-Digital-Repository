<?php

namespace Tests\Feature;

use App\Contracts\SubmissionScreener;
use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessArtifactIngestion;
use App\Models\Artifact;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiSubmissionScreener;
use App\Support\ScreeningResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Before a declared submission is published automatically, an AI check asks
 * "does this look like a senior design / research project?". Only a clear yes
 * goes live; everything else waits for an admin, who can find it under
 * "Flagged by AI check". Admins can also spot-check "Recently published".
 */
class AiScreeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function screener(string $verdict, string $reason = 'Looks like a capstone report.'): void
    {
        $this->app->instance(SubmissionScreener::class, new class($verdict, $reason) implements SubmissionScreener
        {
            public int $calls = 0;

            public function __construct(private string $verdict, private string $reason) {}

            public function screen(Artifact $artifact): ScreeningResult
            {
                $this->calls++;
                if ($this->verdict === 'throw') {
                    throw new RuntimeException('Gemini 503: model overloaded');
                }

                return new ScreeningResult($this->verdict, $this->reason);
            }
        });
    }

    private function declaredSubmission(): Artifact
    {
        $artifact = Artifact::factory()->pending()->declared()->create();
        $this->storeFileFor($artifact);
        app()->call([new ProcessArtifactIngestion((string) $artifact->id), 'handle']);

        return $artifact->fresh();
    }

    public function test_accepted_submission_is_published_and_the_check_is_recorded(): void
    {
        $this->screener('accept');

        $artifact = $this->declaredSubmission();

        $this->assertSame(ReviewStatus::Approved, $artifact->status);
        $this->assertSame('accept', $artifact->ai_screening_verdict);
        $this->assertSame('Looks like a capstone report.', $artifact->ai_screening_reason);
        $this->assertNotNull($artifact->ai_screened_at);
    }

    public function test_doubtful_submission_stays_pending_and_is_flagged_for_admins(): void
    {
        $this->screener('review', 'This looks like lecture notes, not a project report.');

        $artifact = $this->declaredSubmission();

        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertFalse((bool) $artifact->auto_approved);
        $this->assertSame('review', $artifact->ai_screening_verdict);
        // Students' searches never see it: Qdrant was told it is not approved.
        $this->assertFalse(end($this->vectorIndex()->calls)[2]['is_approved']);

        Artifact::factory()->pending()->create(); // ordinary pending file, not flagged
        $this->actingAsAdmin();
        $this->getJson('/api/admin/documents?view=flagged')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.artifactId', (string) $artifact->id)
            ->assertJsonPath('data.0.aiScreening.verdict', 'review')
            ->assertJsonPath('data.0.aiScreening.reason', 'This looks like lecture notes, not a project report.');
    }

    public function test_if_the_check_cannot_run_the_submission_waits_for_an_admin(): void
    {
        $this->screener('throw');

        $artifact = $this->declaredSubmission();

        $this->assertSame(ReviewStatus::Pending, $artifact->status);
        $this->assertSame('error', $artifact->ai_screening_verdict);
        $this->assertStringContainsString('Gemini 503', $artifact->ai_screening_reason);
    }

    public function test_the_check_can_be_switched_off(): void
    {
        config(['idr.screening.enabled' => false]);
        $this->screener('throw'); // would fail if it were called

        $this->assertSame(ReviewStatus::Approved, $this->declaredSubmission()->status);
    }

    public function test_recently_published_view_lists_automatic_publications_of_the_last_days(): void
    {
        $recent = Artifact::factory()->declared()->create(['auto_approved' => true, 'reviewed_at' => now()->subDays(2)]);
        Artifact::factory()->declared()->create(['auto_approved' => true, 'reviewed_at' => now()->subDays(30)]); // too old
        Artifact::factory()->create(['reviewed_at' => now()]); // approved by an admin, not automatically
        Artifact::factory()->rejected()->declared()->create(['auto_approved' => true, 'reviewed_at' => now()]); // since rejected
        $this->actingAsAdmin();

        $ids = array_column($this->getJson('/api/admin/documents?view=recent')->assertOk()->json('data'), 'artifactId');

        $this->assertSame([(string) $recent->id], $ids);
    }

    /* ── The live Gemini check, with Gemini faked ─────────────────── */

    private function geminiReplies(string $text): void
    {
        config(['services.gemini.key' => 'test-gemini-key']);
        $this->app->forgetInstance(GeminiClient::class);
        Http::preventStrayRequests();
        Http::fake(['*:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
    }

    private function liveScreen(): ScreeningResult
    {
        $artifact = Artifact::factory()->pending()->declared()->create([
            'title' => 'Smart Irrigation', 'document_type' => DocumentType::Project, 'course_code' => 'CSC 499',
        ]);
        $this->storeFileFor($artifact, file_get_contents(base_path('tests/Fixtures/sample-project.pdf')));

        return $this->app->make(GeminiSubmissionScreener::class)->screen($artifact);
    }

    public function test_gemini_check_sends_the_details_and_opening_text_and_reads_the_verdict(): void
    {
        $this->geminiReplies('{"verdict": "accept", "confidence": 0.92, "reason": "A senior design report on irrigation."}');

        $result = $this->liveScreen();

        $this->assertTrue($result->accepted());
        $this->assertSame('A senior design report on irrigation.', $result->reason);
        Http::assertSent(function (Request $r) {
            $prompt = $r['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($r->url(), ':generateContent')
                && str_contains($prompt, 'Title entered by the student: Smart Irrigation')
                && str_contains($prompt, 'Declared type: Senior Design Project')
                && str_contains($prompt, 'Irrigation');
        });
    }

    public function test_a_low_confidence_accept_goes_to_an_admin(): void
    {
        $this->geminiReplies('```json {"verdict": "accept", "confidence": 0.3, "reason": "Might be a project."} ```');

        $result = $this->liveScreen();

        $this->assertSame('review', $result->verdict);
        $this->assertSame('Low confidence: Might be a project.', $result->reason);
    }

    public function test_an_answer_that_is_not_json_goes_to_an_admin(): void
    {
        $this->geminiReplies('I think this is fine.');

        $this->assertSame('review', $this->liveScreen()->verdict);
    }
}
