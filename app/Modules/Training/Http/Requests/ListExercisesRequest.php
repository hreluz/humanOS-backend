<?php

namespace App\Modules\Training\Http\Requests;

use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\MuscleRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListExercisesRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'equipment_group' => ['sometimes', 'array'],
            /**
             * Only exercises whose equipment is in one of these groups. Keys come from `/equipment-groups`.
             *
             * @example ["kettlebell", "bands"]
             */
            'equipment_group.*' => ['string', Rule::exists('equipment_groups', 'key')],
            'equipment' => ['sometimes', 'array'],
            /**
             * Only exercises with one of these pieces of equipment. Keys come from `/equipment-groups`.
             *
             * @example ["barbell", "cable"]
             */
            'equipment.*' => ['string', Rule::exists('equipment', 'key')],
            'category' => ['sometimes', 'array'],
            /**
             * Only these categories.
             *
             * @example ["strength", "cardio"]
             */
            'category.*' => [Rule::enum(ExerciseCategory::class)],
            'level' => ['sometimes', 'array'],
            /**
             * Only these levels.
             *
             * @example ["beginner", "intermediate"]
             */
            'level.*' => [Rule::enum(ExerciseLevel::class)],
            /**
             * Case-insensitive match anywhere in the name.
             *
             * @example bench
             */
            'search' => ['sometimes', 'string', 'min:2', 'max:100'],
            /**
             * Only exercises tagged with this muscle, primary or secondary. Primaries come first, ranked by the muscle's position and then by fewest primaries; secondaries follow, sorted by name. Keys come from `/muscle-areas`.
             *
             * @example quads
             */
            'muscle' => ['required_with:role', 'string', Rule::exists('muscles', 'key')],
            /**
             * Only exercises where `muscle` has this role. Needs `muscle`.
             *
             * @example primary
             */
            'role' => ['sometimes', Rule::enum(MuscleRole::class)],
            /**
             * Only the exercises saved at this workout location. Must be the key of one of the caller's locations, from `/locations`.
             *
             * @example l_01JC9Z7M4Q8N2R5T6V7W8X9Y0Z
             */
            // Scramble reads `rules()` without a user, hence the nullsafe call.
            'location' => ['sometimes', 'string', Rule::exists('workout_locations', 'key')->where('user_id', $this->user()?->getKey())],
            /**
             * Page number. Defaults to 1.
             *
             * @example 1
             */
            'page' => ['sometimes', 'integer', 'min:1'],
            /**
             * Page size. Defaults to 50.
             *
             * @example 50
             */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The validated page size.
     */
    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }
}
