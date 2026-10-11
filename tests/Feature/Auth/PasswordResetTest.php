<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => 'https://app.humanos.test']);
    }

    private function resetPayload(User $user, string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ], $overrides);
    }

    public function test_forgot_password_sends_a_link_pointing_to_the_frontend(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $notification = Notification::sent($user, ResetPassword::class)->last();
        $this->assertSame(
            'https://app.humanos.test/reset-password?token='.$notification->token.'&email=ana%40example.com',
            $notification->toMail($user)->actionUrl,
        );
    }

    public function test_forgot_password_response_is_the_same_for_unknown_emails(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    public function test_forgot_password_validates_the_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_changes_the_password_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $user->createToken('laptop');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $token))->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password123'])
            ->assertOk();
    }

    public function test_reset_with_an_invalid_token_returns_422(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, 'invalid-token'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_reset_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $token))->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $token, [
            'password' => 'another-password123',
            'password_confirmation' => 'another-password123',
        ]))->assertUnprocessable();
    }

    public function test_reset_validates_the_payload(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }
}
