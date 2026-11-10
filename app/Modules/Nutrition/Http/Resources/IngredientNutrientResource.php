<?php

namespace App\Modules\Nutrition\Http\Resources;

use App\Modules\Nutrition\Models\Nutrient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nutrient with its amount in one ingredient, read from the `ingredient_nutrient` pivot.
 *
 * @mixin Nutrient
 */
class IngredientNutrientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example cholesterol */
            'key' => $this->key,
            /** @example Cholesterol */
            'label' => $this->label,
            /** @example fats_and_sugars */
            'group' => $this->group,
            /** @example mg */
            'unit' => $this->unit,
            /**
             * Amount per 100 g, in `unit`. A measured `0` is sent as `0`.
             *
             * @example 411
             */
            'amount' => (float) $this->pivot->amount,
        ];
    }
}
