# SPEC 16 — Ingredient API

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 15
> **Date:** 2026-11-03
> **Note:** both endpoints gain `photos` on every ingredient, and the search runs three queries instead of two, by SPEC 17.
> **Objective:** Add authenticated `GET /api/v1/nutrition/ingredients?search=` and `GET /api/v1/nutrition/ingredients/{key}` endpoints so clients can search the ingredient catalog by name and read an ingredient's nutrition data, in English.

## Scope

**In:**

- A `NutritionServiceProvider` with routes under `api/v1/nutrition`, registered in `bootstrap/providers.php`.
- A search endpoint: a required term of at least 3 characters, an optional category filter, and every match in one ranked list.
- A detail endpoint, with all measured nutrients and the data source.
- English responses only.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- **The user's language.** A later spec adds a language setting for the user, defaulting to English, and makes these endpoints return Spanish names, portion labels and nutrient labels. Until then the Spanish rows from SPEC 15 stay in the database and aren't read.
- Searching Spanish names and Spanish aliases (`palta`, `aguacate`). It arrives with the language spec.
- Moving `IngredientSeeder`'s locale constants to config. SPEC 15 planned that for this spec. It now belongs to the language spec.
- Listing or browsing the catalog without a search term.
- Pagination.
- Writing to the catalog (admin or user-created ingredients).
- Filtering or sorting by nutrient values.
- A calculation endpoint. Clients use the formula in SPEC 15.
- Full-text search and caching.
- Labels for `category`, nutrient `group` and nutrient `unit`. They are sent as raw values, and clients keep the labels, as the frontend does today for categories.
- Frontend changes. The frontend moves off its mocks in its own spec.
- Photos. SPEC 17 adds `photo` to both endpoints.

## Endpoints

Both require a bearer token (401 otherwise, in the SPEC 04 error envelope).

### `GET /api/v1/nutrition/ingredients`

Query parameters (`SearchIngredientsRequest`):

| Parameter | Rule | Meaning |
|---|---|---|
| `search` | **required**, string, 3–100 characters | The term to look for |
| `category[]` | optional, `IngredientCategory` values | Only these categories |

The category filter and the search combine with AND, and values inside `category[]` combine with OR. A missing or invalid value gives 422.

**Matching.** The term matches anywhere in the ingredient's English name (`ingredients.name`) or in one of its English aliases (`ingredient_aliases` with `locale = 'en'`, e.g. `aubergine` under `Eggplant`). Matching ignores case and accents. `%` and `_` in the term are matched literally, escaped with `addcslashes($search, '\\%_')` as in `Exercise::scopeFilter`. The alias match is an `EXISTS` subquery, so an ingredient matching several aliases appears once.

**Order.** By rank, then name, then `key`. The rank looks at the English name only:

| Rank | Name | Example for `rice` |
|---|---|---|
| 0 | equals the term | none in the catalog; `avocado` → `Avocado` |
| 1 | starts with the term | `Rice noodles, cooked`, `Rice noodles, dry` |
| 2 | has a word starting with the term (`LIKE '% term%'`) | `Brown rice, long-grain, cooked`, `White rice, long-grain, raw` |
| 3 | anything else that matched | a mid-word match, or a match through an alias only |

**Response:** every match, as a SPEC 04 list. No match gives `200` with `{ "data": [] }`.

```json
{
  "data": [
    {
      "key": "egg_whole_raw",
      "name": "Egg, whole, raw",
      "category": "eggs",
      "per_100g": { "kcal": 148, "protein": 12.4, "carbs": 0.96, "fat": 9.96, "fiber": 0 },
      "portions": [
        { "key": "large", "label": "1 large egg", "grams": 50 },
        { "key": "medium", "label": "1 medium egg", "grams": 44 }
      ],
      "photos": [
        {
          "url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_egg_whole.jpg?width=960",
          "thumbnail_url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_egg_whole.jpg?width=330",
          "license": "CC BY-SA 4.0",
          "license_url": "https://creativecommons.org/licenses/by-sa/4.0",
          "author": "Example Author",
          "credit_url": "https://commons.wikimedia.org/wiki/File:Example_egg_whole.jpg"
        }
      ]
    }
  ]
}
```

- `portions[].key` is the portion's stable key from the data files (SPEC 15 rule 7). It identifies the portion within its ingredient, and later specs (meal logging) use it.
- `portions` are in `position` order, and may be empty (28 catalog entries have none).
- Every number (`per_100g.*`, `grams`) is a JSON number, not a string. Clients round for display.
- `photos` are in `position` order, the first being the main photo, and may be empty. The fields, their nullability and the credit clients must show are in SPEC 17 ("API").

**Queries:** three, whatever the number of matches: the ingredients, their portions and their photos. No N+1.

### `GET /api/v1/nutrition/ingredients/{key}`

Route model binding on `key`. 404 in the SPEC 04 envelope if no ingredient has that key, or if it is soft-deleted. Returns the search item plus two detail-only fields:

```json
{
  "data": {
    "key": "egg_whole_raw",
    "name": "Egg, whole, raw",
    "category": "eggs",
    "per_100g": { "kcal": 148, "protein": 12.4, "carbs": 0.96, "fat": 9.96, "fiber": 0 },
    "portions": [{ "key": "large", "label": "1 large egg", "grams": 50 }],
    "photos": [
      {
        "url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_egg_whole.jpg?width=960",
        "thumbnail_url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_egg_whole.jpg?width=330",
        "license": "CC BY-SA 4.0",
        "license_url": "https://creativecommons.org/licenses/by-sa/4.0",
        "author": "Example Author",
        "credit_url": "https://commons.wikimedia.org/wiki/File:Example_egg_whole.jpg"
      }
    ],
    "nutrients": [
      { "key": "cholesterol", "label": "Cholesterol", "group": "fats_and_sugars", "unit": "mg", "amount": 411 }
    ],
    "source": { "type": "usda_foundation", "id": "748967" }
  }
}
```

- `nutrients` are per 100 g, in `sort_order`. `amount` is cast to a float, because the pivot column is a decimal and PHP reads it as a string.
- A nutrient that wasn't measured is left out, never sent as `0`. A nutrient measured as `0` is sent with `amount: 0`. Clients show a missing one as "no data".
- A soft-deleted nutrient or portion is left out (SPEC 15's removal rule; the `SoftDeletes` scopes do this).
- `group` and `unit` are raw enum values (`NutrientGroup`, `NutrientUnit`).
- `source.type` is an `IngredientSource` value and `source.id` is the FDC id. Neither is ever `null`. The USDA credit lives in `docs/ingredient-catalog.md`, not in the response.

## Data model

No new tables, columns or config. The endpoints read SPEC 15's schema.

- `Ingredient::scopeSearch(SearchIngredientsRequest $filters)` applies the category filter, the matching and the order, as `Exercise::scopeFilter` does (SPEC 12).
- `IngredientSeeder::TRANSLATION_LOCALES` keeps its value. Its docblock says it moves to config "in SPEC 16"; that line now says "in the language spec".

### Files

```
app/Modules/Nutrition/
  NutritionServiceProvider.php
  routes.php
  Http/Controllers/IngredientController.php        // #[Group('Nutrition')], index and show
  Http/Requests/SearchIngredientsRequest.php
  Http/Resources/{IngredientResource,IngredientPortionResource,IngredientNutrientResource}.php
  Models/Ingredient.php                            (scopeSearch)
bootstrap/providers.php
database/seeders/IngredientSeeder.php              (docblock line only)
tests/Feature/Nutrition/{IngredientSearchApiTest,IngredientShowApiTest}.php
tests/Feature/Docs/OpenApiDocumentTest.php         (both operations under the Nutrition tag)
docs/openapi/v1.json                               (re-export)
CLAUDE.md                                          (Nutrition provider and routes)
specs/15-ingredient-catalog.md                     (header note)
```

- `IngredientResource` returns `nutrients` and `source` only through `withDetails()`, the way `ExerciseResource` handles its detail-only fields.
- Docs follow `docs/code-conventions.md`: `#[Group('Nutrition')]`, a summary and description per method, `@throws ModelNotFoundException` on `show`, and `@example` on every resource field and validation rule.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 15 is merged. Tests seed small fixture ingredients through `IngredientSeeder`'s directory argument, not the full catalog.

1. **Module and routes.** Add the provider with `Route::prefix('api/v1/nutrition')->middleware(['api', 'auth:sanctum'])`, as `TrainingServiceProvider` does, its registration, and the two routes (`/ingredients` and `/ingredients/{ingredient:key}`). The controller methods return empty resources for now. Check: `php artisan route:list --path=api/v1/nutrition` lists two routes.
2. **Detail endpoint.** Add the resources and `show`. Tests in `IngredientShowApiTest`:
   - 401 without a token, and 404 for an unknown key in the SPEC 04 envelope;
   - every field above, with portions in `position` order and nutrients in `sort_order`;
   - every number in the body is a JSON number, `nutrients[].amount` included;
   - an unmeasured nutrient is absent, and one measured as `0` is present with `0`;
   - an ingredient with no portions returns `portions: []`;
   - a soft-deleted ingredient gives 404, and a soft-deleted portion or nutrient is left out.
3. **Search endpoint.** Add the request, `scopeSearch` and `index`. Tests in `IngredientSearchApiTest`:
   - 401 without a token;
   - a missing `search`, a `search` of 2 or 101 characters, and an unknown category give 422;
   - the term matches anywhere in the English name, and through an English alias (`aubergine` finds `Eggplant`);
   - a Spanish name or a Spanish alias doesn't match (`palta` returns `[]`);
   - matching ignores case and accents (`JALAPENO` finds `Jalapeño`, using a fixture name with `ñ`);
   - `%` and `_` in the term match literally;
   - ranking with fixtures named like the catalog: `rice` returns `Rice noodles, …` before `Brown rice, …` and `White rice, …`, and those before a mid-word or alias-only match; an exact name match comes first; ties sort by name, then `key`;
   - an ingredient matching on several aliases appears once;
   - `category[]` with one and two values narrows the matches;
   - no match returns `200` with `{ "data": [] }`;
   - every match is returned (a fixture term matching more than 50 ingredients returns all of them);
   - items have no `nutrients` or `source`;
   - a soft-deleted ingredient isn't found, and a soft-deleted portion isn't in `portions`;
   - a term matching 5 ingredients and one matching 15 run the same number of queries, two.
4. **Docs.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that both operations sit under `Nutrition` with their parameters and 401/404/422 responses, that `search` is required, and that `nutrients` and `source` aren't required on search items.
5. **Records.** Update `CLAUDE.md`: the Nutrition module now has a provider and routes under `api/v1/nutrition`, and responses are English until the language spec. Add to SPEC 15's header: `> **Note:** Moving IngredientSeeder's locales to config was deferred from SPEC 16 to the language spec by SPEC 16.` Update the seeder's docblock line to match.
6. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] Both endpoints give 401 without a token, and the detail endpoint gives 404 for an unknown or soft-deleted key.
- [ ] `search` is required and must be 3–100 characters, and an invalid `search` or category gives 422.
- [ ] The search returns every match as `{ "data": [...] }`, with no pagination, and `{ "data": [] }` when nothing matches.
- [ ] `aubergine` finds `Eggplant`, `JALAPENO` finds `Jalapeño`, and `palta` finds nothing.
- [ ] Searching `rice` returns `Rice noodles` entries before `Brown rice` and `White rice` entries, and those before mid-word or alias-only matches.
- [ ] `category[]` narrows the matches.
- [ ] Search items carry `key`, `name`, `category`, `per_100g` and `portions` (each with `key`, `label` and `grams`), and no `nutrients` or `source`.
- [ ] The detail endpoint adds `nutrients` and `source`. `source` is `{ type, id }` with neither `null`.
- [ ] An unmeasured nutrient is absent from `nutrients`, a measured `0` is present, and every amount is a JSON number.
- [ ] The search runs two queries whatever the number of matches.
- [ ] `docs/openapi/v1.json` is re-exported and documents both operations under `Nutrition`.
- [ ] `CLAUDE.md` describes the Nutrition routes, and SPEC 15's header has the note.

## Decisions

- **Yes:** a search endpoint with a required term of at least 3 characters. It's the flow clients use: the user types, and matches appear. **No:** browsing the catalog without a term. **No:** 2 characters, which matches too much to be useful.
- **Yes:** every match in one response, with no pagination or limit. The catalog has about 200 entries, so even a broad term returns a few dozen rows. **No:** pagination like exercises (SPEC 12), or a fixed top-N.
- **Yes:** an optional `category[]` filter. It costs one `whereIn`, and the frontend already groups by category.
- **Yes:** English only in this spec. The user's language gets its own spec, defaulting to English, so the language logic is designed once. **No:** `Accept-Language` now with a user setting later, which would make two mechanisms. **No:** writing the language spec first and depending on it.
- **Yes:** matching English names and English aliases only. Search and response stay in one language until the language spec adds Spanish to both. **No:** searching Spanish while returning English.
- **Yes:** match anywhere, ranked as exact, prefix, word prefix, then the rest. Catalog names follow SPEC 15 rule 5 (`White rice, long-grain, cooked`), so an exact match is rare. A prefix tier alone would put `Rice noodles` ahead of every rice and leave `Brown rice` with mid-word matches. **No:** word-start matching only, or alphabetical order with no ranking.
- **No:** ranking alias matches as exact. It keeps the rank one expression over the name, and alias searches return few results.
- **Yes:** a bearer token on both endpoints, like every other endpoint. Nothing needs the catalog before login yet. **No:** public endpoints.
- **Yes:** only `name` on each item. **No:** aliases or a second-language name in the response.
- **Yes:** a detail endpoint for the full nutrients and the source. Search results stay light, and the full data loads when one ingredient is opened.
- **Yes:** portion `key` in the response. It survives reseeds and reordering, unlike `position`, and meal logging needs to name a portion. **No:** the database id, which clients would otherwise start storing.
- **Yes:** `source` as `{ type, id }`, both non-null. SPEC 15 dropped `source_citation`, and every entry has an FDC id.
- **Yes:** raw values for `category`, `group` and `unit`. One rule for every enum, matching `docs/code-conventions.md`.
- **Yes:** macros and portions on search items, all nutrients on detail.
- **Yes:** values under `per_100g`. The name states the basis, so nobody treats them as per portion.
- **Yes:** nutrient labels inline in `nutrients`, not keys joined against a catalog endpoint as SPEC 11 does for muscles. The detail response is the only place nutrients appear, so a separate endpoint adds a request and saves nothing.
- **Yes:** `key` in the URL, as with exercises (SPEC 11).
- **No:** a calculation endpoint. The formula is simple and clients need it offline.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Accent- and case-insensitive matching depends on the `utf8mb4_unicode_ci` collation, and the README targets PostgreSQL. Local SQLite won't ignore accents. | Tests pin the behaviour on MySQL. A move to PostgreSQL has to keep those tests passing (e.g. with `unaccent`). |
| Results have no limit, and the catalog grows, or user-created foods join the search later. | About 200 entries today. The spec that adds user-created foods to search revisits the limit. |
| Matching aliases duplicates rows. | `EXISTS` subquery, no join, and a test with several matching aliases. |
| `LIKE '%…%'` can't use an index. | About 200 rows; measure before optimising. |
| Scramble doesn't infer the detail-only fields. | Check the export by hand (step 4). |

## What is **not** in this spec

- The user's language, and Spanish search and responses (the language spec).
- Browsing without a search term, and pagination.
- Writing to the catalog.
- Nutrient-based filters or sorting.
- A calculation endpoint.
- Labels for categories, nutrient groups or units.
- Frontend changes.
- Photos (SPEC 17).
