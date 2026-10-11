<?php

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example 1 */
            'id' => $this->id,
            /** @example Ana Torres */
            'name' => $this->name,
            /** @example ana@example.com */
            'email' => $this->email,
            /** @example user */
            'role' => $this->role->value,
            /** @example 2026-10-11T09:35:00Z */
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            /** @example 2026-10-11T09:30:00Z */
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
