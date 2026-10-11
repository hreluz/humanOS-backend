<?php

namespace App\Modules\Auth\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin PersonalAccessToken
 */
class TokenResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'expires_at' => $this->expiresAt()?->toIso8601ZuluString(),
            'is_current' => $this->id === $request->user()?->currentAccessToken()?->id,
        ];
    }

    /**
     * When Sanctum will reject the token: its own expiry, else created_at plus
     * the global sanctum.expiration minutes, whichever comes first.
     */
    private function expiresAt(): ?CarbonInterface
    {
        $minutes = config('sanctum.expiration');
        $global = $minutes ? $this->created_at?->copy()->addMinutes((int) $minutes) : null;

        if ($this->expires_at && $global) {
            return $this->expires_at->min($global);
        }

        return $this->expires_at ?? $global;
    }
}
