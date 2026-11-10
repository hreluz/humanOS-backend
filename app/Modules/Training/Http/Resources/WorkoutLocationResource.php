<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\WorkoutLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkoutLocation
 */
class WorkoutLocationResource extends JsonResource
{
    private bool $withDetails = false;

    /**
     * Include the location's exercise keys, which only the detail view shows.
     */
    public function withDetails(): static
    {
        $this->withDetails = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Stable identifier, used in the URL and in the exercise list's `location` filter. Never changes.
             *
             * @example l_01JC9Z7M4Q8N2R5T6V7W8X9Y0Z
             */
            'key' => $this->key,
            /** @example Gym Local */
            'name' => $this->name,
            /**
             * How many exercises are saved at the location.
             *
             * @example 2
             */
            'exercise_count' => (int) $this->exercises_count,
            /**
             * Keys of the exercises saved at the location, sorted by exercise name, then key. Only returned by the
             * detail shape (create, show and update). Full exercise cards come from `/exercises?location=<key>`.
             *
             * @var list<string>
             *
             * @example ["barbell_bench_press", "dumbbell_bicep_curl"]
             */
            'exercises' => $this->when($this->withDetails, fn () => $this->exercises->pluck('key')->values()->all()),
            /** @example 2026-11-10T10:00:00Z */
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            /** @example 2026-11-10T10:00:00Z */
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
