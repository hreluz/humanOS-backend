# SPEC 26 — Recipe logs

> **Status:** Draft
> **Depends on:** SPEC 04, SPEC 20, SPEC 21
> **Date:** 2026-11-05
> **Objective:** Let a user log servings of one of their recipes as eaten at a given time, with the lines of the portion eaten and their macros frozen at log time, and read back a day's entries and totals.

## Why this spec exists

The frontend's Quick log lets the user pick a saved recipe, adjust what went into the portion ("a bit less rice today"), and add it to the day. Saving recipes (SPEC 20) is only useful if eating them can be recorded. This spec records recipe entries only. Meal slots (breakfast, lunch, dinner, snacks), plain-ingredient entries and day ranges come with the meal-logging specs, on top of these tables.

## Scope

**In:**

- A recipe log entry: a recipe, the servings eaten, when it was eaten, and its own copy of the ingredient lines eaten.
- Macros frozen at log time, per line and per entry, along with the recipe, ingredient and portion names.
- `eaten_at` with its UTC offset, and a `local_date` fixed at log time.
- `POST`, `GET {key}`, `PATCH {key}` and `DELETE {key}` on `/api/v1/nutrition/recipe-logs`, and `GET /recipe-logs?date=` for one day with its totals.
- Sub-recipe lines (SPEC 21) flattened into ingredients at log time.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Meal slots (breakfast, lunch, dinner, snacks). The meal-logging specs.
- Logging a plain ingredient. The meal-logging specs, on the same tables.
- Date ranges, weekly totals and charts.
- Cost on log entries (SPEC 25 left it out).
- Targets, "remaining today", and recommendations.
- Recent and frequent recipes.
- Frontend changes.

## Data model

### Migrations

```php
// database/migrations/<date>_create_recipe_logs_table.php
Schema::create('recipe_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
    $table->string('key', 29)->unique();
    $table->string('recipe_name', 100);
    $table->decimal('servings', 4, 2);
    $table->timestamp('eaten_at');
    $table->smallInteger('utc_offset_minutes');
    $table->date('local_date');
    $table->decimal('kcal', 8, 2);
    $table->decimal('protein', 7, 2);
    $table->decimal('carbs', 7, 2);
    $table->decimal('fat', 7, 2);
    $table->decimal('fiber', 7, 2)->nullable();
    $table->timestamps();
    $table->index(['user_id', 'local_date', 'eaten_at']);
});

// database/migrations/<date>_create_recipe_log_lines_table.php
Schema::create('recipe_log_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('recipe_log_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
    $table->foreignId('ingredient_portion_id')->nullable()->constrained()->restrictOnDelete();
    $table->string('ingredient_name', 100);
    $table->string('portion_label', 50)->nullable();
    $table->decimal('quantity', 7, 2);
    $table->decimal('grams', 7, 2);
    $table->decimal('kcal', 8, 2);
    $table->decimal('protein', 7, 2);
    $table->decimal('carbs', 7, 2);
    $table->decimal('fat', 7, 2);
    $table->decimal('fiber', 7, 2)->nullable();
    $table->unsignedSmallInteger('position');
    $table->index(['recipe_log_id', 'position']);
});
```

- `key` is `rl_` + lowercase ULID (29 characters).
- `eaten_at` is stored in UTC. `utc_offset_minutes` keeps the offset the client sent, so responses return the time as the user saw it.
- `local_date` is the date of `eaten_at` at that offset, computed once on create, and again only when a `PATCH` changes `eaten_at`. Changing time zones later never moves an entry to another day.
- The entry's macros are the sum of its lines' macros at log time. `fiber` is `null` only when no line has fiber, as in SPEC 20.
- A line's macros are `grams × per_100g / 100` of the ingredient at log time.
- `recipe_name`, `ingredient_name` and `portion_label` are copied at log time, so a log reads without joins and never changes when a name is edited.
- Every reference uses `RESTRICT`, per CLAUDE.md. Deleting a logged recipe, ingredient or portion soft-deletes it through `CatalogRemover`, and the entry is unchanged.
- The `User` deleting hook (SPEC 20) also deletes the user's recipe logs before the rest, so their `RESTRICT` keys don't block the cascade.

### Models

- `app/Modules/Nutrition/Models/RecipeLog.php`: `belongsTo(Recipe)` `withTrashed()`, `hasMany(RecipeLogLine)` ordered by `position`, `scopeOwnedBy(User $user)`, `rl_` key generation.
- `app/Modules/Nutrition/Models/RecipeLogLine.php`: `belongsTo(Ingredient)` and `belongsTo(IngredientPortion)`, both `withTrashed()`. No timestamps.
- Route binding for `{recipeLog:key}` through `RecipeLog::ownedBy` the authenticated user. Another user's entry is a 404.
- `app/Modules/Nutrition/Support/RecipeLogger.php` builds the lines (scaling, flattening, merging) and the snapshot, shared by create and update.

### Building the lines

- **`ingredients` absent:** the recipe's lines are copied and scaled by `servings / recipe.servings`, applied to both `quantity` and `grams`, rounded to 2 decimals.
- **A sub-recipe line** (SPEC 21) is replaced by the sub-recipe's ingredient lines, scaled by `line quantity / sub-recipe servings`, then by the factor above.
- **Repeats after flattening** (the same ingredient and portion twice) are merged into one line by summing `quantity` and `grams`. The merged line takes the position of the first one.
- **`ingredients` sent:** the list as sent is the portion eaten. `servings` is kept as the label.
- Lines copied from the recipe can include soft-deleted ingredients or portions. They're logged as they are.

## Endpoints

All of them require a bearer token (401 otherwise) and live under `api/v1/nutrition`.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/recipe-logs` | `201`, the entry detail shape | 422 |
| `GET` | `/recipe-logs?date=` | `200`, the day shape | 422 |
| `GET` | `/recipe-logs/{key}` | `200`, the entry detail shape | 404 |
| `PATCH` | `/recipe-logs/{key}` | `200`, the entry detail shape | 404, 422 |
| `DELETE` | `/recipe-logs/{key}` | `204`, no body | 404 |

### `POST /recipe-logs` (`StoreRecipeLogRequest`)

```json
{
  "recipe": "r_01jc8k2d4x7q9m3n5p6r8s0t2v",
  "servings": 1.5,
  "eaten_at": "2026-11-05T13:10:00-05:00",
  "ingredients": [
    { "ingredient": "rice_cooked", "quantity": 120 },
    { "ingredient": "chicken_grilled", "quantity": 150 }
  ]
}
```

| Field | Rule |
|---|---|
| `recipe` | required, the key of one of the caller's recipes, not soft-deleted |
| `servings` | required, number, 0.25–12, a multiple of 0.25 |
| `eaten_at` | required, ISO 8601 date-time with a UTC offset (`Z` or `±hh:mm`), not before 2000-01-01, and at most 24 hours after the request |
| `ingredients` | optional, list of 1–50 items, ingredient lines only, with SPEC 20's line rules (visible, not soft-deleted, portion of that ingredient, quantity limits, no repeats) |
| `ingredients.*.recipe` | prohibited. Sub-recipes are only flattened from the recipe |

- Another user's recipe gives the same 422 as an unknown key.
- The entry and its lines are written in one transaction.

### `PATCH /recipe-logs/{key}` (`UpdateRecipeLogRequest`)

- `servings`, `eaten_at` and `ingredients` are optional. None can be `null`. `recipe` is prohibited, since an entry can't change recipe.
- `eaten_at` sent: `eaten_at`, `utc_offset_minutes` and `local_date` are recomputed. The macros don't change.
- `ingredients` sent: it's the full new list, and the snapshot is taken again from current per-100 g values. A line may reference a soft-deleted ingredient or portion only when the entry's current lines already use that same pair (SPEC 20's rule).
- `servings` sent without `ingredients`: the current lines are scaled by `new servings / old servings`, and the snapshot is taken again.
- Only `eaten_at` sent: the frozen macros and names stay as they are.

### `DELETE /recipe-logs/{key}`

- A hard delete, with lines cascading. Nothing references an entry.

### `GET /recipe-logs?date=` (`ShowRecipeLogDayRequest`)

| Parameter | Rule |
|---|---|
| `date` | required, `Y-m-d` |

```json
{
  "data": {
    "date": "2026-11-05",
    "totals": { "kcal": 1240.5, "protein": 92.1, "carbs": 130.4, "fat": 38.0, "fiber": 21.3 },
    "entries": [
      {
        "key": "rl_01jc8m5r7t9v1x3z5b7d9f1h3k",
        "recipe": { "key": "r_01jc8k2d4x7q9m3n5p6r8s0t2v", "name": "Chicken burrito bowl", "deleted": false },
        "servings": 1.5,
        "eaten_at": "2026-11-05T13:10:00-05:00",
        "macros": { "kcal": 820.3, "protein": 61.2, "carbs": 84.0, "fat": 24.1, "fiber": 14.0 },
        "ingredient_count": 5
      }
    ]
  }
}
```

- The caller's entries whose `local_date` is `date`, ordered by `eaten_at`, then `key`. Unpaginated.
- `totals` sums the entries' frozen macros. A day with no entries returns `entries: []` and zeros, with `fiber` `null`.
- Two queries, whatever the number of entries.

### Entry detail shape

- The day's entry fields, plus `local_date` and `ingredients`. Each line has `ingredient` (`key`, `name` as logged, `deleted`), `portion` (`key`, `label` as logged) or `null`, `quantity`, `grams` and `macros`.
- `eaten_at` is returned with the stored offset.
- `recipe.name` is the logged name. `deleted` reflects the recipe's current state.

## Files

```
app/Modules/Nutrition/
  Models/RecipeLog.php
  Models/RecipeLogLine.php
  Support/RecipeLogger.php
  Http/Controllers/RecipeLogController.php       (index, store, show, update, destroy)
  Http/Requests/StoreRecipeLogRequest.php
  Http/Requests/UpdateRecipeLogRequest.php
  Http/Requests/ShowRecipeLogDayRequest.php
  Http/Resources/RecipeLogResource.php
  Http/Resources/RecipeLogLineResource.php
  Http/Resources/RecipeLogDayResource.php
  NutritionServiceProvider.php                   (route binding)
  routes.php
app/Models/User.php                              (deleting hook removes logs first)
database/migrations/<date>_create_recipe_logs_table.php
database/migrations/<date>_create_recipe_log_lines_table.php
docs/openapi/v1.json
specs/20-recipes.md                              (header note)
CLAUDE.md
tests/Feature/Nutrition/RecipeLogStoreApiTest.php
tests/Feature/Nutrition/RecipeLogUpdateApiTest.php
tests/Feature/Nutrition/RecipeLogDayApiTest.php
tests/Feature/Nutrition/RecipeLogDeleteApiTest.php
tests/Unit/Nutrition/RecipeLoggerTest.php
```

Plus additions to `RecipeDeleteApiTest`, `UserIngredientDeleteApiTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-21-nested-recipes`, and rebase onto `master` once it's merged.

1. **Schema and models.** Add both migrations, both models, the key generation, the route binding and the `User` hook change. Tests:
   - `migrate` and `migrate:rollback` work;
   - deleting a user with recipe logs, recipes and own ingredients succeeds.
2. **Line building.** Add `RecipeLogger`. Tests in `RecipeLoggerTest`:
   - a 4-serving recipe logged at 1.5 servings scales quantities and grams by 0.375;
   - a sub-recipe line is flattened and scaled by its quantity over the sub-recipe's servings;
   - the same ingredient and portion from the parent and the sub-recipe merge into one line;
   - line and entry macros match hand-computed values, with `fiber` `null` only when no line has it;
   - names and labels are copied.
3. **Create and detail.** Add `StoreRecipeLogRequest`, the resources, `store`, `show` and the routes. Tests in `RecipeLogStoreApiTest`:
   - 401 without a token;
   - `201` with the detail shape, an `rl_` key of 29 characters, and `eaten_at` returned with the sent offset;
   - `local_date`: `2026-11-05T23:30:00-05:00` is stored on `2026-11-05`, while its UTC time is on the 6th;
   - with `ingredients` absent, the lines are the scaled recipe. With `ingredients` sent, the lines are as sent;
   - each rule gives 422: an unknown, another user's or a soft-deleted recipe, servings of 0, 0.3 or 12.25, `eaten_at` without an offset, before 2000, or 25 hours ahead, and `ingredients` breaking a SPEC 20 line rule or holding a `recipe` line;
   - detail returns the entry, and another user's or an unknown key gives 404.
4. **Frozen values.** Tests:
   - after logging, editing the ingredient's macros, renaming the recipe and editing the recipe's lines leave the entry's macros, names and lines unchanged;
   - deleting the logged recipe soft-deletes it, and the entry shows `recipe.deleted: true` with the logged name;
   - deleting an own ingredient used by an entry soft-deletes it, and the entry is unchanged.
5. **Update and delete.** Add `UpdateRecipeLogRequest`, `update`, `destroy` and the routes. Tests in `RecipeLogUpdateApiTest` and `RecipeLogDeleteApiTest`:
   - 404 on another user's or an unknown key;
   - `eaten_at` alone moves `local_date` and keeps the macros, even after the ingredient changed;
   - `ingredients` replaces the lines and re-snapshots from current values;
   - `servings` alone scales the current lines, tweaks included, and re-snapshots;
   - re-sending a soft-deleted ingredient already on the entry is accepted, and a new one gives 422;
   - `recipe` in the body and `servings: null` give 422;
   - `DELETE` returns `204`, and the entry and its lines are gone.
6. **Day.** Add `ShowRecipeLogDayRequest`, `index` and the day resource. Tests in `RecipeLogDayApiTest`:
   - entries of that `local_date` only, ordered by `eaten_at`, and not another user's;
   - `totals` sums the entries;
   - an empty day gives `entries: []`, zero totals and `fiber: null`;
   - a missing or malformed `date` gives 422;
   - 2 and 12 entries run the same two queries.
7. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest`. Add to SPEC 20's header: `> **Note:** recipes can be logged as eaten, by SPEC 26.` Update `CLAUDE.md`: recipe logs, frozen macros, `local_date`, and that the meal-logging specs build on these tables.
8. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `POST /recipe-logs` logs servings of the caller's recipe, with the scaled recipe lines by default or the lines sent.
- [ ] Sub-recipe lines are flattened into ingredients, and repeats are merged.
- [ ] Line and entry macros, and the recipe, ingredient and portion names, are frozen at log time and don't change when the recipe or ingredients are edited or deleted.
- [ ] `local_date` comes from `eaten_at`'s own offset, and only changes when `eaten_at` is edited.
- [ ] `PATCH` with `eaten_at` alone keeps the macros, and with `ingredients` or `servings` takes the snapshot again.
- [ ] `GET /recipe-logs?date=` returns that day's entries in order with their totals, in two queries.
- [ ] Another user's entry gives 404 on detail, update and delete, and never appears in a day.
- [ ] Deleting a logged recipe or ingredient soft-deletes it and leaves the entry intact.
- [ ] Deleting a user with recipe logs succeeds.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 20 has its header note, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** recipe entries only, with no meal slots and no ingredient entries. They come with the meal-logging specs, on the same tables.
- **Yes:** macros and names frozen at log time. A log is a record of what happened, and trends need past days to stay put. **No:** live macros as in recipes.
- **Yes:** each entry keeps its own lines. The frontend lets users adjust the portion while logging, and the saved recipe stays untouched.
- **Yes:** `eaten_at` with its offset, plus a `local_date` fixed at log time. The day is decided where the user was and never recomputed. **No:** a date only, which loses the time the frontend shows. **No:** server time plus a profile time zone, which moves entries when the user travels.
- **Yes:** `ingredients` optional, defaulting to the recipe scaled by `servings / recipe.servings`, with `servings` kept as a label when lines are sent. **No:** servings only, with no tweaks. **No:** lines only, with no servings.
- **Yes:** sub-recipes flattened at log time, with repeats merged. An entry holds only ingredient lines, so later meal-logging code has one kind of line.
- **Yes:** a `PATCH` of `eaten_at` alone keeps the snapshot, and a `PATCH` of servings or lines re-snapshots. Moving an entry's time shouldn't change what it was. Editing what was eaten picks up the current values.
- **Yes:** `servings` from 0.25 to 12 in steps of 0.25, matching the recipe's 12-serving maximum.
- **Yes:** `eaten_at` at most 24 hours ahead. It allows any time zone, and still rejects far-future typos.
- **Yes:** a day view with totals, unpaginated. **No:** ranges and weekly totals, which belong with the meal-logging specs once ingredient entries count too.
- **Yes:** `RESTRICT` on every reference, per CLAUDE.md, so deleting a logged recipe or ingredient soft-deletes it.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The meal-logging specs need meal slots and ingredient entries that don't fit these tables. | The tables are named for what they hold today. The meal-logging spec can add a nullable `meal` column and plain-ingredient entries, or migrate them into a general `food_logs` table. The frozen-snapshot design carries over either way. |
| A client sends local time without an offset. | The request requires an offset and gives 422 otherwise. |
| Flattening a sub-recipe and merging repeats surprises a user who expected separate lines. | The merged line keeps the position of the first one, and the tweak screen shows the result before logging. |
| Day totals leave out what the user ate as plain ingredients. | Documented. This spec has no "day total vs. target" view, so nothing presents it as the full day. |

## What is **not** in this spec

- Meal slots and plain-ingredient entries.
- Ranges, weekly totals and charts.
- Cost on log entries.
- Targets, recommendations, and recent recipes.
- Frontend changes.
