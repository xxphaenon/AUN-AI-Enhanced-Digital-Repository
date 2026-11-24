<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    public function test_creates_a_new_admin(): void
    {
        $this->artisan('idr:make-admin', ['email' => 'Dean@AUN.edu.ng', '--name' => 'The Dean', '--password' => 'Secret123'])
            ->assertSuccessful();

        $user = User::where('email', 'dean@aun.edu.ng')->first();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('Secret123', $user->password));
    }

    public function test_promotes_an_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'kemi@aun.edu.ng']);

        $this->artisan('idr:make-admin', ['email' => 'kemi@aun.edu.ng'])->assertSuccessful();

        $this->assertTrue($user->refresh()->isAdmin());
    }

    public function test_refuses_other_domains_and_weak_passwords(): void
    {
        $this->artisan('idr:make-admin', ['email' => 'boss@gmail.com', '--name' => 'X', '--password' => 'Secret123'])->assertFailed();
        $this->artisan('idr:make-admin', ['email' => 'boss@aun.edu.ng', '--name' => 'X', '--password' => 'weak'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
