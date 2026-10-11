<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

#[Group('Auth')]
class LoginController
{
    /**
     * Log in.
     *
     * Checks the credentials and issues a new personal access token. Wrong credentials
     * return a validation error on `email`. Limited to 5 attempts per minute per email and IP.
     *
     * @unauthenticated
     *
     * @throws ThrottleRequestsException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return response()->json([
            /** @example 1|xR8nQ2vLk9Tz4WmP7bYc3HdF6gJs1NaE5uVo0iKq6b79c89d */
            'token' => $user->createToken($request->deviceName())->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Log out.
     *
     * Revokes the token used for this request. Other tokens of the user stay valid.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
