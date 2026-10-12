<?php

namespace App\Modules\Profile\Http\Resources;

use App\Modules\Profile\Enums\UnitSystem;
use App\Modules\Profile\Models\WeightEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WeightEntry
 */
class WeightEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `weight` uses the unit system of the entry's user profile; load `user.profile` before
     * building it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $units = $this->user->profile?->unit_system ?? UnitSystem::Metric;

        return [
            /** @example 12 */
            'id' => $this->id,
            /**
             * Weight in the profile's `unit_system`: kilograms (metric, also used when the user
             * has no profile) or pounds (imperial).
             *
             * @example 154.3
             */
            'weight' => $units->fromKg((float) $this->weight_kg),
            /** @example 69.99 */
            'weight_kg' => (float) $this->weight_kg,
            /** @example 2026-10-11T09:30:00Z */
            'recorded_at' => $this->recorded_at->toIso8601ZuluString(),
        ];
    }
}
