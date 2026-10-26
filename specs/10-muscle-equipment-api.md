# SPEC 10 — Muscle areas and equipment groups API

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 06, SPEC 07, SPEC 09
> **Date:** 2026-10-26
> **Objective:** Open the Training module's API under `/api/v1/training` with two read-only, authenticated endpoints that return the muscle and equipment catalogs as trees, documented by Scramble.

## Why this spec exists

SPECs 06–09 put 30 muscles, 12 pieces of equipment and 927 exercises in the database, but nothing reads them yet. The frontend still ships its own copy (`humanOS-frontend/app/services/mocks/exercises.ts`, `app/utils/muscles.ts`): 138 exercises, three equipment tabs and no neck.

The read API is split into four specs so each PR stays small:

| Spec | Adds |
|---|---|
| **10** (this one) | Training routes, `/muscle-areas`, `/equipment-groups` |
| 11 | `/exercises/{key}` and the exercise resources |
| 12 | `/exercises`: pagination and the equipment, category, level and search filters |
| 13 | `/exercises?muscle=`: the muscle filter, `role`, and the frontend's ranking |

This spec does the two small trees first. Exercises return muscles and equipment as keys (SPEC 11), and clients get the labels from these two endpoints.

## Scope

**In:**

- `TrainingServiceProvider` loads `app/Modules/Training/routes.php` under `api/v1/training` with the `api` and `auth:sanctum` middleware.
- `GET /api/v1/training/muscle-areas`: the six areas in display order, each with its muscles.
- `GET /api/v1/training/equipment-groups`: the six groups in display order, each with its equipment.
- API Resources for areas, muscles, groups and equipment.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- The exercise endpoints (SPECs 11–13).
- Writing to the catalog.
- HTTP caching (`ETag`, `Cache-Control`) and server-side caching. Both trees are tiny and change only on a reseed.
- The frontend switching to the API and drawing `neck` (a frontend spec).
- Translations of labels.

## Endpoints

Both require a bearer token (401 otherwise), use the SPEC 04 envelope, and are not paginated.

### `GET /api/v1/training/muscle-areas`

```json
{
  "data": [
    {
      "key": "chest",
      "label": "Chest",
      "muscles": [
        { "key": "upper_chest", "label": "Upper chest", "body_view": "front" },
        { "key": "lower_chest", "label": "Mid & lower chest", "body_view": "front" },
        { "key": "serratus", "label": "Serratus", "body_view": "front" }
      ]
    }
  ]
}
```

Areas are ordered by `sort_order`, and so are the muscles within each area.

### `GET /api/v1/training/equipment-groups`

```json
{
  "data": [
    {
      "key": "gym",
      "label": "Gym",
      "equipment": [
        { "key": "barbell", "label": "Barbell" },
        { "key": "machine", "label": "Machine" }
      ]
    }
  ]
}
```

Groups are ordered by `sort_order`, and so is the equipment within each group. `source_key` (free-exercise-db's value) is internal and not returned.

Queries: two per endpoint (the parents, then the eager-loaded children, ordered).

## Code

### Files

```
app/Modules/Training/
  TrainingServiceProvider.php             (load routes.php: prefix api/v1/training, middleware api + auth:sanctum)
  routes.php
  Http/Controllers/MuscleAreaController.php       // #[Group('Training')]
  Http/Controllers/EquipmentGroupController.php   // #[Group('Training')]
  Http/Resources/{MuscleAreaResource,MuscleResource,EquipmentGroupResource,EquipmentResource}.php
tests/Feature/Training/{MuscleAreaApiTest,EquipmentGroupApiTest}.php
tests/Feature/Docs/OpenApiDocumentTest.php       (Training operations under the Training tag)
docs/openapi/v1.json                             (re-export)
```

- Both controllers are single-action (`__invoke`).
- `MuscleResource` and `EquipmentResource` are reused by later specs if an exercise ever needs nested objects; for now only these trees use them.
- Docs follow `docs/code-conventions.md`: a group, a summary and description on each method, and `@example` on every resource field.

## Implementation plan

All commands run inside the `app` container. Branch from `master`.

1. **Routes.** `TrainingServiceProvider` loads `routes.php`. Check: `php artisan route:list --path=api/v1/training` lists two routes, both behind `auth:sanctum`.
2. **Muscle areas.** Controller and resources. Tests: 401 without a token; 200 with the six areas and 30 muscles in order, including `neck` first in `back`.
3. **Equipment groups.** Controller and resources. Tests: 401 without a token; 200 with the six groups and 12 pieces of equipment in order; `source_key` absent.
4. **Docs.** PHPDoc and `@example`s; `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that both operations sit under the `Training` tag with the 401 response. `composer test` passes (drift and envelope tests).
5. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan route:list --path=api/v1/training` lists the two routes, both behind `auth:sanctum`.
- [ ] Both endpoints return 401 without a bearer token.
- [ ] `/muscle-areas` returns 6 areas and 30 muscles in display order.
- [ ] `/equipment-groups` returns 6 groups and 12 pieces of equipment in display order, without `source_key`.
- [ ] `docs/openapi/v1.json` is re-exported and documents both operations under `Training`.

## Decisions

- **Yes:** authenticated endpoints. Every client screen that shows the catalog is behind login, and it matches the other modules. If onboarding ever needs the catalog before login, those routes can move to `@unauthenticated`.
- **Yes:** areas and groups returned nested, one request each. Clients need the whole tree to draw the body map and the equipment tabs, and both are tiny.
- **Yes:** the module-wide middleware set in the provider, not per route. Every Training endpoint in SPECs 10–13 is authenticated.
- **No:** caching. The data changes only on a reseed; add `ETag` support when a client needs it.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Clients receive `neck` before they can draw it. | Clients ignore muscles they have no region for; the frontend spec handles it. |
| Scramble doesn't infer the nested resource schemas. | Check the export by hand (step 4) and use Scramble's documented mechanisms, not hand-written OpenAPI. |
