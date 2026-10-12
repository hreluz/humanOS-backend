<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Modules\Profile\Http\Resources\TargetWeightEntryResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Profile')]
class TargetWeightEntryController
{
    /**
     * List target weight entries.
     *
     * Lists every target weight the user has set, newest first. One entry is added on each profile
     * save. `target_weight` is in the profile's `unit_system` (metric if the user has no profile yet).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user()->loadMissing('profile');

        $entries = $user->targetWeightEntries()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->each->setRelation('user', $user);

        return TargetWeightEntryResource::collection($entries);
    }
}
