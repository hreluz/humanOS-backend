# SPEC 16 — Ingredient API

> **Status:** Draft
> **Depends on:** SPEC 04, SPEC 15
> **Date:** 2026-11-02
> **Objective:** Add authenticated, language-aware `GET /api/v1/nutrition/ingredients` and `GET /api/v1/nutrition/ingredients/{key}` endpoints so clients can search the ingredient catalog and read its nutrition data.

## Scope

**In:**

- A `NutritionServiceProvider` with routes under `api/v1/nutrition`, registered in `bootstrap/providers.php`.
- The paginated list, with search and a category filter.
- The detail endpoint, with all nutrients.
- Language selection through `Accept-Language`, with a fallback to English.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- Writing to the catalog (admin or user-created ingredients).
- Filtering or sorting by nutrient values.
- A calculation endpoint. Clients use the formula in SPEC 15.
- Full-text search and caching.
- Category labels. Categories are sent as raw values, and clients keep the labels, as the frontend does today.
- Frontend changes. The frontend moves off its mocks in its own spec.
- Photos. SPEC 17 adds `photo` to both endpoints.

## Language

A new middleware, `SetLocaleFromAcceptLanguage`, runs on the Nutrition routes only.

- It picks the best match from `config('nutrition.locales')`, which is `['en', 'es']`, and sets the app locale for the request.
- `es-PE` and `es-419` resolve to `es`. A missing header or an unsupported language resolves to `en`.
- Responses carry `Content-Language` with the chosen locale, and `Vary: Accept-Language`.
- A name with no translation in the chosen locale falls back to English. This matters once a language is only partly filled in.

## Endpoints

Both require a bearer token (401 otherwise).

### `GET /api/v1/nutrition/ingredients`

Query parameters (`ListIngredientsRequest`, all optional):

| Parameter | Rule | Meaning |
|---|---|---|
| `category[]` | `IngredientCategory` values | Only these categories |
| `search` | string, 2–100 chars | See "Search" |
| `page` | integer ≥ 1 | Page number (default 1) |
| `per_page` | integer 1–100 | Page size (default 50) |

Filters combine with AND, and values inside `category[]` combine with OR. An unknown value gives 422.

**Search** matches anywhere in the English name, any translated name, or any alias in any language, ignoring case and accents. `palta`, `aguacate` and `avocado` find the same ingredient, and `jalapeno` finds `jalapeño`.

**Order:** without `search`, by display name in the chosen locale, then `key`. With `search`, an exact match comes first, then names that start with the term, then the rest, each by display name then `key`. This puts `Egg` before `Eggplant`, and both before `Duck egg`-style matches. Only the display name decides the rank, so an alias match ranks with "the rest".

**Response:** the SPEC 04 paginated envelope.

```json
{
  "data": [
    {
      "key": "egg_whole_raw",
      "name": "Huevo entero crudo",
      "category": "eggs",
      "per_100g": { "kcal": 143, "protein": 12.56, "carbs": 0.72, "fat": 9.51, "fiber": 0 },
      "portions": [{ "label": "1 huevo grande", "grams": 50 }]
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "per_page": 50, "total": 200, "…": "…" }
}
```

- `name` and `portions[].label` are in the chosen locale.
- Numbers are JSON numbers, not rounded strings. Clients round for display.
- `portions` are in `position` order, and may be empty.

Queries: the count, the page, and eager loads of portions and translations for the chosen locale. A constant number whatever the page size. No N+1.

### `GET /api/v1/nutrition/ingredients/{key}`

Route model binding on `key`. 404 if no ingredient has that key. Returns the list item plus two detail-only fields:

```json
{
  "data": {
    "key": "egg_whole_raw",
    "name": "Huevo entero crudo",
    "category": "eggs",
    "per_100g": { "kcal": 143, "protein": 12.56, "carbs": 0.72, "fat": 9.51, "fiber": 0 },
    "portions": [{ "label": "1 huevo grande", "grams": 50 }],
    "nutrients": [
      { "key": "cholesterol", "label": "Colesterol", "group": "fats_and_sugars", "unit": "mg", "amount": 372 }
    ],
    "source": { "type": "usda_foundation", "id": "000000", "citation": null }
  }
}
```

- `nutrients` are per 100 g, in `sort_order`, with `label` in the chosen locale. A nutrient that wasn't measured is left out, never sent as `0`. Clients show it as "no data".
- `source.id` and `source.citation` may be `null`. Clients show the citation where they show the data's origin.

## Code

### Files

```
app/Modules/Nutrition/
  NutritionServiceProvider.php
  routes.php
  Http/Controllers/IngredientController.php        // #[Group('Nutrition')], index and show
  Http/Requests/ListIngredientsRequest.php
  Http/Resources/{IngredientResource,IngredientPortionResource,IngredientNutrientResource}.php
  Http/Middleware/SetLocaleFromAcceptLanguage.php
  Models/Ingredient.php                            (scopeFilter)
config/nutrition.php
bootstrap/providers.php
tests/Feature/Nutrition/{IngredientListApiTest,IngredientShowApiTest}.php
tests/Feature/Docs/OpenApiDocumentTest.php         (both operations under the Nutrition tag)
docs/openapi/v1.json                               (re-export)
CLAUDE.md                                          (the Nutrition routes and Accept-Language)
```

- The list query lives in a scope on `Ingredient`, as on `Exercise` (SPEC 12).
- `IngredientResource` returns `nutrients` and `source` only on the show route, the way `ExerciseResource` handles its detail-only fields.
- Docs follow `docs/code-conventions.md`. The `Accept-Language` header is documented on both operations with Scramble's own mechanism.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 15's schema is merged. The endpoints don't need the full catalog, and tests seed fixture ingredients.

1. **Module and routes.** Add the provider, its registration and the routes under `auth:sanctum`. Check: `php artisan route:list --path=api/v1/nutrition` lists two routes.
2. **Language middleware.** Tests through the show endpoint once step 3 exists; here, a unit-level test that `es`, `es-PE`, `fr` and no header resolve as described.
3. **Detail endpoint.** Add the resources and `show`. Tests:
   - 401 without a token, and 404 for an unknown key in the SPEC 04 envelope;
   - every field above, with nutrients in `sort_order`;
   - an unmeasured nutrient is absent, and a measured `0` is present;
   - `Accept-Language: es` returns Spanish names, portion labels and nutrient labels, with `Content-Language: es`;
   - a missing translation falls back to English;
   - a soft-deleted ingredient gives 404, and a soft-deleted portion or nutrient is left out (SPEC 15's removal rule).
4. **List endpoint.** Add the request, the scope and `index`. Tests:
   - 401 without a token;
   - default page of 50, sorted by display name then key, and sorted by the Spanish name under `Accept-Language: es`;
   - `category[]` with one and two values;
   - search matches the English name, a translation and an alias in another language;
   - search ignores case and accents;
   - exact match, then prefix, then the rest;
   - an ingredient matching on several aliases appears once, and `meta.total` counts it once;
   - `search` of 1 and 101 characters, `per_page=101`, `page=0` and an unknown category give 422;
   - no `nutrients` or `source` on list items;
   - a soft-deleted ingredient isn't listed or found by search, and a soft-deleted portion isn't in `portions`;
   - a page of 10 and a page of 100 run the same number of queries.
5. **Docs.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that both operations sit under `Nutrition` with their parameters and error responses, and that detail-only fields aren't required on list items. Update `CLAUDE.md`.
6. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] Both endpoints give 401 without a token, and the detail endpoint gives 404 for an unknown key.
- [ ] The list is paginated (default 50, max 100) in the SPEC 04 envelope, and items have no `nutrients` or `source`.
- [ ] `category[]` and `search` work alone and combined, and an invalid value gives 422.
- [ ] Searching `palta`, `aguacate` or `avocado` returns the same ingredient, and `jalapeno` finds `jalapeño`.
- [ ] `Accept-Language: es-PE` returns Spanish text with `Content-Language: es`, and `fr` returns English.
- [ ] An unmeasured nutrient is absent from `nutrients`, and a measured `0` is present.
- [ ] The list runs a constant number of queries per page.
- [ ] `docs/openapi/v1.json` is re-exported and documents both operations under `Nutrition`.

## Decisions

- **Yes:** `Accept-Language` over a `?locale=` parameter. It's the HTTP standard, and clients set it once.
- **Yes:** the middleware on Nutrition routes only. Other modules have no translated data yet.
- **Yes:** search across all languages, whatever the chosen locale. A Spanish speaker in an English UI can still type `palta`.
- **Yes:** macros and portions on the list, all nutrients on detail. The list has what's needed to search and log, and each page stays small.
- **Yes:** values under `per_100g`. The name states the basis, so nobody treats them as per portion.
- **Yes:** labels inline in `nutrients`, not keys joined against a catalog endpoint as SPEC 11 does for muscles. The detail response is the only place nutrients appear, so a separate endpoint adds a request and saves nothing.
- **Yes:** exact and prefix ranking, which exercises don't have. Even with about 200 names, a plain alphabetical order puts `Brown rice` before `Rice` when searching `rice`.
- **Yes:** `key` in the URL, as with exercises (SPEC 11).
- **No:** a calculation endpoint. The formula is simple and clients need it offline.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Accent-insensitive search depends on the database collation, and the README targets PostgreSQL. | Tests pin the behaviour on MySQL. A move to PostgreSQL has to keep those tests passing (e.g. with `unaccent`). |
| Joining translations and aliases duplicates rows or breaks pagination counts. | Match with `EXISTS` subqueries, not row-multiplying joins, and test an ingredient with several matching aliases. |
| `LIKE '%…%'` across three tables can't use an index. | About 200 rows; measure before optimising. |
| A shared cache serves the wrong language. | `Vary: Accept-Language` is on every response. |
| Scramble doesn't infer the detail-only fields or the header. | Check the export by hand (step 5) and use Scramble's documented mechanisms. |

## What is **not** in this spec

- Writing to the catalog.
- Nutrient-based filters or sorting.
- A calculation endpoint.
- Frontend changes.
