<?php

namespace App\Modules\Training\Http\Controllers;

use App\Modules\Training\Http\Resources\EquipmentGroupResource;
use App\Modules\Training\Models\EquipmentGroup;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Training')]
class EquipmentGroupController
{
    /**
     * List equipment groups.
     *
     * Lists the equipment groups in display order, each with its equipment in display order.
     * Clients use it to draw the equipment tabs and to label the equipment keys that exercises
     * return.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        $groups = EquipmentGroup::query()
            ->with('equipment')
            ->orderBy('sort_order')
            ->get();

        return EquipmentGroupResource::collection($groups);
    }
}
