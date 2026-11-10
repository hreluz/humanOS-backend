<?php

namespace App\Modules\Training\Http\Requests;

use App\Modules\Training\Models\WorkoutLocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A partial edit of the caller's own workout location (SPEC 19). A field that isn't sent keeps its stored value.
 */
class UpdateWorkoutLocationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('workoutLocation');

        return [
            /**
             * 1–50 characters after trimming. Unique among the caller's other locations, ignoring case and accents.
             * Keeping the current name is allowed.
             *
             * @example House workout
             */
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('workout_locations', 'name')
                    ->where('user_id', $this->user()?->getKey())
                    ->ignore($location instanceof WorkoutLocation ? $location->getKey() : null),
            ],
        ];
    }
}
