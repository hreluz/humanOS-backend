<?php

namespace App\Modules\Nutrition\Http\Controllers;

use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Http\Requests\ListMyIngredientsRequest;
use App\Modules\Nutrition\Http\Requests\SearchIngredientsRequest;
use App\Modules\Nutrition\Http\Requests\StoreIngredientRequest;
use App\Modules\Nutrition\Http\Requests\UpdateIngredientRequest;
use App\Modules\Nutrition\Http\Resources\IngredientResource;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPortion;
use App\Modules\Nutrition\Support\CatalogRemover;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

#[Group('Nutrition')]
class IngredientController
{
    /**
     * The macro fields a user's ingredient stores, all per 100 g.
     *
     * @var list<string>
     */
    private const MACROS = ['kcal', 'protein', 'carbs', 'fat', 'fiber'];

    /**
     * Search ingredients.
     *
     * Returns every ingredient whose English name or English alias contains `search`, ignoring case and
     * accents, in one unpaginated list: the catalog plus the caller's own ingredients, never another
     * user's. A user's ingredient matches on its name only. Ranked by the name: an exact match first, then
     * names starting with the term, then names with a word starting with it, then the rest (mid-word or
     * alias-only matches); ties sort by name, then key. The caller's own ingredients get no priority and are
     * marked by `is_mine`. Values are per 100 g. `nutrients` and `source` are only returned by the detail
     * endpoint. Each ingredient's first photo is its main photo; use its `thumbnail_url` in lists.
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
     * List my ingredients.
     *
     * Returns the caller's own ingredients, paginated and sorted by name, then key. Items have the search
     * shape: `nutrients` and `source` are only returned by the detail endpoint.
     */
    public function mine(ListMyIngredientsRequest $request): AnonymousResourceCollection
    {
        $ingredients = Ingredient::ownedBy($request->user())
            ->with(['portions', 'photos'])
            ->orderBy('name')
            ->orderBy('key')
            ->paginate($request->perPage())
            ->withQueryString();

        return IngredientResource::collection($ingredients);
    }

    /**
     * Show an ingredient.
     *
     * Returns one ingredient by its key, with every measured nutrient per 100 g and the data source. A
     * nutrient that wasn't measured is left out; clients show it as "no data". Another user's ingredient
     * is a 404. A user's ingredient has no nutrients or photos yet, and its source is `user` with a `null` id.
     *
     * @throws ModelNotFoundException
     */
    public function show(Ingredient $ingredient): IngredientResource
    {
        $ingredient->load(['portions', 'photos', 'nutrients']);

        return IngredientResource::make($ingredient)->withDetails();
    }

    /**
     * Create an ingredient.
     *
     * Creates a private ingredient owned by the caller, with its macros per 100 g as on a food label and
     * up to 10 portions. The ingredient gets a generated `u_` key and each portion a generated `p_` key;
     * neither changes afterwards. Returns the detail shape.
     *
     * @status 201
     */
    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $ingredient = DB::transaction(function () use ($request, $data) {
            $ingredient = Ingredient::create([
                'user_id' => $request->user()->getKey(),
                'key' => Ingredient::generateUserKey(),
                'name' => $data['name'],
                'category' => $data['category'],
                ...array_intersect_key($data, array_flip(self::MACROS)),
                'fiber' => $data['fiber'] ?? null,
                'source' => IngredientSource::User,
                'source_id' => null,
            ]);

            $this->syncPortions($ingredient, $data['portions'] ?? []);

            return $ingredient;
        });

        return $this->detail($ingredient)->response()->setStatusCode(201);
    }

    /**
     * Update an ingredient.
     *
     * Changes only the fields sent; `fiber: null` clears the fiber. The sum rule is checked with stored
     * values filling the fields not sent. `portions`, when sent, is the full new list: items with a `key`
     * update that portion, items without one are new portions, and portions left out are removed. Only the
     * owner may edit: a catalog ingredient is a 403, another user's is a 404. Returns the detail shape.
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): IngredientResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($ingredient, $data) {
            $ingredient->update(array_intersect_key($data, array_flip(['name', 'category', ...self::MACROS])));

            if (array_key_exists('portions', $data)) {
                $this->syncPortions($ingredient, $data['portions']);
            }
        });

        return $this->detail($ingredient);
    }

    /**
     * Delete an ingredient.
     *
     * Deletes the caller's own ingredient and its portions. It's gone from every endpoint afterwards. Only
     * the owner may delete: a catalog ingredient is a 403, another user's is a 404.
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function destroy(Ingredient $ingredient): Response
    {
        Gate::authorize('delete', $ingredient);

        // Hard-deleted today, soft-deleted once something references it with `ON DELETE RESTRICT`.
        CatalogRemover::remove(Ingredient::whereKey($ingredient->getKey()));

        return response()->noContent();
    }

    /**
     * Makes the ingredient's portions match the list: an item with a `key` updates that portion, an item
     * without one becomes a new portion with a generated key, and a current portion left out is removed through
     * `CatalogRemover`. Positions follow the list, from 1.
     *
     * @param  array<int, array{key?: string, label: string, grams: int|float|string}>  $items
     */
    private function syncPortions(Ingredient $ingredient, array $items): void
    {
        // `validated()` builds the list in rule order, so an item with a `key` can come before one without:
        // the indexes, not the array order, hold the order the client sent.
        ksort($items);

        $current = $ingredient->portions()->get()->keyBy('key');
        // Every key the ingredient ever had: a soft-deleted portion still holds its key.
        $taken = IngredientPortion::withTrashed()->where('ingredient_id', $ingredient->getKey())->pluck('key')->all();
        $kept = [];

        foreach (array_values($items) as $i => $item) {
            $values = ['label' => $item['label'], 'grams' => $item['grams'], 'position' => $i + 1];

            if (isset($item['key'])) {
                $portion = $current[$item['key']];
                $portion->update($values);
            } else {
                $key = IngredientPortion::generateUserKey($taken);
                $taken[] = $key;
                $portion = $ingredient->portions()->create(['key' => $key, ...$values]);
            }

            $kept[] = $portion->getKey();
        }

        CatalogRemover::remove(IngredientPortion::where('ingredient_id', $ingredient->getKey())->whereNotIn('id', $kept));
    }

    /**
     * The detail shape, read back from the database so values come at the columns' precision.
     */
    private function detail(Ingredient $ingredient): IngredientResource
    {
        $ingredient->refresh()->load(['portions', 'photos', 'nutrients']);

        return IngredientResource::make($ingredient)->withDetails();
    }
}
