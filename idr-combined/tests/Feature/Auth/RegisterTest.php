<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amina Bello',
            'email' => 'amina.bello@aun.edu.ng',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ], $overrides);
    }

    public function test_aun_student_can_register_and_gets_a_working_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.email', 'amina.bello@aun.edu.ng')
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.tokenType', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'expiresAt', 'user' => ['id', 'name', 'email', 'role']]]);

        $this->assertStringContainsString('|idr_', $response->json('data.token'));
        $this->assertArrayNotHasKey('password', $response->json('data.user'));

        // The token actually authenticates.
        $this->withToken($response->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'amina.bello@aun.edu.ng');
    }

    public function test_password_is_stored_hashed(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $stored = User::where('email', 'amina.bello@aun.edu.ng')->first()->getAttributes()['password'];
        $this->assertNotSame('Secret123', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
    }

    public function test_cannot_register_as_admin_by_sending_a_role(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['role' => 'admin']))
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'student');

        $this->assertSame('student', User::where('email', 'amina.bello@aun.edu.ng')->first()->role);
    }

    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['email' => '  Amina.Bello@AUN.EDU.NG ']))
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'amina.bello@aun.edu.ng');
    }

    public static function forbiddenEmails(): array
    {
        return [
            'gmail' => ['amina@gmail.com'],
            'domain as suffix of another domain' => ['amina@aun.edu.ng.evil.com'],
            'subdomain' => ['amina@mail.aun.edu.ng'],
            'look-alike' => ['amina@aun-edu.ng'],
            'missing tld part' => ['amina@aun.edu'],
            'two at signs' => ['amina@evil.com@aun.edu.ng'],
            'no local part' => ['@aun.edu.ng'],
            'not an email' => ['aun.edu.ng'],
        ];
    }

    #[DataProvider('forbiddenEmails')]
    public function test_only_aun_edu_ng_addresses_can_register(string $email): void
    {
        $this->postJson('/api/auth/register', $this->payload(['email' => $email]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, User::count());
    }

    public function test_domain_error_message_is_clear(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['email' => 'amina@gmail.com']))
            ->assertJsonPath('errors.email.0', 'Only @aun.edu.ng email addresses can be used to create an account.');
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'amina.bello@aun.edu.ng']);

        $this->postJson('/api/auth/register', $this->payload(['email' => 'AMINA.BELLO@aun.edu.ng']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_weak_or_unconfirmed_passwords_are_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/auth/register', $this->payload(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/auth/register', $this->payload(['password_confirmation' => 'Different123']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_name_is_required(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }
}
