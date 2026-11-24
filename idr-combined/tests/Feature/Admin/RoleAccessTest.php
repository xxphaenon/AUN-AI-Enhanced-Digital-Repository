<?php

namespace Tests\Feature\Admin;

use App\Models\Artifact;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin endpoint: guests get 401, students get 403.
 */
class RoleAccessTest extends TestCase
{
    public static function adminEndpoints(): array
    {
        return [
            'list' => ['GET', '/api/admin/documents'],
            'pending' => ['GET', '/api/admin/documents/pending'],
            'show' => ['GET', '/api/admin/documents/{id}'],
            'preview' => ['GET', '/api/admin/documents/{id}/preview'],
            'status' => ['PATCH', '/api/admin/documents/{id}/status'],
            'edit (patch)' => ['PATCH', '/api/admin/documents/{id}'],
            'edit (put)' => ['PUT', '/api/admin/documents/{id}'],
            'delete' => ['DELETE', '/api/admin/documents/{id}'],
            'reprocess' => ['POST', '/api/admin/documents/{id}/reprocess'],
            'search' => ['GET', '/api/admin/search?q=test'],
            'users' => ['GET', '/api/admin/users'],
            'role' => ['PATCH', '/api/admin/users/{user}/role'],
        ];
    }

    private function url(string $template, Artifact $doc, User $user): string
    {
        return str_replace(['{id}', '{user}'], [(string) $doc->id, (string) $user->id], $template);
    }

    #[DataProvider('adminEndpoints')]
    public function test_guests_get_401(string $method, string $url): void
    {
        $doc = Artifact::factory()->pending()->create();

        $this->json($method, $this->url($url, $doc, User::factory()->create()), ['status' => 'approved', 'title' => 'x', 'role' => 'admin'])
            ->assertUnauthorized();

        $this->assertNotNull(Artifact::find($doc->id));
    }

    #[DataProvider('adminEndpoints')]
    public function test_students_get_403(string $method, string $url): void
    {
        $doc = Artifact::factory()->pending()->create();
        $student = $this->actingAsStudent();

        $this->json($method, $this->url($url, $doc, $student), ['status' => 'approved', 'title' => 'Hacked', 'role' => 'admin'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action requires an administrator account.');

        // Nothing changed.
        $doc->refresh();
        $this->assertSame('pending', $doc->status->value);
        $this->assertNotSame('Hacked', $doc->title);
        $this->assertSame('student', $student->refresh()->role);
    }

    public function test_demoted_admin_loses_access_immediately_even_with_an_old_token(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('web', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/documents')->assertOk();

        $admin->forceFill(['role' => 'student'])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/documents')->assertForbidden();
    }
}
