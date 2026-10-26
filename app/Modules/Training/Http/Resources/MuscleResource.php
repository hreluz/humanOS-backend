<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\Muscle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Muscle
 */
class MuscleResource extends JsonResource
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
             * Stable identifier, used by exercises to reference the muscle.
             *
             * @example upper_chest
             */
            'key' => $this->key,
            /** @example Upper chest */
            'label' => $this->label,
            /**
             * The body map view the muscle is drawn on.
             *
             * @example front
             */
            'body_view' => $this->body_view,
        ];
    }
}
