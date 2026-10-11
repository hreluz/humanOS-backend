<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'ana@example.com']);
    }

    public function test_user_can_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'password',
            'device_name' => 'Pixel 9',
        ])->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]])
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.user.role', 'user');

        $this->assertSame(['Pixel 9'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_wrong_password_returns_422_on_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_unknown_email_returns_422_on_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_validation_errors(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_device_name_falls_back_to_user_agent(): void
    {
        $this->withHeader('User-Agent', 'humanOS-Desktop/0.1')
            ->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertOk();

        $this->assertSame('humanOS-Desktop/0.1', $this->user->tokens()->value('name'));
    }

    public function test_sixth_failed_login_within_a_minute_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_failed_logins_are_not_throttled_when_auth_throttling_is_disabled(): void
    {
        config(['auth.throttle_enabled' => false]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }
    }

    public function test_throttle_is_per_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'other@example.com', 'password' => 'wrong'])
            ->assertUnprocessable();
    }
}
