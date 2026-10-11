<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'iPhone 15',
        ], $overrides);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role', 'email_verified_at', 'created_at']]])
            ->assertJsonPath('data.user.email', 'ana@example.com')
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonPath('data.user.email_verified_at', null);

        $user = User::firstWhere('email', 'ana@example.com');
        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame(['iPhone 15'], $user->tokens()->pluck('name')->all());
    }

    public function test_returned_token_is_a_valid_sanctum_token(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload())->json('data.token');

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'admin']))
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'user');

        $this->assertSame(UserRole::User, User::firstWhere('email', 'ana@example.com')->role);
    }

    public function test_validation_errors(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'not-an-email',
            'password_confirmation' => 'different',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_device_name_falls_back_to_user_agent(): void
    {
        $this->withHeader('User-Agent', 'humanOS-iOS/1.0')
            ->postJson('/api/v1/auth/register', $this->payload(['device_name' => null]))
            ->assertCreated();

        $this->assertSame('humanOS-iOS/1.0', User::firstWhere('email', 'ana@example.com')->tokens()->value('name'));
    }

    public function test_device_name_falls_back_to_unknown_device(): void
    {
        $this->withHeader('User-Agent', '')
            ->postJson('/api/v1/auth/register', $this->payload(['device_name' => null]))
            ->assertCreated();

        $this->assertSame('Unknown device', User::firstWhere('email', 'ana@example.com')->tokens()->value('name'));
    }

    public function test_long_user_agent_is_truncated(): void
    {
        $this->withHeader('User-Agent', str_repeat('a', 300))
            ->postJson('/api/v1/auth/register', $this->payload(['device_name' => null]))
            ->assertCreated();

        $this->assertSame(255, strlen(User::firstWhere('email', 'ana@example.com')->tokens()->value('name')));
    }

    public function test_register_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => "user{$i}@example.com"]))
                ->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'user7@example.com']))
            ->assertTooManyRequests();
    }

    public function test_register_is_not_throttled_when_auth_throttling_is_disabled(): void
    {
        config(['auth.throttle_enabled' => false]);

        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => "user{$i}@example.com"]))
                ->assertCreated();
        }
    }
}
