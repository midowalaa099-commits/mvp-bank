<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_profile_input_cannot_promote_a_customer_to_admin(): void
    {
        $user = User::factory()->make();

        $user->fill(['name' => 'Updated Customer', 'role' => 'admin']);

        $this->assertSame('Updated Customer', $user->name);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_privately_assigned_admin_role_is_recognized(): void
    {
        $user = User::factory()->make(['role' => UserRole::Admin]);

        $this->assertTrue($user->isAdmin());
    }
}
