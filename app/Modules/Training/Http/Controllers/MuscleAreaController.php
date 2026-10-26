<?php

namespace App\Modules\Training\Http\Controllers;

use App\Modules\Training\Http\Resources\MuscleAreaResource;
use App\Modules\Training\Models\MuscleArea;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Training')]
class MuscleAreaController
{
    /**
     * List muscle areas.
     *
     * Lists the body areas in display order, each with its muscles in display order. Clients use
     * it to draw the body map and to label the muscle keys that exercises return.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        $areas = MuscleArea::query()
            ->with('muscles')
            ->orderBy('sort_order')
            ->get();

        return MuscleAreaResource::collection($areas);
    }
}
