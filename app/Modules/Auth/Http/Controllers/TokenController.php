<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Resources\TokenResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TokenController
{
    /**
     * List the user's tokens, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($request->user()->tokens()->latest('id')->get());
    }

    /**
     * Revoke one of the user's tokens. Tokens of other users are a 404.
     */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->tokens()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * Revoke all of the user's tokens, including the current one.
     */
    public function destroyAll(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }
}
