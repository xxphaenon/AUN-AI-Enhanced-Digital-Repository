<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_login_returns_token_for_correct_credentials(): void
    {
        $user = User::factory()->create(['email' => 'tunde@aun.edu.ng', 'password' => 'Secret123']);

        $response = $this->postJson('/api/auth/login', ['email' => 'Tunde@AUN.edu.ng', 'password' => 'Secret123']);

        $response->assertOk()
            ->assertJsonPath('data.user.id', (string) $user->id)
            ->assertJsonPath('data.user.role', 'student');

        $this->assertSame(1, PersonalAccessToken::count());
        // Only a hash of the token is stored.
        $plain = explode('|', $response->json('data.token'), 2)[1];
        $this->assertSame(hash('sha256', $plain), PersonalAccessToken::first()->token);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'tunde@aun.edu.ng', 'password' => 'Secret123']);

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'tunde@aun.edu.ng', 'password' => 'Nope12345']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'ghost@aun.edu.ng', 'password' => 'Nope12345']);

        $wrongPassword->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $unknownEmail->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_is_throttled_after_repeated_attempts(): void
    {
        config(['idr.auth.login_attempts_per_minute' => 3]);
        User::factory()->create(['email' => 'tunde@aun.edu.ng', 'password' => 'Secret123']);

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/auth/login', ['email' => 'tunde@aun.edu.ng', 'password' => 'Wrong1234'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'tunde@aun.edu.ng', 'password' => 'Secret123'])
            ->assertStatus(429)
            ->assertJsonPath('status', 'error');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated. Please log in.']);
    }

    public function test_garbage_and_forged_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $real = $user->createToken('web')->plainTextToken;
        [$id] = explode('|', $real, 2);

        $this->withToken('nonsense')->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($id.'|idr_forgedforgedforgedforged')->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken('not-an-id|idr_x')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $laptop = $user->createToken('laptop', ['*'], now()->addDay())->plainTextToken;
        $phone = $user->createToken('phone', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($laptop)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(1, PersonalAccessToken::count());
        // Laravel caches the resolved user per test request cycle; reset it.
        $this->app['auth']->forgetGuards();

        $this->withToken($laptop)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/auth/me')->assertOk();
    }

    public function test_token_use_is_tracked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->assertNotNull(PersonalAccessToken::first()->last_used_at);
    }
}
