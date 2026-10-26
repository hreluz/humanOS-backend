# SPEC 12 — Exercise list API

> **Status:** Draft
> **Depends on:** SPEC 04, SPEC 11
> **Date:** 2026-10-26
> **Objective:** Add `GET /api/v1/training/exercises`: the exercise catalog, paginated, sorted by name, with equipment, category, level and name filters.

## Why this spec exists

SPEC 11 fixed the exercise resource on the detail endpoint. The list is what client screens browse: the equipment tabs, the category and level chips, and the search box. This spec adds it with every filter except the muscle one. Filtering by muscle brings a ranking of its own and lands in SPEC 13.

## Scope

**In:**

- `GET /api/v1/training/exercises`, paginated.
- A `ListExercisesRequest` FormRequest with the filters below.
- The list query in a scope on `Exercise`.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- `muscle` and `role` filters and the muscle ranking (SPEC 13).
- Full-text search beyond a simple name match.
- HTTP and server-side caching.

## Endpoint

### `GET /api/v1/training/exercises`

Requires a bearer token (401 otherwise).

Query parameters (`ListExercisesRequest`, all optional):

| Parameter | Rule | Meaning |
|---|---|---|
| `equipment_group[]` | group keys that exist | Only exercises whose equipment is in one of these groups |
| `equipment[]` | equipment keys that exist | Only exercises with one of these pieces of equipment |
| `category[]` | `ExerciseCategory` values | Only these categories |
| `level[]` | `ExerciseLevel` values | Only these levels |
| `search` | string, 2–100 chars | Case-insensitive match anywhere in `name` |
| `page` | integer ≥ 1 | Page number (default 1) |
| `per_page` | integer 1–100 | Page size (default 50) |

Filters combine with AND, and values inside one array filter combine with OR. An unknown key or value gives 422.

**Order:** by `name`, then `key`.

**Response:** a paginated list (SPEC 04 "Paginated list"): `ExerciseResource::collection($paginator)`, so `{ "data": [...], "links": {...}, "meta": {...} }`. Items are the SPEC 11 resource without the detail-only fields (`instructions`, `source_id`).

```json
{
  "data": [
    { "key": "ab_roller", "name": "Ab Roller", "category": "strength", "…": "…" }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "per_page": 50, "total": 927, "…": "…" }
}
```

Queries: the count, the page, plus eager loads of `equipment.group`, `muscles` and `photos`. A constant number whatever the page size. No N+1.

## Code

### Files

```
app/Modules/Training/
  routes.php                                       (add the index route)
  Http/Controllers/ExerciseController.php          (add index)
  Http/Requests/ListExercisesRequest.php
  Models/Exercise.php                              (scopeFilter)
tests/Feature/Training/ExerciseListApiTest.php
tests/Feature/Docs/OpenApiDocumentTest.php         (the index operation under the Training tag)
docs/openapi/v1.json                               (re-export)
```

- The list query lives in a query scope on `Exercise` (`scopeFilter(ListExercisesRequest $filters)`). If SPEC 13 makes it grow past these filters, it moves to a small `ExerciseQuery` class there. The controller stays thin.
- `ListExercisesRequest` validates keys with `Rule::exists` and enum values with `Rule::enum`, so every invalid value is a 422 and not an empty page.
- Docs follow `docs/code-conventions.md`: a summary and description, and `@example` on every query rule.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 11 is merged.

1. **Route.** Add the index route. Check: `php artisan route:list --path=api/v1/training` lists four routes.
2. **Request, query and controller.** Tests:
   - 401 without a token;
   - default page: 50 items, `meta.total` equals the exercise count, sorted by name then key;
   - `equipment_group[]=kettlebell&equipment_group[]=bands`: only those groups;
   - `equipment[]`, `category[]`, `level[]` on their own;
   - `search` matches anywhere in the name, case-insensitive; 1 and 101 characters give 422;
   - filters combined (`equipment_group[]` + `level[]` + `search`);
   - `per_page=100` works, `per_page=101` and `page=0` give 422;
   - unknown `equipment_group`, `equipment`, `category` or `level` gives 422;
   - the list has no `instructions` or `source_id` field;
   - query count: a page of 10 and a page of 100 run the same number of queries (assert with `DB::getQueryLog()`).
3. **Docs.** PHPDoc and `@example`s; `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that the operation sits under `Training` with the query parameters, the paginated schema, the 401 and 422 responses, and that `instructions` is not documented as a required list field. `composer test` passes.
4. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `/exercises` is paginated (default 50, max 100) with the SPEC 04 paginated envelope, sorted by name.
- [ ] Every filter works on its own and combined, and an invalid value gives 422.
- [ ] List items have no `instructions` or `source_id`.
- [ ] The endpoint runs a constant number of queries per page.
- [ ] `docs/openapi/v1.json` is re-exported and documents the operation under `Training`.

## Decisions

- **Yes:** pagination. 927 exercises with photos is too much for one response, and SPEC 04 already defined the paginated envelope.
- **Yes:** 422 on unknown filter values, not an empty result. A typo in a client shows up in development instead of as an empty screen.
- **Yes:** a `LIKE` match on `name` for search. Good enough for 927 names; full-text search waits for a need.
- **No:** the muscle filter here. Its ranking is the hardest part of the list and gets its own review (SPEC 13).

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| `LIKE '%…%'` can't use an index. | 927 rows; measure before optimising. |
| Scramble doesn't infer the paginated schema or the array query parameters. | Check the export by hand (step 3) and use Scramble's documented mechanisms, not hand-written OpenAPI. |
