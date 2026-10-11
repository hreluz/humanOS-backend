<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Auth')]
class MeController
{
    /**
     * Get the current user.
     *
     * Returns the user the bearer token belongs to.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
