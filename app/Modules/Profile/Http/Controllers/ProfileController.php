<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Models\User;
use App\Modules\Profile\Http\Requests\UpdateProfileRequest;
use App\Modules\Profile\Http\Resources\ProfileResource;
use App\Modules\Profile\Models\Profile;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Profile')]
class ProfileController
{
    /**
     * Get the profile.
     *
     * Returns the user's body profile with their latest weight and target weight. Values are
     * returned in the user's `unit_system` and in metric. A user who has not saved a profile
     * yet gets 404.
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request): ProfileResource
    {
        $user = $request->user();

        return $this->resource($user, $user->profile()->firstOrFail());
    }

    /**
     * Save the profile.
     *
     * Creates the user's profile or fully replaces it; every field is required. Values are read
     * in `unit_system` units, stored in metric, and `unit_system` is kept as the display
     * preference. Each save also records one weight entry and one target weight entry, even when
     * the values did not change. Always returns 200.
     */
    public function update(UpdateProfileRequest $request): ProfileResource
    {
        $user = $request->user();

        $profile = DB::transaction(function () use ($request, $user) {
            $now = now();

            $profile = $user->profile()->updateOrCreate([], [
                'birth_date' => $request->validated('birth_date'),
                'sex' => $request->validated('sex'),
                'height_cm' => $request->heightCm(),
                'activity_level' => $request->validated('activity_level'),
                'unit_system' => $request->unitSystem(),
            ]);

            $user->weightEntries()->create([
                'weight_kg' => $request->weightKg(),
                'recorded_at' => $now,
            ]);

            $user->targetWeightEntries()->create([
                'target_weight_kg' => $request->targetWeightKg(),
                'recorded_at' => $now,
            ]);

            return $profile;
        });

        // fresh() so the resource is not flagged as recently created, which would turn the 200 into a 201.
        return $this->resource($user, $profile->fresh());
    }

    /**
     * Build the resource with the user's latest weight and target weight loaded.
     */
    private function resource(User $user, Profile $profile): ProfileResource
    {
        $user->load(['latestWeightEntry', 'latestTargetWeightEntry']);
        $profile->setRelation('user', $user);

        return new ProfileResource($profile);
    }
}
