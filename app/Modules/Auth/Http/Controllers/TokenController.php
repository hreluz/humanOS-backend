<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Resources\TokenResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Auth')]
class TokenController
{
    /**
     * List tokens.
     *
     * Lists the user's personal access tokens, newest first. Token values are never returned.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($request->user()->tokens()->latest('id')->get());
    }

    /**
     * Revoke a token.
     *
     * Deletes one of the user's tokens. A token that does not exist or belongs to another
     * user returns 404.
     *
     * @throws ModelNotFoundException
     */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->tokens()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * Revoke all tokens.
     *
     * Deletes every token of the user, including the one used for this request.
     */
    public function destroyAll(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }
}
