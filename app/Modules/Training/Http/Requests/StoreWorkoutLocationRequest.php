<?php

namespace App\Modules\Training\Http\Requests;

use App\Modules\Training\Models\WorkoutLocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * A new workout location for the caller (SPEC 19). Strings are trimmed by the global middleware.
 */
class StoreWorkoutLocationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * 1–50 characters after trimming. Unique among the caller's locations, ignoring case and accents.
             *
             * @example Gym Local
             */
            'name' => ['required', 'string', 'max:50', $this->uniqueName()],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (WorkoutLocation::ownedBy($this->user())->count() >= WorkoutLocation::MAX_PER_USER) {
                    $validator->errors()->add('name', 'You can have at most '.WorkoutLocation::MAX_PER_USER.' workout locations.');
                }
            },
        ];
    }

    /**
     * The name must be free among the caller's locations. The `utf8mb4_unicode_ci` collation makes the comparison
     * ignore case and accents. Scramble reads `rules()` without a user, hence the nullsafe call.
     */
    protected function uniqueName(): Unique
    {
        return Rule::unique('workout_locations', 'name')->where('user_id', $this->user()?->getKey());
    }
}
