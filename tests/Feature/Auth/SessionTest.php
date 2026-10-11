<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Send the next request with a fresh guard, so a token deleted by a
     * previous request in the same test is looked up again.
     */
    private function withFreshToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Ana']);
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ana')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonMissingPath('data.password');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_rejects_an_invalid_token(): void
    {
        $this->withToken('1|not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('phone')->plainTextToken;
        $other = $user->createToken('laptop')->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(['laptop'], $user->tokens()->pluck('name')->all());
        $this->withFreshToken($current)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withFreshToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_requires_a_token(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
