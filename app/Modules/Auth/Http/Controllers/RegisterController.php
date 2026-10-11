<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\AuthTokenResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;

#[Group('Auth')]
class RegisterController
{
    /**
     * Register a new user.
     *
     * Creates the account, sends the email verification link and issues the first
     * personal access token. The token is named after `device_name`, or the User-Agent
     * when it is missing.
     *
     * @unauthenticated
     *
     * @throws ThrottleRequestsException
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        event(new Registered($user));

        return (new AuthTokenResource($user, $user->createToken($request->deviceName())->plainTextToken))
            ->response()
            ->setStatusCode(201);
    }
}
