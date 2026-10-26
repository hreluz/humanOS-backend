<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\EquipmentGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentGroup
 */
class EquipmentGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example gym */
            'key' => $this->key,
            /** @example Gym */
            'label' => $this->label,
            /** The equipment in the group, in display order. */
            'equipment' => EquipmentResource::collection($this->whenLoaded('equipment')),
        ];
    }
}
