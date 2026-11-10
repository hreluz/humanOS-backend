<?php

namespace App\Modules\Training\Http\Controllers;

use App\Modules\Training\Http\Requests\ListExercisesRequest;
use App\Modules\Training\Http\Resources\ExerciseResource;
use App\Modules\Training\Models\Exercise;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Training')]
class ExerciseController
{
    /**
     * List exercises.
     *
     * Returns the exercise catalog, paginated and sorted by name, or ranked by the muscle when
     * `muscle` is set. Filters combine with AND, and values inside one array filter combine with
     * OR. `location` keeps only the exercises saved at one of the caller's workout locations and combines with
     * the other filters, the muscle ranking included. `instructions` and `source_id` are only returned by the
     * detail endpoint.
     */
    public function index(ListExercisesRequest $request): AnonymousResourceCollection
    {
        $exercises = Exercise::query()
            ->filter($request)
            ->with(['equipment.group', 'muscles', 'photos'])
            ->paginate($request->perPage())
            ->withQueryString();

        return ExerciseResource::collection($exercises);
    }

    /**
     * Show an exercise.
     *
     * Returns one exercise by its key, with its photos and instructions. Muscles and equipment are
     * keys: clients get their labels from `/muscle-areas` and `/equipment-groups`.
     *
     * @throws ModelNotFoundException
     */
    public function show(Exercise $exercise): ExerciseResource
    {
        $exercise->load(['equipment.group', 'muscles', 'photos']);

        return ExerciseResource::make($exercise)->withDetails();
    }
}
