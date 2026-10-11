<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_middleware_only_lets_admins_through(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'admin'])
            ->get('/api/test/admin-only', fn () => ['ok' => true]);

        $user = User::factory()->create()->createToken('a')->plainTextToken;
        $admin = User::factory()->admin()->create()->createToken('b')->plainTextToken;

        $this->withToken($user)->getJson('/api/test/admin-only')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->getJson('/api/test/admin-only')->assertOk();
    }

    public function test_admin_middleware_returns_403_for_guests(): void
    {
        Route::middleware(['api', 'admin'])->get('/api/test/admin-only', fn () => ['ok' => true]);

        $this->getJson('/api/test/admin-only')->assertForbidden();
    }

    public function test_command_promotes_a_user(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->artisan('users:promote', ['email' => 'ana@example.com'])->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
    }

    public function test_command_demotes_a_user(): void
    {
        $user = User::factory()->admin()->create(['email' => 'ana@example.com']);

        $this->artisan('users:promote', ['email' => 'ana@example.com', '--demote' => true])->assertSuccessful();

        $this->assertSame(UserRole::User, $user->fresh()->role);
    }

    public function test_command_fails_for_an_unknown_email(): void
    {
        $this->artisan('users:promote', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user found')
            ->assertFailed();
    }
}
