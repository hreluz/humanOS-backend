<?php

namespace App\Modules\Training\Http\Controllers;

use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\WorkoutLocation;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;

#[Group('Training')]
class WorkoutLocationExerciseController
{
    /**
     * Add an exercise to a workout location.
     *
     * Saves the exercise at one of the caller's locations. Idempotent: an exercise already there is also a 204,
     * so a double tap or a retry is safe. The location's `updated_at` only moves when the exercise was added.
     * Another user's location, or an unknown exercise, is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function store(WorkoutLocation $workoutLocation, Exercise $exercise): Response
    {
        $changes = $workoutLocation->exercises()->syncWithoutDetaching([$exercise->getKey()]);

        if ($changes['attached'] !== []) {
            $workoutLocation->touch();
        }

        return response()->noContent();
    }

    /**
     * Remove an exercise from a workout location.
     *
     * Removes the exercise from one of the caller's locations. Idempotent: an exercise that isn't there is also
     * a 204. The location's `updated_at` only moves when the exercise was removed. Another user's location, or
     * an unknown exercise, is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function destroy(WorkoutLocation $workoutLocation, Exercise $exercise): Response
    {
        if ($workoutLocation->exercises()->detach($exercise->getKey()) > 0) {
            $workoutLocation->touch();
        }

        return response()->noContent();
    }
}
