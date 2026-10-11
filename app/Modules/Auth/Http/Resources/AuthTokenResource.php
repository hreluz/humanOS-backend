<?php

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AuthTokenResource extends JsonResource
{
    public function __construct(User $resource, private string $token)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example 1|xR8nQ2vLk9Tz4WmP7bYc3HdF6gJs1NaE5uVo0iKq6b79c89d */
            'token' => $this->token,
            'user' => new UserResource($this->resource),
        ];
    }
}
