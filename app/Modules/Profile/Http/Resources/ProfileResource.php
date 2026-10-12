<?php

namespace App\Modules\Profile\Http\Resources;

use App\Modules\Profile\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Profile
 */
class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The latest weight and target weight are read from the user's history; load
     * `user.latestWeightEntry` and `user.latestTargetWeightEntry` before building it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $units = $this->unit_system;
        $weightKg = $this->user->latestWeightEntry?->weight_kg;
        $targetWeightKg = $this->user->latestTargetWeightEntry?->target_weight_kg;

        return [
            /** @example 1990-04-15 */
            'birth_date' => $this->birth_date->toDateString(),
            /** @example 36 */
            'age' => $this->age,
            /** @example female */
            'sex' => $this->sex->value,
            /** @example moderately_active */
            'activity_level' => $this->activity_level->value,
            /** @example imperial */
            'unit_system' => $units->value,
            /**
             * Height in `unit_system` units: centimetres (metric) or inches (imperial).
             *
             * @example 65.0
             */
            'height' => $units->fromCm((float) $this->height_cm),
            /**
             * Latest weight in `unit_system` units: kilograms (metric) or pounds (imperial).
             *
             * @example 154.3
             */
            'weight' => $weightKg === null ? null : $units->fromKg((float) $weightKg),
            /**
             * Latest target weight in `unit_system` units: kilograms (metric) or pounds (imperial).
             *
             * @example 140.0
             */
            'target_weight' => $targetWeightKg === null ? null : $units->fromKg((float) $targetWeightKg),
            /** @example 165.1 */
            'height_cm' => (float) $this->height_cm,
            /** @example 69.99 */
            'weight_kg' => $weightKg === null ? null : (float) $weightKg,
            /** @example 63.5 */
            'target_weight_kg' => $targetWeightKg === null ? null : (float) $targetWeightKg,
            /** @example 2026-10-11T09:30:00Z */
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
