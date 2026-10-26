<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Stable identifier, used by exercises to reference the equipment.
             *
             * @example barbell
             */
            'key' => $this->key,
            /** @example Barbell */
            'label' => $this->label,
        ];
    }
}
