<?php

namespace App\Modules\Training\Http\Controllers;

use App\Modules\Training\Http\Requests\StoreWorkoutLocationRequest;
use App\Modules\Training\Http\Requests\UpdateWorkoutLocationRequest;
use App\Modules\Training\Http\Resources\WorkoutLocationResource;
use App\Modules\Training\Models\WorkoutLocation;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Training')]
class WorkoutLocationController
{
    /**
     * List my workout locations.
     *
     * Returns the caller's workout locations, sorted by name, then key, with how many exercises each has. Not
     * paginated: a user has at most 20. The exercise keys are only returned by the detail endpoint.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = WorkoutLocation::ownedBy($request->user())
            ->withCount('exercises')
            ->orderBy('name')
            ->orderBy('key')
            ->get();

        return WorkoutLocationResource::collection($locations);
    }

    /**
     * Create a workout location.
     *
     * Creates a named location for the caller, with no exercises yet. The location gets a generated `l_` key
     * that never changes. A user has at most 20 locations, and names are unique per user, ignoring case and
     * accents. Returns the detail shape.
     *
     * @status 201
     */
    public function store(StoreWorkoutLocationRequest $request): JsonResponse
    {
        $location = WorkoutLocation::create([
            'user_id' => $request->user()->getKey(),
            'key' => WorkoutLocation::generateKey(),
            'name' => $request->validated('name'),
        ]);

        return $this->detail($location)->response()->setStatusCode(201);
    }

    /**
     * Show a workout location.
     *
     * Returns one of the caller's locations with the keys of its exercises, sorted by exercise name, then key.
     * Clients use them to mark exercises already at the location. Another user's location is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function show(WorkoutLocation $workoutLocation): WorkoutLocationResource
    {
        return $this->detail($workoutLocation);
    }

    /**
     * Update a workout location.
     *
     * Changes only the fields sent; an empty body changes nothing. Keeping the current name is allowed, and
     * taking another of the caller's location names, in any case, is a 422. Another user's location is a 404.
     * Returns the detail shape.
     *
     * @throws ModelNotFoundException
     */
    public function update(UpdateWorkoutLocationRequest $request, WorkoutLocation $workoutLocation): WorkoutLocationResource
    {
        $workoutLocation->update($request->validated());

        return $this->detail($workoutLocation);
    }

    /**
     * Delete a workout location.
     *
     * Deletes one of the caller's locations and its exercise list. The exercises themselves are not touched.
     * Another user's location is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function destroy(WorkoutLocation $workoutLocation): Response
    {
        $workoutLocation->delete();

        return response()->noContent();
    }

    /**
     * The detail shape: the count and the exercise keys.
     */
    private function detail(WorkoutLocation $location): WorkoutLocationResource
    {
        $location->load('exercises')->loadCount('exercises');

        return WorkoutLocationResource::make($location)->withDetails();
    }
}
