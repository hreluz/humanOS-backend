<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Auth')]
class EmailVerificationController
{
    /**
     * Verify email.
     *
     * Marks the email as verified. This is the link sent by email: it is authenticated by
     * its signature (`expires` and `signature` query parameters), not by a token. Verifying
     * an already verified email succeeds.
     *
     * @unauthenticated
     *
     * @response array{message: string}
     *
     * @throws ThrottleRequestsException
     */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'Invalid verification link.');

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return response()->json(['message' => 'Email verified.']);
    }

    /**
     * Resend the verification email.
     *
     * Sends a new verification link to the current user. Returns 202 when the email is
     * sent, and 200 when the email is already verified.
     *
     * @throws ThrottleRequestsException
     */
    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
