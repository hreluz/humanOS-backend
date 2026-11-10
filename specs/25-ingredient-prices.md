# SPEC 25 — Ingredient prices and recipe cost

> **Status:** Draft
> **Depends on:** SPEC 05, SPEC 18, SPEC 20, SPEC 21
> **Date:** 2026-11-05
> **Objective:** Let a user set their own package price on any ingredient they can see, in their profile's currency, and return the cost of each recipe line, the recipe and one serving.

## Why this spec exists

The frontend already shows ingredient prices and recipe cost (frontend SPEC 03 and SPEC 04), with mocked USD catalog prices and a typed-in price on custom foods. The backend has no prices. Prices depend on country, city and store far more than macros do, so this spec stores each user's own prices instead of catalog defaults. It stores them the way they're read on a shelf tag: a price for a package of so many grams.

## Scope

**In:**

- A private price per user and ingredient (catalog or own): an amount and a package size in grams.
- `PUT` and `DELETE /api/v1/nutrition/ingredients/{key}/price`.
- `price` on every ingredient response.
- `currency` on the profile: a required field of `PUT /profile`, defaulting to `USD` for existing profiles, with `USD` used when the user has no profile.
- `cost` on the recipe detail (per line, total, per serving, unpriced count) and on the recipe list.
- Sub-recipe lines (SPEC 21) costed from the sub-recipe's per-serving cost.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Default catalog prices from the data files.
- A currency on each price, and exchange rates.
- Price history and "price per store".
- Prices on logged meals and a daily food-spend total (the meal-logging spec).
- Linking a grocery list to prices.
- Sharing prices between users.
- Frontend changes.

## Data model

### Migrations

```php
// database/migrations/<date>_create_ingredient_prices_table.php
Schema::create('ingredient_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('amount_minor');
    $table->decimal('package_grams', 8, 2);
    $table->timestamps();
    $table->unique(['user_id', 'ingredient_id']);
});

// database/migrations/<date>_add_currency_to_profiles_table.php
Schema::table('profiles', function (Blueprint $table) {
    $table->char('currency', 3)->default('USD');
});
```

- `amount_minor` is the package price in the currency's minor units (349 for $3.49, 3490 for S/ 34.90, 349 for ¥349).
- A price has no currency column. Its amount is read in the user's current currency. Changing the currency relabels prices and converts nothing.
- `ingredient_id` cascades instead of using `RESTRICT`. A price is a user's current setting, not a record of something eaten, so it needn't outlive its ingredient. It also mustn't stop the seeder or a user from hard-deleting an ingredient. A soft-deleted ingredient keeps its price, so SPEC 20's deleted recipe lines keep their cost.

### Currencies

- `config/currencies.php` lists the ISO 4217 codes the API accepts, each with its minor units (0, 2 or 3), e.g. `'USD' => 2, 'PEN' => 2, 'JPY' => 0, 'KWD' => 3`.
- `App\Support\Currency` converts between a decimal amount and minor units, and rounds to minor units for output.

### Models

- `app/Modules/Nutrition/Models/IngredientPrice.php`.
- `Ingredient::priceFor(User $user)`: a `hasOne` constrained to the user, eager-loaded as `myPrice` wherever ingredients are returned.
- `Profile::currency`, and `User::currency(): string` returning the profile's currency or `USD`.

### Cost

- A price per gram is `amount_minor / package_grams`. `per_100g` is that × 100.
- An ingredient line's cost is `grams × amount_minor / package_grams`, or `null` when the caller has no price for the ingredient.
- A sub-recipe line's cost is `quantity × per_serving cost` of the sub-recipe, or `null` when that cost is `null`.
- `cost.total` sums the lines that have a cost. It's `null` when none has one, matching the frontend.
- `cost.per_serving` is `total / servings`.
- `cost.unpriced_count` is the number of ingredient lines with no price, including the sub-recipes' own unpriced lines.
- Sums use unrounded values. Responses round to the currency's minor units and return major units as numbers (`3.49`).

## Endpoints

All of them require a bearer token (401 otherwise).

| Method | Path | Success | Errors |
|---|---|---|---|
| `PUT` | `/api/v1/nutrition/ingredients/{key}/price` | `200`, the ingredient detail shape | 404, 422 |
| `DELETE` | `/api/v1/nutrition/ingredients/{key}/price` | `200`, the ingredient detail shape | 404 |
| `PUT` | `/api/v1/profile` | unchanged (SPEC 05), with `currency` | 422 |

- `{key}` resolves through SPEC 18's binding: catalog or own, and another user's ingredient is a 404. A catalog ingredient is allowed, since the price is the caller's, not the ingredient's.
- A soft-deleted ingredient is a 404, so a price can't be set on one.
- `DELETE` on an ingredient with no price is a `200` that changes nothing.

### `PUT /ingredients/{key}/price` (`UpdateIngredientPriceRequest`)

```json
{ "amount": 3.49, "package_grams": 500 }
```

| Field | Rule |
|---|---|
| `amount` | required, number, more than 0, at most 100000, at most as many decimals as the currency's minor units |
| `package_grams` | required, number, more than 0, at most 50000, at most 2 decimals |

- It creates or replaces the caller's price.
- The amount is read in the caller's currency at the time of the request.

### `PUT /profile` change

| Field | Rule |
|---|---|
| `currency` | required, a code in `config/currencies.php` |

- Changing it relabels every price of the user. Nothing is converted.
- This is a contract change to SPEC 05's request and response, which get updated in place, following the CLAUDE.md rule. `ProfileResource` returns `currency`.

### Response changes

Every ingredient response (search, detail, `my-ingredients`, create, update, photo endpoints) gains `price`:

```json
"price": { "amount": 3.49, "package_grams": 500, "per_100g": 0.70, "currency": "USD" }
```

- `null` when the caller has no price.
- Search and `my-ingredients` load prices with one extra query, whatever the number of results. SPEC 16's three-query search becomes four, and SPEC 18's four-query list becomes five. Both tests are updated.

The recipe detail gains `cost`, and each line gains `cost`:

```json
"cost": { "total": 4.12, "per_serving": 1.03, "currency": "USD", "unpriced_count": 1 }
```

- A line's `cost` is a number or `null`.
- `cost.total` and `cost.per_serving` are `null` when no line has a price. `currency` and `unpriced_count` are always set.
- The recipe list gains the same `cost` object, computed in its aggregate query. Its query count still doesn't grow with the page size.
- These are contract changes to SPEC 16, SPEC 18 and SPEC 20's shapes, updated in place.

## Files

```
app/Support/Currency.php
config/currencies.php
app/Modules/Nutrition/
  Models/IngredientPrice.php
  Models/Ingredient.php                          (myPrice)
  Http/Controllers/IngredientPriceController.php (update, destroy)
  Http/Requests/UpdateIngredientPriceRequest.php
  Http/Resources/IngredientResource.php          (price)
  Http/Resources/RecipeResource.php              (cost)
  Http/Resources/RecipeIngredientResource.php    (cost)
  Http/Controllers/IngredientController.php      (eager-load myPrice)
  Http/Controllers/RecipeController.php          (cost in detail and list)
  Support/RecipeMacros.php                       (cost)
  routes.php
app/Modules/Profile/
  Http/Requests/UpdateProfileRequest.php         (currency)
  Http/Resources/ProfileResource.php             (currency)
app/Models/Profile.php, app/Models/User.php      (currency)
database/migrations/<date>_create_ingredient_prices_table.php
database/migrations/<date>_add_currency_to_profiles_table.php
docs/openapi/v1.json
specs/05-user-profile.md                         (header note, request and response)
specs/16-ingredient-api.md                       (header note, response shapes)
specs/18-user-ingredients.md                     (header note)
specs/20-recipes.md                              (header note, response shapes)
CLAUDE.md
tests/Feature/Nutrition/IngredientPriceApiTest.php
tests/Feature/Nutrition/RecipeCostApiTest.php
tests/Unit/CurrencyTest.php
```

Plus additions to `IngredientSearchApiTest`, `IngredientShowApiTest`, `MyIngredientsApiTest`, `RecipeListApiTest`, the profile tests and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-21-nested-recipes`, and rebase onto `master` once it's merged.

1. **Currencies.** Add `config/currencies.php` and `Currency`. Tests in `CurrencyTest`: 3.49 USD is 349, 349 JPY is 349, 1.234 KWD is 1234, rounding to minor units, and an unknown code throws.
2. **Profile currency.** Add the migration, `currency` to `UpdateProfileRequest` and `ProfileResource`, and `User::currency()`. Tests:
   - existing profiles get `USD`;
   - `PUT /profile` without `currency` or with an unknown code gives 422;
   - `GET /profile` returns it;
   - `User::currency()` is `USD` with no profile.
3. **Prices.** Add the table, `IngredientPrice`, `myPrice`, the request, the controller and the routes. Tests in `IngredientPriceApiTest`:
   - 401 without a token, 404 on another user's, an unknown or a soft-deleted ingredient;
   - `PUT` on a catalog and on an own ingredient stores `amount_minor` and returns `price` with `per_100g`;
   - a second `PUT` replaces it;
   - each rule gives 422: amount 0, 100000.01, 3 decimals in USD, 1 decimal in JPY, package grams 0 or 50000.01;
   - `DELETE` removes it, and `DELETE` with no price is a `200`;
   - another user's price on the same catalog ingredient isn't returned;
   - changing the profile currency to `PEN` returns the same amount with `PEN`;
   - deleting the ingredient (hard) removes the price, and deleting the user removes their prices.
4. **Ingredient responses.** Eager-load `myPrice` in search, detail and `my-ingredients`. Tests: `price` is returned or `null`, and the query counts are four and five.
5. **Recipe cost.** Extend `RecipeMacros`, both recipe resources and the list aggregate. Tests in `RecipeCostApiTest`:
   - line costs, `total` and `per_serving` match hand-computed values;
   - an unpriced line has `cost: null` and counts in `unpriced_count`;
   - no priced lines gives `total: null`, `per_serving: null`;
   - a sub-recipe line costs `quantity × per_serving` of the sub-recipe, and adds its unpriced lines to the count;
   - a soft-deleted ingredient line keeps its cost;
   - the list's `cost` matches the detail's, and its query count doesn't grow with the page size.
6. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest`. Update SPEC 05, 16 and 20's shapes in place and add header notes to SPEC 05, 16, 18 and 20: `> **Note:** … by SPEC 25.` Update `CLAUDE.md`: prices, currency, cost.
7. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] A user can set, replace and delete their own package price on a catalog or own ingredient, and no other user sees it.
- [ ] Prices are stored in minor units, and amounts with more decimals than the currency allows give 422.
- [ ] `PUT /profile` requires `currency`, existing profiles have `USD`, and a user without a profile prices in `USD`.
- [ ] Changing the currency relabels prices without converting them.
- [ ] Every ingredient response returns `price` or `null`, with search at four queries and `my-ingredients` at five.
- [ ] The recipe detail returns a cost per line, `total`, `per_serving`, `currency` and `unpriced_count`, with `total` `null` when nothing is priced.
- [ ] Sub-recipe lines are costed from the sub-recipe's per-serving cost.
- [ ] The recipe list returns the same `cost` as the detail, without a query per recipe.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 05, 16, 18 and 20 have their header notes and updated shapes, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** users' own prices only. Prices vary by country and store, and a wrong default is worse than none. **No:** default catalog prices, which can come later as a fallback. **No:** catalog prices only.
- **Yes:** one currency per user, on the profile. People shop in one currency. **No:** a currency per price, which needs exchange rates.
- **Yes:** `currency` as a required field of `PUT /profile`, accepting that changing it also appends a weight entry and a target weight entry (SPEC 05 adds one on every `PUT`). It keeps every profile setting in one place. **No:** a separate nutrition settings endpoint.
- **Yes:** `USD` when the user has no profile, relabeled once a profile sets its currency. Prices work from the first call. **No:** a 422 until a profile exists.
- **Yes:** a price for a package and its grams. It's what a shelf tag and a receipt say. **No:** a price per 100 g, which users would have to work out. **No:** a choice between package and portion. Count items are entered as package weight, or clients fill it in from a portion.
- **Yes:** amounts stored in minor units as integers, avoiding float rounding.
- **Yes:** `PUT` and `DELETE` on `/ingredients/{key}/price` for every visible ingredient. **No:** prices inside `POST` and `PATCH /ingredients`, which can't cover catalog ingredients (403 in SPEC 18).
- **Yes:** `price` on every ingredient response, and `cost` on recipe detail and list, so cards can show cost per serving.
- **Yes:** `total` `null` when no line is priced, and an `unpriced_count`, matching the frontend's "Excludes 1 ingredient without a price".
- **Yes:** the price row cascades with its ingredient. It's a current setting, not history, and it mustn't block deletes.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| A user changes currency and their old prices now read as the new currency. | It's the documented behavior. Clients should warn when the currency changes on a user who has prices (a frontend concern). |
| Changing the currency through `PUT /profile` adds weight and target weight entries the user didn't mean as a weigh-in. | Accepted by decision. If it skews weight trends, a later spec can add a `PATCH /profile`. |
| `config/currencies.php` goes stale as ISO 4217 changes. | It changes rarely. The list is taken from the ISO 4217 table and dated in a comment. |
| Extra price queries slow down search. | One query per request, on the `(user_id, ingredient_id)` unique index. |

## What is **not** in this spec

- Default catalog prices.
- Several currencies and exchange rates.
- Price history and stores.
- Meal-log prices and food spend.
- Grocery lists.
- Shared prices.
- Frontend changes.
