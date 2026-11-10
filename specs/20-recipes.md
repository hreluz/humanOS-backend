# SPEC 20 — Recipes

> **Status:** Draft
> **Depends on:** SPEC 04, SPEC 15, SPEC 16, SPEC 18
> **Date:** 2026-11-05
> **Objective:** Let a user create, list, view, edit and delete their own private recipes, made of a name, a number of servings and ingredient lines, with macros computed from the ingredients.

## Why this spec exists

The frontend already builds recipes (`/food/recipes/new`) against mocks: a name, servings from 1 to 12, and lines of `{ ingredient, amount }`, saved to My foods and logged as one serving. The backend has the ingredients (SPEC 15, SPEC 18) but nowhere to save a recipe. CLAUDE.md already fixes how a recipe must reference the catalog: by id, with `ON DELETE RESTRICT`, storing the grams at save time.

## Scope

**In:**

- `POST`, `GET {key}`, `PATCH {key}` and `DELETE {key}` on `/api/v1/nutrition/recipes`, and a paginated `GET /api/v1/nutrition/recipes`.
- A recipe holds `name`, `servings` and 1–50 ingredient lines.
- A line references an ingredient, an optional portion, a `quantity`, and the `grams` computed when it was saved.
- Macros computed on every read: per line, `totals` and `per_serving`.
- Lines whose ingredient or portion was soft-deleted are kept and flagged `deleted: true`.
- Private recipes: another user's recipe is a 404 everywhere.
- Deletion through `CatalogRemover`, so a recipe referenced later by meal logs is soft-deleted.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- **Recipes inside recipes** (SPEC 21). One saved recipe used as a line of another. Example: you save "Homemade tomato sauce" (tomatoes, onion, olive oil, 4 servings), then build "Spaghetti bolognese" and add "1 serving of Homemade tomato sauce" as a line next to the pasta and beef, instead of re-entering the sauce's ingredients. Bolognese's macros include the sauce's per-serving macros, and editing the sauce changes the bolognese. It needs cycle checks (A can't contain B if B contains A), recursive macros, and delete rules. The frontend's picker also leaves recipes out today.
- **Instructions, notes or prep steps** (SPEC 22).
- **Photos** of a recipe (SPEC 24, on SPEC 23's uploads).
- **Cost and prices** (SPEC 25). The backend has no prices yet.
- **Logging a recipe** (SPEC 26, without meal slots). Meal slots and plain-ingredient entries come with the meal-logging specs.
- **Sharing recipes** with other users (SPEC 27).
- **Recipes in a food search** next to ingredients. No spec yet.
- Rate limiting and a cap on recipes per user.
- Frontend changes.

## Data model

### Migrations

```php
// database/migrations/<date>_create_recipes_table.php
Schema::create('recipes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('key', 28)->unique();
    $table->string('name', 100);
    $table->unsignedTinyInteger('servings');
    $table->timestamps();
    $table->softDeletes();
    $table->index(['user_id', 'created_at']);
});

// database/migrations/<date>_create_recipe_ingredients_table.php
Schema::create('recipe_ingredients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
    $table->foreignId('ingredient_portion_id')->nullable()->constrained()->restrictOnDelete();
    $table->decimal('quantity', 7, 2);
    $table->decimal('grams', 7, 2);
    $table->unsignedSmallInteger('position');
    $table->index(['recipe_id', 'position']);
});
```

- `key` is `r_` + lowercase ULID (28 characters), generated on create, never changed.
- `name` is 1–100 characters after trimming, not unique.
- `servings` is an integer from 1 to 12.
- `quantity` is grams when `ingredient_portion_id` is `null`, and a count of that portion otherwise.
- `grams` is `quantity` (no portion) or `quantity × portion.grams`, rounded to 2 decimals, computed when the line is written. It doesn't change when the portion's grams are edited later. Saving the lines again recomputes it.
- `position` follows the order the client sends, starting at 1.
- Lines have no keys. Nothing references a single line.
- The duplicate rule (same ingredient and portion twice) is checked in the request, since MySQL's unique index treats `null` portions as distinct.

### Models

- `app/Modules/Nutrition/Models/Recipe.php`: `SoftDeletes`, `belongsTo(User)`, `hasMany(RecipeIngredient)` ordered by `position`, `scopeOwnedBy(User $user)`, `r_` key generation.
- `app/Modules/Nutrition/Models/RecipeIngredient.php`: `belongsTo(Ingredient)` and `belongsTo(IngredientPortion)`, both `withTrashed()`. No timestamps.
- Route binding for `{recipe:key}` resolves through `Recipe::ownedBy` the authenticated user (`Route::bind` in `NutritionServiceProvider`). Another user's recipe, or a soft-deleted one, is a 404 before the controller runs. There's no policy: every recipe is private, so if the binding resolves, the caller owns it.
- `User` gets a `deleting` hook that removes the user's recipes first. Without it, the user's ingredients (cascading from `users`) could be blocked by the `RESTRICT` key from `recipe_ingredients` before the recipes' cascade removes those lines.

### Macros

- A line's macros are `grams × ingredient.per_100g / 100`, for `kcal`, `protein`, `carbs`, `fat` and `fiber`, using the ingredient's current values, soft-deleted or not.
- `totals` sums the lines. `per_serving` is `totals / servings`.
- `fiber`: a line's fiber is `null` when the ingredient's fiber is `null`. `totals.fiber` sums the lines that have it, and is `null` only when no line has it.
- Values are returned rounded to 1 decimal. Sums use the unrounded values.

## Endpoints

All of them require a bearer token (401 otherwise, in the SPEC 04 envelope) and live under `api/v1/nutrition`.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/recipes` | `201`, the detail shape | 422 |
| `GET` | `/recipes` | `200`, SPEC 04 paginated list | 422 |
| `GET` | `/recipes/{key}` | `200`, the detail shape | 404 |
| `PATCH` | `/recipes/{key}` | `200`, the detail shape | 404, 422 |
| `DELETE` | `/recipes/{key}` | `204`, no body | 404 |

### `POST /recipes` (`StoreRecipeRequest`)

```json
{
  "name": "Overnight oats",
  "servings": 1,
  "ingredients": [
    { "ingredient": "oats", "quantity": 40 },
    { "ingredient": "banana", "portion": "medium", "quantity": 1 }
  ]
}
```

| Field | Rule |
|---|---|
| `name` | required, string, 1–100 characters after trimming |
| `servings` | required, integer, 1–12 |
| `ingredients` | required, list of 1–50 items |
| `ingredients.*.ingredient` | required, the key of an ingredient visible to the caller (catalog or own) and not soft-deleted |
| `ingredients.*.portion` | optional, nullable, the key of a portion of that ingredient, not soft-deleted |
| `ingredients.*.quantity` | required, number, more than 0, at most 2 decimals; at most 5000 without a portion, at most 100 with one |
| line grams | at most 5000 (error on `quantity`) |
| duplicates | the same `ingredient` and `portion` twice gives 422 (error on the second item's `ingredient`) |

- The same ingredient with a different portion, or once with and once without a portion, is allowed.
- Another user's ingredient gives the same 422 as an unknown key.
- The recipe and its lines are written in one transaction.

### `PATCH /recipes/{key}` (`UpdateRecipeRequest`)

- Every field is optional. A field that isn't sent keeps its stored value. None can be `null`.
- Each field sent follows the create rule, with one exception below.
- `ingredients` is all or nothing:
  - absent: lines don't change, and their `grams` stay as stored;
  - present: it's the full new list (1–50 items). The old lines are deleted and the new ones inserted in one transaction, with `grams` recomputed from the current portions and `position` from the list order.
- **Exception for deleted rows:** a line may reference a soft-deleted ingredient, or a soft-deleted portion, only when the recipe's current lines already use that same ingredient and portion pair. Its grams are computed from the soft-deleted portion. Anything else soft-deleted gives 422, as on create.

### `DELETE /recipes/{key}`

- `CatalogRemover::remove(Recipe::whereKey($recipe->id))`. Nothing references a recipe today, so it's hard-deleted, with lines cascading. Once meal logs reference recipes with `ON DELETE RESTRICT`, it's soft-deleted instead, with no change here.
- Afterwards it's a 404 on every endpoint and leaves the list.

### `GET /recipes` (`ListRecipesRequest`)

| Parameter | Rule | Meaning |
|---|---|---|
| `page` | integer ≥ 1 | Page number |
| `per_page` | integer 1–100 | Page size (default 50) |

- The caller's recipes, not soft-deleted, newest first (`created_at` desc, then `key` desc), as `RecipeResource::collection($paginator)`.
- Items have the list shape: no `ingredients`, plus `ingredient_count`.
- Macros come from one aggregate query per page (sums over lines joined to ingredients, soft-deleted included), not from loading the lines. The query count doesn't grow with the page size.

### Detail shape

```json
{
  "data": {
    "key": "r_01jc8k2d4x7q9m3n5p6r8s0t2v",
    "name": "Overnight oats",
    "servings": 1,
    "totals": { "kcal": 257.4, "protein": 8.0, "carbs": 46.2, "fat": 3.2, "fiber": 6.9 },
    "per_serving": { "kcal": 257.4, "protein": 8.0, "carbs": 46.2, "fat": 3.2, "fiber": 6.9 },
    "ingredients": [
      {
        "ingredient": { "key": "oats", "name": "Oats", "category": "grains", "is_mine": false, "deleted": false },
        "portion": null,
        "quantity": 40,
        "grams": 40,
        "macros": { "kcal": 152.0, "protein": 5.3, "carbs": 27.0, "fat": 2.6, "fiber": 4.0 }
      },
      {
        "ingredient": { "key": "banana", "name": "Banana", "category": "fruit", "is_mine": false, "deleted": false },
        "portion": { "key": "medium", "label": "1 medium", "grams": 118, "deleted": false },
        "quantity": 1,
        "grams": 118,
        "macros": { "kcal": 105.4, "protein": 2.7, "carbs": 19.2, "fat": 0.6, "fiber": 2.9 }
      }
    ],
    "created_at": "2026-11-05T10:00:00Z",
    "updated_at": "2026-11-05T10:00:00Z"
  }
}
```

- `portion.grams` is the portion's current grams. The line's `grams` is what was stored, so the two can differ after the portion is edited.
- The ingredient summary is English only, like SPEC 16.
- The list shape is `key`, `name`, `servings`, `totals`, `per_serving`, `ingredient_count`, `created_at`, `updated_at`.

## Files

```
app/Modules/Nutrition/
  Models/Recipe.php
  Models/RecipeIngredient.php
  Http/Controllers/RecipeController.php          (index, store, show, update, destroy)
  Http/Requests/StoreRecipeRequest.php
  Http/Requests/UpdateRecipeRequest.php
  Http/Requests/ListRecipesRequest.php
  Http/Resources/RecipeResource.php
  Http/Resources/RecipeIngredientResource.php
  Support/RecipeMacros.php                       (line macros, totals, per serving)
  Support/CatalogRemover.php                     (docblock only)
  NutritionServiceProvider.php                   (route binding)
  routes.php
app/Models/User.php                              (deleting hook)
database/migrations/<date>_create_recipes_table.php
database/migrations/<date>_create_recipe_ingredients_table.php
docs/openapi/v1.json
CLAUDE.md
tests/Feature/Nutrition/RecipeStoreApiTest.php
tests/Feature/Nutrition/RecipeShowApiTest.php
tests/Feature/Nutrition/RecipeUpdateApiTest.php
tests/Feature/Nutrition/RecipeDeleteApiTest.php
tests/Feature/Nutrition/RecipeListApiTest.php
```

Plus additions to `OpenApiDocumentTest`, `UserIngredientDeleteApiTest` and `UserIngredientUpdateApiTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-18-user-ingredients`, and rebase onto `master` once SPEC 18 is merged.

1. **Schema and models.** Add both migrations, `Recipe`, `RecipeIngredient`, the `r_` key generation and the `User` deleting hook. Tests:
   - `migrate` and `migrate:rollback` work;
   - deleting a user with recipes that use their own ingredients succeeds, and removes the recipes, lines and ingredients.
2. **Create and detail.** Add `RecipeMacros`, `StoreRecipeRequest`, both resources, `store`, `show`, the route binding and the routes. Tests in `RecipeStoreApiTest` and `RecipeShowApiTest`:
   - 401 without a token;
   - `201` with the detail shape, an `r_` key of 28 characters, and lines in the order sent;
   - grams: 40 with no portion, and `quantity × portion.grams` with one;
   - macros per line, `totals` and `per_serving` (servings 2 halves the totals), checked against hand-computed values;
   - `fiber` `null` on a line whose ingredient has no fiber, `totals.fiber` summing the others, and `null` when no line has it;
   - the caller's own ingredient works with `is_mine: true`;
   - each rule gives 422: missing fields, a name of 0 or 101 characters after trimming, servings of 0 or 13, 0 or 51 lines, an unknown, another user's, or a soft-deleted ingredient, a portion of a different ingredient, a soft-deleted portion, a quantity of 0, 5001 without a portion, 101 with one, 3 decimals, line grams over 5000, and the same ingredient and portion twice;
   - the same ingredient with two different portions is accepted;
   - detail returns the caller's recipe, and another user's or an unknown key gives 404;
   - detail runs the same number of queries for 2 and 20 lines.
3. **Deleted ingredients and portions.** Tests, extending `UserIngredientDeleteApiTest` and `UserIngredientUpdateApiTest`:
   - deleting the caller's own ingredient used by a recipe soft-deletes it, and the recipe's detail shows the line with `ingredient.deleted: true`, still counted in the macros;
   - a portion dropped in an ingredient `PATCH` while a recipe uses it is soft-deleted, and the line shows `portion.deleted: true`;
   - editing a portion's grams leaves the recipe line's `grams` unchanged, while `portion.grams` shows the new value.
4. **Update.** Add `UpdateRecipeRequest`, `update` and the route. Tests in `RecipeUpdateApiTest`:
   - 401 without a token, and 404 on another user's or an unknown key;
   - one field sent changes only that field, and `name: null` gives 422;
   - `ingredients` absent leaves lines and their stored grams unchanged;
   - `ingredients` present replaces the lines, recomputes grams from current portions, and renumbers positions;
   - a line with a soft-deleted ingredient or portion is accepted when the recipe already used that pair, and gives 422 when it didn't;
   - the 422 rules from create apply to fields sent.
5. **Delete.** Add `destroy` and the route. Tests in `RecipeDeleteApiTest`:
   - 401 without a token, and 404 on another user's or an unknown key;
   - `204` with no body, and the recipe and its lines are gone from the database;
   - afterwards, detail gives 404 and the list doesn't return it;
   - a recipe referenced through a `RESTRICT` foreign key (a test-only table, as in `CatalogRemover`'s tests) is soft-deleted, and still gives 404.
6. **List.** Add `ListRecipesRequest`, `index` and the route. Tests in `RecipeListApiTest`:
   - 401 without a token;
   - only the caller's recipes, newest first, with `ingredient_count`, `totals` and `per_serving` matching the detail, and no `ingredients`;
   - default page of 50, `per_page=100` works, `per_page=101` and `page=0` give 422, and `meta.total` equals the caller's count;
   - a user with none gets `200` with `data: []`;
   - 5 and 15 recipes run the same number of queries.
7. **Docs and records.** PHPDoc and `@example`s per `docs/code-conventions.md`, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that the five operations sit under `Nutrition` with their 201/204/401/404/422 responses, and that `fiber`, `portion` are nullable. Extend `OpenApiDocumentTest`. Update `CatalogRemover`'s docblock to mention recipes. Update `CLAUDE.md`: recipes, their endpoints, stored grams, live macros and deleted lines.
8. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `POST /recipes` creates a private recipe with an `r_` key and its lines, and returns `201` with the detail shape.
- [ ] Each line stores `quantity` and `grams`, where `grams` is the quantity, or quantity × portion grams, at save time.
- [ ] Creating rejects servings outside 1–12, 0 or more than 50 lines, an ingredient the caller can't see or that is deleted, a portion of another ingredient, an invalid quantity, and a repeated ingredient and portion, with 422.
- [ ] Detail returns per-line macros, `totals` and `per_serving` computed from the ingredients' current per-100 g values.
- [ ] `PATCH` changes only the fields sent, and with `ingredients` replaces every line and recomputes grams.
- [ ] A line whose ingredient or portion was soft-deleted is kept, returned with `deleted: true`, counted in the macros, and can be re-sent in a `PATCH` of that recipe.
- [ ] Editing a portion's grams doesn't change a recipe line's stored `grams`.
- [ ] `DELETE` returns `204`, and the recipe is hard-deleted, or soft-deleted when a `RESTRICT` foreign key references it.
- [ ] `GET /recipes` returns only the caller's recipes, newest first, paginated (default 50, max 100), with a query count that doesn't grow with the page size.
- [ ] Another user's recipe gives 404 on detail, update and delete.
- [ ] Deleting a user who has recipes using their own ingredients succeeds.
- [ ] `docs/openapi/v1.json` is re-exported with the five operations, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** full CRUD in this spec. **No:** create, list and detail only. The frontend's next steps are edit and delete, and SPEC 18 set the pattern.
- **Yes:** a line stores `ingredient_id`, an optional portion, a `quantity` and the `grams` at save time. "2 eggs" stays readable when editing, and CLAUDE.md requires stored grams with `RESTRICT` keys. **No:** grams only. The frontend's `ml`, `piece` and `serving` units become portions.
- **Yes:** macros computed on every read from current per-100 g values. A fixed label updates the recipes that use it. **No:** a snapshot at save time. Freezing values matters for logged meals, which the meal-logging spec decides.
- **Yes:** servings 1–12, 1–50 lines, and a 422 for the same ingredient and portion twice. It matches the frontend, which merges repeats. **No:** repeated lines.
- **Yes:** a soft-deleted ingredient or portion keeps its line, flagged `deleted: true` and counted in the macros. **No:** a 409 blocking the delete, which would stop users cleaning up their ingredients. **No:** dropping the line from the macros, which would silently change the recipe.
- **Yes:** stored grams stay when a portion's grams are edited, and saving the lines again recomputes them. It follows CLAUDE.md. **No:** recomputing on read. **No:** rewriting every recipe line when a portion changes.
- **Yes:** `PATCH ingredients` replaces the whole list, with no line keys. Nothing references a line, and the client already holds the full list. **No:** line keys matched as in SPEC 18 portions, which only pay off when something references a line.
- **Yes:** a `PATCH` may re-send a soft-deleted ingredient or portion already on that recipe. Otherwise deleting an ingredient would block every edit of the recipes that use it. New lines can't use deleted rows.
- **Yes:** the list is newest first, without lines, with macros from one aggregate query. It matches the frontend's My foods order and keeps the list cheap. **No:** sorted by name. **No:** full items with lines.
- **Yes:** `r_<ulid>` keys and names that aren't unique, as in SPEC 18.
- **Yes:** private recipes enforced by the route binding, with no policy. There are no catalog recipes, so there's no 403 case.
- **Yes:** recipes soft-deletable and removed through `CatalogRemover`, so meal logs can later reference them with `RESTRICT` without changing this spec.
- **Yes:** the out-of-scope items each get their own spec. SPEC 21 to SPEC 27 were written right after this one. Recipes in a food search has no spec yet.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Deleting a user fails because `recipe_ingredients` restricts the user's ingredients during the cascade. | The `User` deleting hook removes recipes first, and a test covers it. |
| A missing ownership check leaks another user's recipe. | Ownership lives in the route binding, and tests cover detail, update, delete and the list with a second user's recipe. |
| Stored grams go stale after a portion's grams are fixed. | The response returns the portion's current grams next to the line's stored grams, so the client can show the difference. Saving the lines again recomputes them. |
| The list's macros drift from the detail's. | Both use `RecipeMacros` rules, and a test compares them for the same recipe. |
| A soft-deleted catalog ingredient removed by the seeder stays in recipes forever. | It's flagged `deleted: true`, and the user can replace it. `CatalogRemover` hard-deletes it once nothing references it. |

## What is **not** in this spec

- Recipes inside recipes, instructions, photos, cost, logging a recipe to a meal, sharing, and recipes in ingredient search (SPEC 21 to SPEC 27, except food search, which has no spec yet).
- Rate limiting and a cap on recipes per user.
- Frontend changes.
