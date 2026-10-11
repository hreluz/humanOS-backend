<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_factory_user_has_the_user_role_by_default(): void
    {
        $user = User::factory()->make();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_admin_state_sets_the_admin_role(): void
    {
        $user = User::factory()->admin()->make();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->isAdmin());
    }
}
