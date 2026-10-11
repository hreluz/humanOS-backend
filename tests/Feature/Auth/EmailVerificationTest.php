<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => 'https://app.humanos.test']);
    }

    /**
     * The link from the last VerifyEmail sent to the user. Notification::fake()
     * doesn't render mail, so the URL is built now: call this before travelling in time.
     */
    private function verificationLink(User $user): string
    {
        Notification::assertSentTo($user, VerifyEmail::class);

        return Notification::sent($user, VerifyEmail::class)->last()->toMail($user)->actionUrl;
    }

    /**
     * Turn the frontend link into the API call the frontend makes.
     */
    private function apiUrlFor(string $link): string
    {
        parse_str(parse_url($link, PHP_URL_QUERY), $query);

        return "/api/v1/auth/email/verify/{$query['id']}/{$query['hash']}?".http_build_query([
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }

    private function unverifiedUserWithLink(): array
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        return [$user, $this->verificationLink($user)];
    }

    public function test_register_sends_a_verification_email_pointing_to_the_frontend(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::firstWhere('email', 'ana@example.com');

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->assertStringStartsWith('https://app.humanos.test/verify-email?', $this->verificationLink($user));
    }

    public function test_link_parameters_verify_the_user(): void
    {
        [$user, $link] = $this->unverifiedUserWithLink();

        $this->getJson($this->apiUrlFor($link))
            ->assertOk()
            ->assertJsonPath('message', 'Email verified.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_twice_is_idempotent(): void
    {
        [$user, $link] = $this->unverifiedUserWithLink();

        $this->getJson($this->apiUrlFor($link))->assertOk();
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->getJson($this->apiUrlFor($link))
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    public function test_tampered_signature_returns_403(): void
    {
        [$user, $link] = $this->unverifiedUserWithLink();

        $this->getJson($this->apiUrlFor($link).'0')->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_returns_403(): void
    {
        [$user, $link] = $this->unverifiedUserWithLink();

        $this->travel(61)->minutes();

        $this->getJson($this->apiUrlFor($link))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_for_another_users_hash_returns_403(): void
    {
        [$user] = $this->unverifiedUserWithLink();
        $other = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('auth.verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($other->email),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_sends_a_new_link(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')
            ->assertAccepted()
            ->assertJsonPath('message', 'Verification link sent.');

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_resend_returns_200_when_already_verified(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        Notification::assertNothingSent();
    }

    public function test_resend_requires_a_token(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    }

    public function test_verified_middleware_blocks_unverified_users(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'verified'])
            ->get('/api/test/verified-only', fn () => ['ok' => true]);

        $unverified = User::factory()->unverified()->create()->createToken('a')->plainTextToken;
        $verified = User::factory()->create()->createToken('b')->plainTextToken;

        $this->withToken($unverified)->getJson('/api/test/verified-only')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($verified)->getJson('/api/test/verified-only')->assertOk();
    }
}
