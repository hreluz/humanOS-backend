<?php

namespace App\Modules\Profile\Http\Resources;

use App\Modules\Profile\Enums\UnitSystem;
use App\Modules\Profile\Models\TargetWeightEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TargetWeightEntry
 */
class TargetWeightEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `target_weight` uses the unit system of the entry's user profile; load `user.profile` before
     * building it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $units = $this->user->profile?->unit_system ?? UnitSystem::Metric;

        return [
            /** @example 7 */
            'id' => $this->id,
            /**
             * Target weight in the profile's `unit_system`: kilograms (metric, also used when the user
             * has no profile) or pounds (imperial).
             *
             * @example 140.0
             */
            'target_weight' => $units->fromKg((float) $this->target_weight_kg),
            /** @example 63.5 */
            'target_weight_kg' => (float) $this->target_weight_kg,
            /** @example 2026-10-11T09:30:00Z */
            'recorded_at' => $this->recorded_at->toIso8601ZuluString(),
        ];
    }
}
