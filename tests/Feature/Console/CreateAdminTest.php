<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_creates_admin_with_hashed_password(): void
    {
        $this->artisan('bank:create-admin', ['name' => 'Bank Admin', 'email' => 'ADMIN@example.com'])
            ->expectsQuestion('Password', 'secure-password-123')
            ->expectsQuestion('Confirm password', 'secure-password-123')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_command_does_not_promote_or_overwrite_an_existing_customer(): void
    {
        $user = User::factory()->create(['email' => 'customer@example.com']);
        $originalPassword = $user->password;
        $this->artisan('bank:create-admin', ['name' => 'Admin', 'email' => $user->email])
            ->expectsQuestion('Password', 'secure-password-123')
            ->expectsQuestion('Confirm password', 'secure-password-123')
            ->assertFailed();

        $this->assertSame(UserRole::Customer, $user->fresh()->role);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_command_rejects_weak_passwords(): void
    {
        $this->artisan('bank:create-admin', ['name' => 'Admin', 'email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'short')->expectsQuestion('Confirm password', 'short')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_command_never_creates_default_credentials(): void
    {
        $this->artisan('bank:create-admin', ['name' => 'Admin', 'email' => 'admin@example.com', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
