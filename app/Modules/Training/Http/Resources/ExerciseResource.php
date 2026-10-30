<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\Muscle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Exercise
 */
class ExerciseResource extends JsonResource
{
    private bool $withDetails = false;

    /**
     * Include the fields only the detail view shows.
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
             * Stable identifier, used in the URL.
             *
             * @example barbell_bench_press
             */
            'key' => $this->key,
            /** @example Barbell Bench Press */
            'name' => $this->name,
            /** @example strength */
            'category' => $this->category,
            /** @example beginner */
            'level' => $this->level,
            /** @example compound */
            'mechanic' => $this->mechanic,
            /** @example push */
            'force' => $this->force,
            /**
             * One-line coaching cue.
             *
             * @example Shoulder blades pinned back, bar to mid-chest.
             */
            'cue' => $this->cue,
            /**
             * Equipment key. The label comes from `/equipment-groups`.
             *
             * @example barbell
             */
            'equipment' => $this->equipment->key,
            /**
             * Equipment group key. The label comes from `/equipment-groups`.
             *
             * @example gym
             */
            'equipment_group' => $this->equipment->group->key,
            /**
             * Keys of the muscles doing most of the work, most direct first. Every exercise has at least one. A muscle appears in `primary` or `secondary`, never both, and muscles that only stabilise are in neither. For example, the chest does the pressing in a barbell bench press, so `lower_chest` and `upper_chest` are primary. The labels come from `/muscle-areas`.
             *
             * @var list<string>
             *
             * @example ["lower_chest", "upper_chest"]
             */
            'primary' => $this->muscleKeys(MuscleRole::Primary),
            /**
             * Keys of the muscles that assist the primary ones, in order. Can be empty: stretching and foam-rolling exercises have none. For example, the front delts and triceps help the chest in a barbell bench press, so `front_delts`, `triceps_lateral` and `triceps_long` are secondary. The labels come from `/muscle-areas`.
             *
             * @var list<string>
             *
             * @example ["front_delts", "triceps_lateral", "triceps_long"]
             */
            'secondary' => $this->muscleKeys(MuscleRole::Secondary),
            /** Photos in display order. */
            'photos' => ExercisePhotoResource::collection($this->photos),
            /**
             * Step-by-step instructions. Only returned by the detail endpoint.
             *
             * @var list<string>|null
             *
             * @example ["Lie back on a flat bench.", "Lower the bar to your chest."]
             */
            'instructions' => $this->when($this->withDetails, fn () => $this->instructions),
            /**
             * The free-exercise-db id. Only returned by the detail endpoint.
             *
             * @example Barbell_Bench_Press_-_Medium_Grip
             */
            'source_id' => $this->when($this->withDetails, fn () => $this->source_id),
        ];
    }

    /**
     * The keys of the muscles with the given role, in order.
     *
     * @return list<string>
     */
    private function muscleKeys(MuscleRole $role): array
    {
        return $this->muscles
            ->filter(fn (Muscle $muscle): bool => $muscle->pivot->role === $role->value)
            ->sortBy(fn (Muscle $muscle): int => $muscle->pivot->sort_order)
            ->pluck('key')
            ->values()
            ->all();
    }
}
