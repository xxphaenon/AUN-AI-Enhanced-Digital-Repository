<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    public function test_admin_lists_and_filters_users(): void
    {
        $this->actingAsAdmin(User::factory()->admin()->create(['name' => 'Admin One']));
        User::factory()->create(['name' => 'Kemi Ade', 'email' => 'kemi@aun.edu.ng']);
        User::factory()->create(['name' => 'Musa Bello']);

        $this->getJson('/api/admin/users?role=student')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/users?q=kemi')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'kemi@aun.edu.ng');
        $this->getJson('/api/admin/users?role=admin')->assertJsonPath('data.0.name', 'Admin One');
    }

    public function test_admin_promotes_and_demotes(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        $this->patchJson("/api/admin/users/{$user->id}/role", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'admin');
        $this->assertTrue($user->refresh()->isAdmin());

        $this->patchJson("/api/admin/users/{$user->id}/role", ['role' => 'student'])->assertOk();
        $this->assertFalse($user->refresh()->isAdmin());
    }

    public function test_admin_cannot_change_own_role_or_set_unknown_roles(): void
    {
        $admin = $this->actingAsAdmin();
        $user = User::factory()->create();

        $this->patchJson("/api/admin/users/{$admin->id}/role", ['role' => 'student'])->assertForbidden();
        $this->patchJson("/api/admin/users/{$user->id}/role", ['role' => 'superadmin'])->assertStatus(422);
        $this->patchJson('/api/admin/users/66f0000000000000000000ff/role', ['role' => 'admin'])->assertNotFound();
    }
}
