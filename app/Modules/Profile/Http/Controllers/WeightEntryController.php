<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Modules\Profile\Http\Resources\WeightEntryResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Profile')]
class WeightEntryController
{
    /**
     * List weight entries.
     *
     * Lists every weight the user has recorded, newest first. One entry is added on each profile
     * save. `weight` is in the profile's `unit_system` (metric if the user has no profile yet).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user()->loadMissing('profile');

        $entries = $user->weightEntries()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->each->setRelation('user', $user);

        return WeightEntryResource::collection($entries);
    }
}
