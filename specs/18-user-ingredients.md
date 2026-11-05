# SPEC 18 — User ingredients

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 15, SPEC 16, SPEC 17
> **Date:** 2026-11-05
> **Objective:** Let a user create, list, edit and delete their own private ingredients, with macros per 100 g and optional portions, and find them next to the catalog in the ingredient search and detail endpoints.

## Why this spec exists

The catalog has about 200 USDA ingredients. Users eat packaged and regional foods that aren't in it, and they have the label in front of them. SPEC 17 prepared the tables for this: `ingredients.user_id` (`null` for system ingredients), a seeder that only touches system rows, and a "Ready for user ingredients" list of what this spec still had to decide. This spec decides it, except photo uploads and sharing, which get their own specs.

## Scope

**In:**

- `POST`, `PATCH` and `DELETE` on `/api/v1/nutrition/ingredients`, and a paginated `GET /api/v1/nutrition/my-ingredients`.
- A user ingredient holds `name`, `category`, the five macros per 100 g and up to 10 portions.
- `fiber` becomes optional and nullable, for every ingredient, in the table and in the API contract.
- An `IngredientSource::User` case, and a nullable `source_id`.
- Generated keys: `u_<ulid>` for ingredients, `p_<random>` for a user's portions.
- Visibility: the catalog plus the caller's own ingredients, in search, detail and route binding. Another user's ingredient is a 404 everywhere.
- `is_mine` on every ingredient response.
- An `IngredientPolicy`: only the owner edits or deletes, and a catalog ingredient gives 403.
- Deletion through `CatalogRemover`, for ingredients and for portions dropped in an edit.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- **Photo uploads** (`IngredientPhotoSource::Upload`, storage, upload validation, EXIF stripping). Their own spec, next. Until then a user's ingredient returns `photos: []`.
- **Sharing** a user's ingredient with other users. Their own spec. It brings search limits, edits seen by other users, trust and privacy, none of which exist while ingredients are private.
- Micronutrients on a user's ingredient. Detail returns `nutrients: []`.
- Translations and aliases for a user's ingredient. The name is returned as written, in any language.
- Copying or "forking" a catalog ingredient into a user's own.
- A cap on how many ingredients a user can create.
- Separate endpoints per portion.
- Rate limiting.
- Frontend changes.

## Data model

### Migrations

```php
// database/migrations/<date>_allow_user_ingredients_on_ingredients_table.php
Schema::table('ingredients', function (Blueprint $table) {
    $table->decimal('fiber', 7, 3)->nullable()->change();
    $table->string('source_id', 32)->nullable()->change();
});
```

- `fiber` `null` means "not on the label", never `0`. It follows the catalog rule that a missing value means "not measured".
- `IngredientSeeder` still requires `fiber` and `source_id` for every catalog entry. Only users' ingredients store `null` there.
- The rollback sets `null` `fiber` to `0` and `null` `source_id` to `''` before making both columns `NOT NULL` again.

### Enum

```php
enum IngredientSource: string
{
    case UsdaFoundation = 'usda_foundation';
    case UsdaSrLegacy = 'usda_sr_legacy';
    case User = 'user';
}
```

The seeder rejects `user` as a catalog `source`.

### A user's ingredient

| Column | Value |
|---|---|
| `user_id` | the caller's id |
| `key` | `u_` + lowercase ULID, e.g. `u_01jc8k2d4x7q9m3n5p6r8s0t2v` (28 characters), generated on create, never changed |
| `name` | 1–100 characters after trimming, not unique |
| `category` | an `IngredientCategory` value |
| `kcal`, `protein`, `carbs`, `fat` | required on create |
| `fiber` | optional, `null` when not sent |
| `source` | `user` |
| `source_id` | `null` |

- Catalog keys are snake_case words (`docs/ingredient-catalog.md`), so `u_<ulid>` can't clash with them. The seeder also rejects a catalog key that starts with `u_`, and `IngredientCatalogTest` checks it.
- No rows in `ingredient_translations`, `ingredient_aliases`, `ingredient_nutrient` or `ingredient_photos`.

### Portions

- A user's portion has a `label` (1–50 characters after trimming) and `grams` (more than 0, at most 5000). An ingredient has at most 10.
- Its `key` is `p_` + 8 random lowercase letters and digits (e.g. `p_8k2d4x7q`), generated when the portion is created, unique within its ingredient (soft-deleted portions included), and never changed. The generator retries on a clash.
- `position` follows the order of the list the client sends, starting at 1.

### Models, scopes and policy

- `Ingredient::scopeVisibleTo(User $user)`: `user_id` is `null` or `user_id` is `$user->id`.
- `Ingredient::scopeOwnedBy(User $user)`: `user_id` is `$user->id`.
- `Ingredient::isOwnedBy(User $user): bool`.
- Route binding for `{ingredient:key}` resolves through `visibleTo` the authenticated user (`Route::bind` in `NutritionServiceProvider`). Another user's ingredient, or a soft-deleted one, is a 404 before any controller or policy runs.
- `app/Modules/Nutrition/Policies/IngredientPolicy.php`: `update` and `delete` allow only the owner. A system ingredient is denied, giving 403. It's registered with `Gate::policy()` in `NutritionServiceProvider::boot()`, since policies under `app/Modules` aren't auto-discovered.
- `CatalogRemover` keeps its name and code. Its docblock says it now also removes users' ingredients and portions.

## Endpoints

All of them require a bearer token (401 otherwise, in the SPEC 04 envelope) and live under `api/v1/nutrition`.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/ingredients` | `201`, the detail shape | 422 |
| `PATCH` | `/ingredients/{key}` | `200`, the detail shape | 403 catalog ingredient, 404, 422 |
| `DELETE` | `/ingredients/{key}` | `204`, no body | 403 catalog ingredient, 404 |
| `GET` | `/my-ingredients` | `200`, SPEC 04 paginated list | 422 |
| `GET` | `/ingredients?search=` | unchanged (SPEC 16), now with the caller's own ingredients | |
| `GET` | `/ingredients/{key}` | unchanged (SPEC 16), 404 for another user's ingredient | |

### `POST /ingredients` (`StoreIngredientRequest`)

```json
{
  "name": "Protein bar, chocolate",
  "category": "supplements",
  "kcal": 380,
  "protein": 33,
  "carbs": 38,
  "fat": 11,
  "fiber": 9,
  "portions": [
    { "label": "1 bar", "grams": 45 }
  ]
}
```

| Field | Rule |
|---|---|
| `name` | required, string, 1–100 characters after trimming |
| `category` | required, `IngredientCategory` value |
| `kcal` | required, number, 0–900 |
| `protein`, `carbs`, `fat` | required, number, 0–100 |
| `fiber` | optional, nullable, number, 0–100 |
| `protein + carbs + fat` | at most 100 (error on `fat`) |
| `portions` | optional list, at most 10 items, `[]` when absent |
| `portions.*.label` | required, string, 1–50 characters after trimming |
| `portions.*.grams` | required, number, more than 0, at most 5000 |
| `portions.*.key` | prohibited on create |

- `fiber` isn't in the sum. US labels count fiber inside carbs and EU labels count it separately, so adding it would reject valid US labels (chia seeds: 17 + 42 + 31 = 90, plus fiber 34 = 124).
- `kcal` isn't checked against the macros. Labels round, and alcohol or sugar alcohols add calories the macros don't explain.
- The ingredient and its portions are written in one transaction.

### `PATCH /ingredients/{key}` (`UpdateIngredientRequest`)

- Every field is optional. A field that isn't sent keeps its stored value. `fiber: null` clears the fiber.
- Each field sent follows the create rule. `name`, `category`, `kcal`, `protein`, `carbs` and `fat` can't be `null`.
- The sum rule is checked on the merged result: the values sent, filling the rest from the stored row. `PATCH { "fat": 80 }` on an ingredient with `protein` 30 gives 422.
- `portions` is all or nothing:
  - absent: portions don't change;
  - present: it's the full new list, at most 10 items, `[]` allowed;
  - an item with a `key` updates that portion's `label` and `grams`;
  - an item without a `key` is a new portion with a generated key;
  - a current portion left out is removed through `CatalogRemover`, so it's soft-deleted if something references it;
  - `position` is renumbered from the list order;
  - a `key` that isn't a current (not soft-deleted) portion of this ingredient, or the same `key` twice, gives 422.
- Authorization (`IngredientPolicy::update`) runs before validation, so a catalog ingredient gives 403 whatever the body.
- Everything is written in one transaction.

### `DELETE /ingredients/{key}`

- `IngredientPolicy::delete`, then `CatalogRemover::remove(Ingredient::whereKey($ingredient->id))`. Nothing references a user's ingredient today, so it's hard-deleted, with portions cascading. Once meal logs reference it with `ON DELETE RESTRICT`, it's soft-deleted instead, with no change here.
- A deleted ingredient is a 404 on every endpoint afterwards, and leaves search and `my-ingredients`.

### `GET /my-ingredients` (`ListMyIngredientsRequest`)

| Parameter | Rule | Meaning |
|---|---|---|
| `page` | integer ≥ 1 | Page number |
| `per_page` | integer 1–100 | Page size (default 50) |

- The caller's ingredients, not soft-deleted, sorted by `name` then `key`, as `IngredientResource::collection($paginator)` (SPEC 04 paginated list, like SPEC 12). Items have the search shape: no `nutrients` or `source`.
- Queries: four, whatever the page size: the count, the ingredients, their portions and their photos.

### Response changes

Every ingredient response (search, detail, `my-ingredients`, create, update) gains `is_mine`:

```json
{
  "data": {
    "key": "u_01jc8k2d4x7q9m3n5p6r8s0t2v",
    "name": "Protein bar, chocolate",
    "category": "supplements",
    "is_mine": true,
    "per_100g": { "kcal": 380, "protein": 33, "carbs": 38, "fat": 11, "fiber": null },
    "portions": [{ "key": "p_8k2d4x7q", "label": "1 bar", "grams": 45 }],
    "photos": [],
    "nutrients": [],
    "source": { "type": "user", "id": null }
  }
}
```

- `is_mine` is `true` when the caller owns the ingredient, `false` for catalog ingredients. It's computed from `user_id` and the request user, with no extra query.
- `per_100g.fiber` is a number or `null`, for every ingredient. Clients show `null` as "no data" and leave it out of totals.
- `source.type` can be `user`, and `source.id` is `null` for it. Catalog ingredients still always have an id.
- These are contract changes. SPEC 16's response shapes and its `source` rule get updated in place, following the CLAUDE.md rule.

### Search and detail

- `Ingredient::scopeSearch` adds `visibleTo` the request user. The SPEC 16 ranking applies unchanged, and the caller's ingredients get no priority. The search still runs three queries.
- A user's ingredient matches on its `name` only, since it has no aliases.
- Detail works through the route binding above.

## Files

```
app/Modules/Nutrition/
  Enums/IngredientSource.php                      (User case)
  Http/Controllers/IngredientController.php       (store, update, destroy, mine)
  Http/Requests/StoreIngredientRequest.php
  Http/Requests/UpdateIngredientRequest.php
  Http/Requests/ListMyIngredientsRequest.php
  Http/Resources/IngredientResource.php           (is_mine, nullable fiber and source.id)
  Models/Ingredient.php                           (visibleTo, ownedBy, isOwnedBy, key generation)
  Models/IngredientPortion.php                    (key generation)
  Policies/IngredientPolicy.php
  Support/CatalogRemover.php                      (docblock only)
  NutritionServiceProvider.php                    (route binding, policy)
  routes.php
database/migrations/<date>_allow_user_ingredients_on_ingredients_table.php
database/seeders/IngredientSeeder.php             (rejects `user` source and `u_` keys)
docs/ingredient-catalog.md                        (key rule)
docs/openapi/v1.json
specs/16-ingredient-api.md                        (header note, response shapes, source rule)
specs/17-ingredient-photos.md                     (header note)
CLAUDE.md
tests/Feature/Nutrition/UserIngredientStoreApiTest.php
tests/Feature/Nutrition/UserIngredientUpdateApiTest.php
tests/Feature/Nutrition/UserIngredientDeleteApiTest.php
tests/Feature/Nutrition/MyIngredientsApiTest.php
```

Plus additions to `IngredientSearchApiTest`, `IngredientShowApiTest`, `IngredientSeederTest`, `IngredientCatalogTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-17-ingredient-photos` (the work lives on `spec-18-user-ingredients`), and rebase onto `master` once SPEC 17 is merged.

1. **Schema and enum.** Add the migration and `IngredientSource::User`. `IngredientResource` returns `fiber` and `source.id` as nullable. The seeder rejects a `user` source and a key starting with `u_`. Tests:
   - `migrate` makes both columns nullable, and `migrate:rollback` restores `NOT NULL`, after turning `null` values into `0` and `''`;
   - the seeder still rejects a missing `fiber` or `source_id`, a `user` source and a `u_` key, writing nothing.
2. **Visibility and `is_mine`.** Add `visibleTo`, `ownedBy`, `isOwnedBy`, the route binding, `is_mine`, and `visibleTo` in `scopeSearch`. Tests, with user ingredients created directly in the database:
   - search finds the caller's ingredient, not another user's;
   - detail returns the caller's ingredient with `is_mine: true`, `source: { type: "user", id: null }`, `photos: []` and `nutrients: []`, and another user's gives 404;
   - catalog ingredients have `is_mine: false`;
   - a user's ingredient with `fiber` `null` returns `fiber: null`;
   - the search still runs three queries.
3. **Create.** Add `StoreIngredientRequest`, key generation for ingredients and portions, `store`, and the route. Tests in `UserIngredientStoreApiTest`:
   - 401 without a token;
   - `201` with the detail shape, `is_mine: true`, a `u_` key of 28 characters and `p_` portion keys of 10 characters, in the order sent;
   - stored with `user_id`, `source` `user` and `source_id` `null`;
   - `fiber` omitted is stored and returned as `null`;
   - `portions` omitted gives `[]`;
   - each rule gives 422: a missing required field, an unknown category, a name of 0 or 101 characters after trimming, a macro below 0 or over 100, `kcal` over 900, `protein + carbs + fat` of 100.1, 11 portions, a portion label of 51 characters, grams of 0 or 5001, and a portion with a `key`;
   - fiber is outside the sum: `protein` 17, `carbs` 42, `fat` 31 and `fiber` 34 is accepted;
   - two ingredients with the same name are both created, with different keys.
4. **Update.** Add `UpdateIngredientRequest`, `IngredientPolicy`, its registration, `update`, and the route. Tests in `UserIngredientUpdateApiTest`:
   - 401 without a token, 403 on a catalog ingredient (even with an invalid body), and 404 on another user's or an unknown key;
   - one field sent changes only that field;
   - `fiber: null` clears it, and `name: null` gives 422;
   - the sum is checked against stored values: `{ "fat": 80 }` with stored `protein` 30 gives 422;
   - `portions` absent leaves portions unchanged;
   - `portions` with one kept key, one new item and one left out: the kept one is updated with the same key, the new one gets a new key, the left-out one is gone, and positions follow the list;
   - `portions: []` removes them all;
   - a key from another ingredient, a soft-deleted portion's key, or the same key twice gives 422;
   - a left-out portion that is referenced through a `RESTRICT` foreign key (a test-only table, as in `CatalogRemover`'s tests) is soft-deleted, not hard-deleted.
5. **Delete.** Add `destroy` and the route. Tests in `UserIngredientDeleteApiTest`:
   - 401 without a token, 403 on a catalog ingredient, 404 on another user's or an unknown key;
   - `204` with no body, and the ingredient and its portions are gone from the database;
   - afterwards, detail gives 404 and search and `my-ingredients` don't return it;
   - an ingredient referenced through a `RESTRICT` foreign key (test-only table) is soft-deleted, and still gives 404 afterwards.
6. **My ingredients.** Add `ListMyIngredientsRequest`, `mine`, and the route. Tests in `MyIngredientsApiTest`:
   - 401 without a token;
   - only the caller's ingredients, not catalog ones or another user's, sorted by name then key, with `is_mine: true`;
   - default page of 50, `per_page=100` works, `per_page=101` and `page=0` give 422, and `meta.total` equals the caller's count;
   - no `nutrients` or `source` on items;
   - a user with none gets `200` with `data: []`;
   - 5 and 15 ingredients run the same number of queries, four.
7. **Docs and records.** PHPDoc and `@example`s per `docs/code-conventions.md`, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that the four new operations sit under `Nutrition` with their 201/204/401/403/404/422 responses, that `fiber`, `source.id` are nullable and `is_mine` is required. Extend `OpenApiDocumentTest` for those three. Update SPEC 16's response shapes (`is_mine`, nullable `fiber`) and its `source` rule in place, and add to its header: `> **Note:** responses gain is_mine, per_100g.fiber and source.id become nullable, and search includes the caller's own ingredients, by SPEC 18.` Add to SPEC 17's header: `> **Note:** user ingredients, their keys and their source are defined by SPEC 18; photo uploads are still pending.` Add the `u_` key rule to `docs/ingredient-catalog.md` and extend `IngredientCatalogTest`. Update `CLAUDE.md`: users' ingredients, the endpoints, visibility, nullable `fiber`, and the policy.
8. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` makes `ingredients.fiber` and `ingredients.source_id` nullable, and rolling back restores `NOT NULL`.
- [ ] `POST /ingredients` creates a private ingredient with a `u_` key, `source` `user`, `source_id` `null`, and portions with `p_` keys, and returns `201` with the detail shape.
- [ ] Creating rejects a macro outside 0–100, `kcal` over 900, `protein + carbs + fat` over 100, a name outside 1–100 characters, more than 10 portions, and invalid portion labels or grams, with 422.
- [ ] Fiber is optional, stored as `null` when not sent, and outside the sum rule.
- [ ] `PATCH` changes only the fields sent, checks the sum on the merged values, and with `portions` updates kept keys, creates items without a key, and removes left-out portions.
- [ ] `PATCH` and `DELETE` give 403 on a catalog ingredient and 404 on another user's.
- [ ] `DELETE` returns `204`, and the ingredient is hard-deleted, or soft-deleted when a `RESTRICT` foreign key references it.
- [ ] `GET /my-ingredients` returns only the caller's ingredients, paginated (default 50, max 100), sorted by name, in four queries.
- [ ] Search and detail include the caller's ingredients and never another user's, and the search still runs three queries.
- [ ] Every ingredient response has `is_mine`, and a user's ingredient returns `source: { "type": "user", "id": null }`, `photos: []` and `nutrients: []`.
- [ ] The seeder rejects a `user` source and a key starting with `u_`.
- [ ] `docs/openapi/v1.json` is re-exported, with `fiber` and `source.id` nullable and `is_mine` required.
- [ ] SPEC 16 has its header note and updated response shapes, SPEC 17 has its header note, and `CLAUDE.md` and `docs/ingredient-catalog.md` are updated.

## Decisions

- **Yes:** photo uploads in their own spec. Storage, file validation and serving are a separate area, and this spec already covers data, endpoints and visibility.
- **Yes:** private ingredients only. **No:** sharing with other users in this spec. It needs a search limit, rules for edits and deletes seen by others, and trust and privacy decisions. It gets its own spec.
- **Yes:** `name`, `category`, the five macros and optional portions. It's what a food label gives, and portions are what meal logging will use. **No:** micronutrients, which few users would type in.
- **Yes:** `fiber` optional and nullable, for every ingredient. Many labels outside the US don't list fiber, and a `0` would silently lower fiber totals. It makes `per_100g.fiber` nullable in the contract, cheap now since no client uses the API yet. **No:** all five required, with users typing `0`. **No:** storing `0` when omitted.
- **Yes:** `IngredientSource::User` and a nullable `source_id`. **No:** reusing a USDA source or an empty string, which would lie about where values come from.
- **Yes:** `u_<ulid>` keys. They can't clash with catalog keys and don't change when the name changes. **No:** a slug from the name, which can collide and goes stale on rename.
- **Yes:** server-generated `p_<random>` portion keys that never change, and `PATCH` matching portions by key. Future meal logs reference portions with `ON DELETE RESTRICT`, so deleting and recreating every portion on each edit would break once a portion is referenced. **No:** regenerating `p1`, `p2`, … on every edit. **No:** separate portion endpoints, which add three operations for a list of at most 10.
- **Yes:** `PATCH` with partial updates and the sum rule checked on merged values. **No:** `PUT` with the full body.
- **Yes:** `protein + carbs + fat` ≤ 100 with fiber outside the sum, and `kcal` at most 900 with no cross-check. US and EU labels count fiber differently, and labels round.
- **Yes:** names not unique. Two "Protein bar" entries are told apart by `key`, and a uniqueness rule gets in the way of variants.
- **Yes:** 403 for a catalog ingredient and 404 for another user's. The catalog is visible, so 403 is honest. Another user's ingredient must not reveal that it exists.
- **Yes:** visibility enforced in the route binding and the search scope, plus a policy for ownership. One place decides what a user can see, and another decides what they can change.
- **Yes:** delete through `CatalogRemover`. It's a hard delete today and becomes a soft delete automatically once meal logs reference the ingredient. **No:** always soft delete, which keeps rows nothing needs. **No:** always hard delete with a 409, which would block users once meal logs exist.
- **Yes:** a separate paginated `GET /my-ingredients` with no cap, like SPEC 12. **No:** a cap of 500 with an unpaginated list. **No:** making `search` optional on the existing endpoint.
- **Yes:** the caller's ingredients mixed into search with SPEC 16's ranking and no priority, marked by `is_mine`. One search box finds everything.
- **Yes:** a user's name returned as written, with no translations. The user typed it in their own language.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| A missing visibility check leaks another user's ingredient. | Visibility lives in the route binding and in `scopeSearch`, and tests cover search, detail, update, delete and `my-ingredients` with a second user's ingredient. |
| Making `fiber` and `source_id` nullable breaks code that assumes a value. | The resource and OpenAPI mark both nullable, and the seeder still requires them for catalog entries. No client uses the API yet. |
| Search results grow with each user's own ingredients, still unpaginated. | Only the caller's own are added, not every user's. The sharing spec revisits the limit. |
| A user creates huge numbers of ingredients. | `my-ingredients` is paginated. Rate limiting is a separate topic. |
| Edits change values that future meal logs depend on. | CLAUDE.md requires meal logs to store grams at save time. Whether they also snapshot macros is decided in the meal-logging spec. |
| `fiber` stays `null` for some ingredients, and clients sum it as `0`. | The contract documents `null` as "no data", and clients leave it out of totals. |

## What is **not** in this spec

- Photo uploads (their own spec, next).
- Sharing ingredients with other users (their own spec).
- Micronutrients, translations and aliases on a user's ingredient.
- Copying a catalog ingredient into a user's own.
- A cap on ingredients per user, and rate limiting.
- Separate portion endpoints.
- Frontend changes.
