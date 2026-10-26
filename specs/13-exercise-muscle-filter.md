# SPEC 13 — Exercise list: muscle filter and ranking

> **Status:** Draft
> **Depends on:** SPEC 12
> **Date:** 2026-10-26
> **Objective:** Add `muscle` and `role` filters to `GET /api/v1/training/exercises`, ranked the way the frontend's `getExercises` does it today, in SQL so pagination stays correct.

## Why this spec exists

Tapping a muscle on the body map is the main way into the catalog. The frontend's `trainingService.ts` lists exercises that target that muscle first, the most direct ones on top, then the ones where it only assists. With 927 exercises and a paginated list (SPEC 12), that ranking has to happen on the server: sorting one page at a time on the client would give wrong pages. This is the only part of the read API with a non-trivial query, so it gets its own spec and review.

## Scope

**In:**

- `muscle` and `role` query parameters on `ListExercisesRequest`.
- The ranking below, when `muscle` is set.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- Filtering by several muscles or by muscle area.
- Recommendation-style ranking (history, recovery, goals). That's the recommendation engine.

## Filters

Added to the SPEC 12 table:

| Parameter | Rule | Meaning |
|---|---|---|
| `muscle` | muscle key that exists | Only exercises tagged with this muscle (primary or secondary) |
| `role` | `primary` \| `secondary`, needs `muscle` | Only exercises where `muscle` has this role |

They combine with the SPEC 12 filters with AND. An unknown `muscle`, an unknown `role`, or `role` without `muscle` gives 422.

## Order

With `muscle`:

1. Exercises that list it as **primary** come first, ordered by:
   1. its `sort_order` in their primaries (most direct first),
   2. then how many primaries they have (fewest first),
   3. then `name`, then `key`.
2. Then exercises that list it as **secondary**, ordered by `name`, then `key`.

This is the frontend's `getExercises` order. Without `muscle`, the SPEC 12 order (`name`, `key`) is unchanged.

`role=primary` returns only group 1, `role=secondary` only group 2, each in its own order.

## Query

- A join on `exercise_muscle` for the requested muscle gives its `role` and its `sort_order` (the pivot stores both, SPEC 08); the join also does the filtering.
- A `withCount` of primary tags gives the number of primaries.
- `ORDER BY` role, position, primary count, `name`, `key`.
- Eager loads stay as in SPEC 12, so the query count stays constant.

If the scope grows past the filters, it moves from `Exercise::scopeFilter` to a small `ExerciseQuery` class (SPEC 12).

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 12 is merged.

1. **Request.** Add the `muscle` and `role` rules. Tests: unknown `muscle` gives 422; unknown `role` gives 422; `role` without `muscle` gives 422.
2. **Query.** Tests:
   - `muscle=quads`: every item has `quads` in `primary` or `secondary`; all primaries come before all secondaries;
   - among primaries, an exercise with `quads` first in `primary` comes before one with `quads` second; at equal position, fewer primaries come first; then by name;
   - secondaries are sorted by name;
   - `role=primary` and `role=secondary` return only that group;
   - combined with SPEC 12 filters (`muscle` + `equipment_group[]`);
   - the order holds across pages: concatenating pages 1–3 with `per_page=10` equals the first 30 items with `per_page=30`;
   - the query count with `muscle` is constant, as in SPEC 12.
3. **Performance.** Run `EXPLAIN` on MySQL for the `muscle` query; the pivot lookup uses the `muscle_id` foreign-key index. Note the result in the PR.
4. **Docs.** `@example`s on the new rules; `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that `muscle` and `role` are documented with the 422 response. `composer test` passes.
5. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `/exercises?muscle=<key>` follows the order above: primaries by muscle position, then by number of primaries, then secondaries.
- [ ] `role` narrows to one group, and needs `muscle`.
- [ ] Pagination with `muscle` is stable across pages.
- [ ] Invalid `muscle` or `role` values give 422.
- [ ] The query count stays constant per page.
- [ ] `docs/openapi/v1.json` is re-exported with the new parameters.

## Decisions

- **Yes:** the frontend's ordering, done in SQL. The ranking is product behaviour, and doing it server-side keeps every client consistent and pagination correct.
- **Yes:** one muscle per request. The body map selects one muscle at a time; several muscles would need a ranking nobody has defined.
- **Yes:** `key` as the final tie-breaker. Two exercises with the same name would otherwise swap between pages.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The ranking is slow or awkward to paginate. | One join plus a count on an indexed pivot; `EXPLAIN` in step 3 and the cross-page test in step 2. |
| The server order drifts from what the frontend shows today. | The tests encode the `getExercises` rules; the frontend spec removes its own sort when it switches. |
