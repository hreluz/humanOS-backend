# SPEC 20 — Recipes inside recipes

> **Status:** Draft
> **Depends on:** SPEC 19
> **Date:** 2026-11-05
> **Objective:** Let a recipe line be a number of servings of another of the user's recipes, one level deep, with its macros taken from that recipe's per-serving values.

## Why this spec exists

SPEC 19 lines are always ingredients. Real cooking reuses preparations: you save "Homemade tomato sauce" (tomatoes, onion, olive oil, 4 servings), then build "Spaghetti bolognese" and want to add "1.5 servings of Homemade tomato sauce" next to the pasta and beef, instead of re-entering the sauce's ingredients. Bolognese's macros include the sauce's, and editing the sauce changes the bolognese. SPEC 19 left this out because it brings cycles, recursive macros and delete rules. This spec settles them by allowing one level only.

## Scope

**In:**

- A recipe line that references another recipe of the caller's, with a `quantity` in servings of that recipe.
- One level of nesting: a recipe used inside another can't contain recipes, and a recipe that contains recipes can't be used inside another.
- The sub-recipe's macros computed on read, as `quantity × per_serving` of the sub-recipe.
- A deleted sub-recipe kept on the lines that use it, flagged `deleted: true`, like SPEC 19's deleted ingredients.
- A `type` on every line in the detail response (`ingredient` or `recipe`).
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- More than one level of nesting, and cycle detection.
- A sub-recipe measured in grams, which needs a cooked batch weight stored on the recipe ("weigh the finished batch").
- Expanding a sub-recipe's lines inline in the parent's response.
- Using another user's or a shared recipe as a line (the sharing spec).
- Frontend changes.

## Data model

### Migration

```php
// database/migrations/<date>_add_sub_recipe_id_to_recipe_ingredients_table.php
Schema::table('recipe_ingredients', function (Blueprint $table) {
    $table->foreignId('ingredient_id')->nullable()->change();
    $table->decimal('grams', 7, 2)->nullable()->change();
    $table->foreignId('sub_recipe_id')->nullable()->after('ingredient_id')
        ->constrained('recipes')->restrictOnDelete();
});
```

- A line has exactly one of `ingredient_id` and `sub_recipe_id`. The models and requests enforce it. A `CHECK` constraint is added too where the database supports it (MySQL 8.0.16+).
- On a recipe line, `ingredient_portion_id` and `grams` are `null`, and `quantity` is in servings of the sub-recipe.
- The rollback deletes recipe lines, then drops `sub_recipe_id` and makes `ingredient_id` and `grams` `NOT NULL` again.

### Models

- `RecipeIngredient::subRecipe()`: `belongsTo(Recipe, 'sub_recipe_id')`, `withTrashed()`.
- `RecipeIngredient::isRecipe(): bool`.
- `Recipe::usedIn()`: the lines that reference this recipe, used by the nesting checks.

### Macros

- A recipe line's macros are `quantity × per_serving` of the sub-recipe, computed from the sub-recipe's current lines with SPEC 19's rules.
- One level deep means the sub-recipe's lines are all ingredients, so no recursion is needed.
- A soft-deleted sub-recipe counts with its lines as they were when it was deleted. Its lines aren't removed while it's soft-deleted.
- `fiber` follows SPEC 19: `null` when the sub-recipe's `per_serving.fiber` is `null`.

## Endpoints

No new endpoints. `POST /recipes` and `PATCH /recipes/{key}` accept recipe lines, and every response that has lines shows them.

### Request lines

```json
{
  "ingredients": [
    { "ingredient": "spaghetti_dry", "quantity": 280 },
    { "recipe": "r_01jc8k2d4x7q9m3n5p6r8s0t2v", "quantity": 1.5 }
  ]
}
```

| Field | Rule |
|---|---|
| `ingredients.*` | exactly one of `ingredient` and `recipe` (422 on the item otherwise) |
| `ingredients.*.recipe` | the key of one of the caller's recipes, not soft-deleted, and not the recipe being edited |
| `ingredients.*.portion` | prohibited when `recipe` is sent |
| `ingredients.*.quantity` | for a recipe line: more than 0, at most 100, at most 2 decimals |
| nesting | the referenced recipe has no recipe lines (422 on `recipe`) |
| nesting | a recipe that is used inside another can't receive recipe lines (422 on `ingredients`) |
| duplicates | the same `recipe` twice gives 422 (error on the second item's `recipe`) |

- Another user's recipe gives the same 422 as an unknown key.
- The 1–50 line limit from SPEC 19 counts both kinds of line.
- **Deleted sub-recipe in a `PATCH`:** a line may reference a soft-deleted recipe only when the recipe's current lines already use it, as SPEC 19 allows for ingredients.

### Response lines

```json
{
  "type": "recipe",
  "recipe": { "key": "r_01jc8k2d4x7q9m3n5p6r8s0t2v", "name": "Homemade tomato sauce", "servings": 4, "deleted": false },
  "quantity": 1.5,
  "macros": { "kcal": 180.0, "protein": 4.1, "carbs": 22.5, "fat": 8.3, "fiber": 4.8 }
}
```

- Ingredient lines gain `"type": "ingredient"` and keep the SPEC 19 shape.
- Recipe lines have no `ingredient`, `portion` or `grams`.
- The sub-recipe's own lines aren't included. The client gets them with `GET /recipes/{key}` on the sub-recipe.
- The detail runs a fixed number of queries, whatever the number of lines of either kind.
- In the list, `ingredient_count` counts both kinds of line, and the aggregate query adds the sub-recipes' per-serving macros. It still runs a fixed number of queries per page.
- This is a contract change to SPEC 19's response shape (`type`, and the recipe line). SPEC 19's detail shape gets updated in place, following the CLAUDE.md rule.

### Deleting a recipe used inside another

- `DELETE /recipes/{key}` is unchanged. The `RESTRICT` key on `sub_recipe_id` makes `CatalogRemover` soft-delete it.
- The parent's line stays, with `recipe.deleted: true`, and still counts in the macros.
- Once no line references it, the next removal attempt can hard-delete it. No job does this in this spec.

## Files

```
app/Modules/Nutrition/
  Models/Recipe.php                              (usedIn)
  Models/RecipeIngredient.php                    (subRecipe, isRecipe)
  Http/Requests/StoreRecipeRequest.php           (recipe lines, nesting rules)
  Http/Requests/UpdateRecipeRequest.php          (recipe lines, nesting rules, deleted sub-recipes)
  Http/Resources/RecipeIngredientResource.php    (type, recipe line)
  Support/RecipeMacros.php                       (recipe lines)
  Http/Controllers/RecipeController.php          (eager loading, list aggregate)
database/migrations/<date>_add_sub_recipe_id_to_recipe_ingredients_table.php
docs/openapi/v1.json
specs/19-recipes.md                              (header note, detail shape)
CLAUDE.md
tests/Feature/Nutrition/NestedRecipeApiTest.php
```

Plus additions to `RecipeShowApiTest`, `RecipeListApiTest`, `RecipeDeleteApiTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-19-recipes`, and rebase onto `master` once SPEC 19 is merged.

1. **Schema and models.** Add the migration, `subRecipe`, `isRecipe` and `usedIn`. Tests:
   - `migrate` and `migrate:rollback` work, and existing SPEC 19 lines are unchanged;
   - deleting a recipe referenced through `sub_recipe_id` is blocked by the foreign key at the database level.
2. **Macros and responses.** Extend `RecipeMacros` and `RecipeIngredientResource`, and eager-load sub-recipes with their lines and ingredients. Tests, with lines created directly in the database:
   - a recipe line's macros are `quantity × per_serving` of the sub-recipe, checked against hand-computed values;
   - ingredient lines have `"type": "ingredient"` and recipe lines `"type": "recipe"` with the summary shape;
   - editing the sub-recipe's lines changes the parent's macros on the next read;
   - the detail runs the same number of queries with 1 and 10 recipe lines;
   - the list's `totals` match the detail's for a recipe with both kinds of line, and its query count doesn't grow with the page size.
3. **Create and update.** Extend both requests. Tests in `NestedRecipeApiTest`:
   - a recipe line is created and returned;
   - each rule gives 422: both or neither of `ingredient` and `recipe`, a `portion` on a recipe line, an unknown, another user's or a soft-deleted recipe, the recipe itself, a quantity of 0, 101 or 3 decimals, the same recipe twice, a sub-recipe that has recipe lines, and recipe lines on a recipe that is used inside another;
   - 50 lines mixing both kinds are accepted, and 51 give 422.
4. **Deleted sub-recipes.** Tests, extending `RecipeDeleteApiTest`:
   - deleting a recipe used inside another returns `204` and soft-deletes it;
   - the parent's line shows `recipe.deleted: true` and still counts in the macros;
   - the parent's `PATCH` accepts the line again, and a new recipe can't add the deleted one;
   - the deleted recipe gives 404 on its own endpoints and leaves the list.
5. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that a line is a `oneOf` of the two shapes, or a single shape with `type` and nullable fields if Scramble can't express it. Extend `OpenApiDocumentTest`. Update SPEC 19's detail shape in place and add to its header: `> **Note:** lines gain type, and a line can be a recipe, by SPEC 20.` Update `CLAUDE.md`.
6. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] A recipe line referencing another of the caller's recipes, with a quantity in servings, can be created and edited.
- [ ] A recipe line's macros equal `quantity × per_serving` of the sub-recipe, and follow edits to the sub-recipe.
- [ ] A sub-recipe that has recipe lines, a recipe used inside another receiving recipe lines, the recipe itself, another user's recipe and a repeated recipe all give 422.
- [ ] Every line in a response has `type`, and recipe lines have the summary shape without their own lines.
- [ ] Deleting a recipe used inside another soft-deletes it, and the parent keeps the line with `deleted: true`, counted in the macros.
- [ ] The detail and list query counts don't grow with the number of recipe lines or the page size.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 19 has its header note and updated detail shape, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** quantity in servings of the sub-recipe. It works with what SPEC 19 stores, and the frontend already treats a recipe as "1 serving". **No:** grams, which need a cooked batch weight. **No:** both, for the same reason.
- **Yes:** one level only. It covers sauces, doughs and spice mixes inside a dish, needs no cycle detection and no recursion, and keeps the list's aggregate query. **No:** any depth with a limit or without one, which needs cycle checks on every save and recursive macros. It can come later without changing the data.
- **Yes:** a deleted sub-recipe soft-deleted, kept and flagged, as SPEC 19 does for ingredients. The client handles every deleted line the same way. **No:** a 409 that blocks the delete. **No:** dropping the line, which silently changes the parent.
- **Yes:** a short recipe summary on the line, with the sub-recipe's lines fetched separately. The detail stays flat with a fixed query count. **No:** expanding the lines inline.
- **Yes:** a nullable `sub_recipe_id` on `recipe_ingredients`, with exactly one of the two references set. One table keeps the line order and the 50-line limit in one place. **No:** a separate `recipe_sub_recipes` table, which splits the order across two tables.
- **Yes:** `quantity` at most 100 servings for a recipe line. It's far above any real use and keeps values bounded.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| A third level slips in through an edit (the sauce gains a recipe line while bolognese uses it). | Both directions are checked in the requests, and tests cover each. Two concurrent saves could still race. The checks run inside the save's transaction, with the referenced recipes locked for update. |
| Soft-deleted sub-recipes pile up because nothing retries the hard delete. | They're few and small. A cleanup job can come with the meal-logging spec, which adds more `RESTRICT` references. |
| Scramble can't express the two line shapes as a `oneOf`. | Step 5 falls back to one shape with `type` and nullable fields, documented per field. |

## What is **not** in this spec

- More than one level of nesting.
- Sub-recipes in grams.
- Inline expansion of a sub-recipe's lines.
- Using another user's or a shared recipe.
- Frontend changes.
