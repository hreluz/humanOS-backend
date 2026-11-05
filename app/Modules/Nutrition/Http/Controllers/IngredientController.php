<?php

namespace App\Modules\Nutrition\Http\Controllers;

use App\Modules\Nutrition\Http\Requests\SearchIngredientsRequest;
use App\Modules\Nutrition\Http\Resources\IngredientResource;
use App\Modules\Nutrition\Models\Ingredient;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Nutrition')]
class IngredientController
{
    /**
     * Search ingredients.
     *
     * Returns every ingredient whose English name or English alias contains `search`, ignoring case and
     * accents, in one unpaginated list. Ranked by the name: an exact match first, then names starting with
     * the term, then names with a word starting with it, then the rest (mid-word or alias-only matches);
     * ties sort by name, then key. Values are per 100 g. `nutrients` and `source` are only returned by the
     * detail endpoint. Each ingredient's first photo is its main photo; use its `thumbnail_url` in lists.
     */
    public function index(SearchIngredientsRequest $request): AnonymousResourceCollection
    {
        $ingredients = Ingredient::query()
            ->search($request)
            ->with(['portions', 'photos'])
            ->get();

        return IngredientResource::collection($ingredients);
    }

    /**
     * Show an ingredient.
     *
     * Returns one ingredient by its key, with every measured nutrient per 100 g and the data source. A
     * nutrient that wasn't measured is left out; clients show it as "no data".
     *
     * @throws ModelNotFoundException
     */
    public function show(Ingredient $ingredient): IngredientResource
    {
        $ingredient->load(['portions', 'photos', 'nutrients']);

        return IngredientResource::make($ingredient)->withDetails();
    }
}
