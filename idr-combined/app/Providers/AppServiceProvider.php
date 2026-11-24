<?php

namespace App\Providers;

use App\Contracts\ChatResponder;
use App\Contracts\DocumentIngestor;
use App\Contracts\RetrievalService;
use App\Contracts\SimilarityChecker;
use App\Contracts\SubmissionScreener;
use App\Contracts\VectorIndex;
use App\Models\PersonalAccessToken;
use App\Services\Fake\FakeChatResponder;
use App\Services\Fake\FakeDocumentIngestor;
use App\Services\Fake\FakeRetrievalService;
use App\Services\Fake\FakeSimilarityChecker;
use App\Services\Fake\FakeSubmissionScreener;
use App\Services\Fake\FakeVectorIndex;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /** Fallback implementations used when IDR_AI_DRIVER=fake. */
    private const FAKE_BINDINGS = [
        DocumentIngestor::class => FakeDocumentIngestor::class,
        RetrievalService::class => FakeRetrievalService::class,
        ChatResponder::class => FakeChatResponder::class,
        VectorIndex::class => FakeVectorIndex::class,
        SubmissionScreener::class => FakeSubmissionScreener::class,
        SimilarityChecker::class => FakeSimilarityChecker::class,
    ];

    public function register(): void
    {
        $this->registerAiBindings();
    }

    public function boot(): void
    {
        // Sanctum tokens live in MongoDB (see App\Models\PersonalAccessToken).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureRateLimiting();
    }

    /**
     * Wire Member 2's AI classes (or the fakes) to the contracts that the
     * backend depends on. Switch with IDR_AI_DRIVER=fake|live.
     */
    private function registerAiBindings(): void
    {
        $driver = config('idr.ai_driver', 'fake');

        $bindings = match ($driver) {
            'fake' => self::FAKE_BINDINGS,
            // Any contract Member 2 hasn't finished yet keeps using its fake.
            'live' => array_merge(self::FAKE_BINDINGS, config('idr.ai_bindings.live', [])),
            default => throw new InvalidArgumentException("Unknown IDR_AI_DRIVER [{$driver}]. Use 'fake' or 'live'."),
        };

        foreach ($bindings as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }

    private function configureRateLimiting(): void
    {
        $perUser = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();

        // Brute-force protection: N tries per minute for one email from one IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('idr.auth.login_attempts_per_minute', 5))
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Generous per IP: students on campus Wi-Fi share one public address.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        // Public route: key by account when a token is sent, else by IP.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip()));
        // Each chat turn costs a Gemini call — keep it modest.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));
    }
}
