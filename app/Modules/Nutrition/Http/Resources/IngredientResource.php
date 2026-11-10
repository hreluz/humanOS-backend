<?php

namespace App\Modules\Nutrition\Http\Resources;

use App\Modules\Nutrition\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    private bool $withDetails = false;

    /**
     * Include the fields only the detail view shows.
     */
    public function withDetails(): static
    {
        $this->withDetails = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Stable identifier, used in the URL. Catalog keys are snake_case words; a user's ingredient has a
             * generated `u_` key.
             *
             * @example egg_whole_raw
             */
            'key' => $this->key,
            /**
             * English name for catalog ingredients. A user's ingredient returns its name as written, in any language.
             *
             * @example Egg, whole, raw
             */
            'name' => $this->name,
            /** @example eggs */
            'category' => $this->category,
            /**
             * Whether the caller owns the ingredient: `true` for their own, `false` for catalog ingredients.
             *
             * @var bool
             *
             * @example false
             */
            'is_mine' => $request->user() !== null && $this->isOwnedBy($request->user()),
            /**
             * Energy and macronutrients per 100 g: `kcal` in kcal, the rest in grams. `fiber` is `null` when it
             * isn't known; clients show it as "no data" and leave it out of totals.
             *
             * @example {"kcal": 148, "protein": 12.4, "carbs": 0.96, "fat": 9.96, "fiber": 0}
             */
            'per_100g' => [
                'kcal' => $this->kcal,
                'protein' => $this->protein,
                'carbs' => $this->carbs,
                'fat' => $this->fat,
                'fiber' => $this->fiber,
            ],
            /** Portions in display order. Can be empty. */
            'portions' => IngredientPortionResource::collection($this->portions),
            /**
             * Photos in display order. The first is the main photo, for lists and thumbnails. Can be empty. Clients must
             * show each photo's `author`, license and a link to `credit_url` wherever they show it.
             */
            'photos' => IngredientPhotoResource::collection($this->photos),
            /** Measured nutrients per 100 g, in display order. Unmeasured ones are left out. Only returned by the detail endpoint. */
            'nutrients' => $this->when($this->withDetails, fn () => IngredientNutrientResource::collection($this->nutrients)),
            /**
             * Where the values come from: the USDA FoodData Central dataset and the FDC id, or `user` with a `null`
             * id for a user's ingredient. Only returned by the detail endpoint.
             *
             * @example {"type": "usda_foundation", "id": "748967"}
             */
            'source' => $this->when($this->withDetails, fn () => [
                'type' => $this->source,
                'id' => $this->source_id,
            ]),
        ];
    }
}
