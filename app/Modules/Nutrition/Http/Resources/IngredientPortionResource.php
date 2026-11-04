<?php

namespace App\Modules\Nutrition\Http\Resources;

use App\Modules\Nutrition\Models\IngredientPortion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IngredientPortion
 */
class IngredientPortionResource extends JsonResource
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
             * Stable identifier of the portion within its ingredient.
             *
             * @example large
             */
            'key' => $this->key,
            /** @example 1 large egg */
            'label' => $this->label,
            /**
             * Weight of the portion in grams.
             *
             * @example 50
             */
            'grams' => $this->grams,
        ];
    }
}
