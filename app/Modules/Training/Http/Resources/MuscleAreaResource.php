<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\MuscleArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MuscleArea
 */
class MuscleAreaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example chest */
            'key' => $this->key,
            /** @example Chest */
            'label' => $this->label,
            /** The muscles in the area, in display order. */
            'muscles' => MuscleResource::collection($this->whenLoaded('muscles')),
        ];
    }
}
