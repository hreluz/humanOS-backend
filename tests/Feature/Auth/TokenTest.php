<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->createToken('laptop');
        $this->current = $this->user->createToken('phone')->plainTextToken;
    }

    /**
     * Send the next request with a fresh guard, so a token deleted by a
     * previous request in the same test is looked up again.
     */
    private function withFreshToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_lists_the_users_tokens(): void
    {
        User::factory()->create()->createToken('someone else');

        $this->withToken($this->current)->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'created_at', 'expires_at', 'is_current']]])
            ->assertJsonPath('data.0.name', 'phone')
            ->assertJsonPath('data.1.name', 'laptop');
    }

    public function test_exactly_one_token_is_current(): void
    {
        $data = $this->withToken($this->current)->getJson('/api/v1/auth/tokens')->json('data');

        $current = array_values(array_filter($data, fn (array $token) => $token['is_current']));
        $this->assertCount(1, $current);
        $this->assertSame('phone', $current[0]['name']);
    }

    public function test_expires_at_follows_the_configured_expiration(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = $this->user->tokens()->firstWhere('name', 'phone');

        $this->withToken($this->current)->getJson('/api/v1/auth/tokens')
            ->assertJsonPath('data.0.expires_at', $token->created_at->addMinutes(60)->toIso8601ZuluString());
    }

    public function test_revokes_one_token(): void
    {
        $laptop = $this->user->tokens()->firstWhere('name', 'laptop');

        $this->withToken($this->current)->deleteJson("/api/v1/auth/tokens/{$laptop->id}")->assertNoContent();

        $this->assertSame(['phone'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_revoking_another_users_token_returns_404(): void
    {
        $other = User::factory()->create();
        $other->createToken('theirs');
        $theirs = $other->tokens()->first();

        $this->withToken($this->current)->deleteJson("/api/v1/auth/tokens/{$theirs->id}")->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_revokes_all_tokens(): void
    {
        $this->withToken($this->current)->deleteJson('/api/v1/auth/tokens')->assertNoContent();

        $this->assertSame(0, $this->user->tokens()->count());
        $this->withFreshToken($this->current)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/tokens')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/tokens/1')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/tokens')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        config(['sanctum.expiration' => 60]);

        $this->travel(59)->minutes();
        $this->withFreshToken($this->current)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->withFreshToken($this->current)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
