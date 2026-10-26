# SPEC 11 — Exercise detail API

> **Status:** Draft
> **Depends on:** SPEC 04, SPEC 08, SPEC 09, SPEC 10
> **Date:** 2026-10-26
> **Objective:** Add `GET /api/v1/training/exercises/{key}` and the exercise and photo resources that the list endpoint (SPEC 12) reuses.

## Why this spec exists

SPEC 10 opened the Training API with the muscle and equipment trees. This spec fixes the shape of an exercise in the API, on the simplest endpoint that returns one: a lookup by key. SPEC 12 then only has to add the list query and pagination around the same resource.

## Scope

**In:**

- `GET /api/v1/training/exercises/{key}`: one exercise with its instructions.
- `ExerciseResource` and `ExercisePhotoResource`, with the detail-only fields behind a flag.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope:**

- The exercise list, its filters and pagination (SPECs 12 and 13).
- Writing to the catalog (admin or user-created exercises).
- Workout logging and "add to today's workout" (`addExerciseToWorkout` in the frontend stays a mock).
- Translations of names, cues and instructions.

## Endpoint

### `GET /api/v1/training/exercises/{key}`

Requires a bearer token (401 otherwise). Route model binding on `key` (`{exercise:key}`). 404 if no exercise has that key.

```json
{
  "data": {
    "key": "barbell_bench_press",
    "name": "Barbell Bench Press",
    "category": "strength",
    "level": "beginner",
    "mechanic": "compound",
    "force": "push",
    "cue": "Shoulder blades pinned back, bar to mid-chest.",
    "equipment": "barbell",
    "equipment_group": "gym",
    "primary": ["lower_chest", "upper_chest"],
    "secondary": ["front_delts", "triceps_lateral", "triceps_long"],
    "photos": [
      {
        "url": "https://raw.githubusercontent.com/yuhonas/free-exercise-db/f00c92c7…/exercises/Barbell_Bench_Press_-_Medium_Grip/0.jpg",
        "caption": "Start",
        "source": "free_exercise_db",
        "license": "Unlicense",
        "author": null,
        "credit_url": null
      }
    ],
    "instructions": ["Lie back on a flat bench…", "…"],
    "source_id": "Barbell_Bench_Press_-_Medium_Grip"
  }
}
```

- `equipment` and `equipment_group` are keys. Clients get the labels from `/equipment-groups`, and the muscle labels from `/muscle-areas` (SPEC 10). This keeps list items small and matches the frontend's `MuscleKey[]` and `EquipmentKey` types.
- `primary` and `secondary` are muscle keys in `sort_order`.
- `photos` are in `position` order. `url` comes from `ExercisePhoto::url` (SPEC 08 config). `author` and `credit_url` are only set for wger photos, and clients must show them when set.
- `level`, `mechanic`, `force`, `cue` and `instructions` may be `null`.
- `source_id` is the free-exercise-db id, or `null`.
- `instructions` and `source_id` are **detail-only**: the list (SPEC 12) leaves them out.

Queries: the bound exercise plus eager loads of `equipment.group`, `muscles` and `photos`.

## Code

### Files

```
app/Modules/Training/
  routes.php                                       (add the show route)
  Http/Controllers/ExerciseController.php          // #[Group('Training')], show
  Http/Resources/{ExerciseResource,ExercisePhotoResource}.php
tests/Feature/Training/ExerciseShowApiTest.php
tests/Feature/Docs/OpenApiDocumentTest.php         (the show operation under the Training tag)
docs/openapi/v1.json                               (re-export)
```

- `ExerciseResource` returns `instructions` and `source_id` only on the show route, via a `withDetails()` flag or a `$this->when(...)` that Scramble documents as optional. Use whichever Scramble infers correctly, and check the exported schema.
- `ExerciseController` gets `index` in SPEC 12; this spec adds only `show`.
- Docs follow `docs/code-conventions.md`: a summary and description, `@example` on every resource field, and `@throws ModelNotFoundException` on `show` for the 404.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 10 is merged.

1. **Route.** Add the show route. Check: `php artisan route:list --path=api/v1/training` lists three routes.
2. **Resources and controller.** `ExercisePhotoResource`, `ExerciseResource`, `ExerciseController@show`. Tests:
   - 401 without a token;
   - 200 for a known key, with every field above, `primary` and `secondary` in `sort_order`, photos in `position` order;
   - `instructions` and `source_id` present; both `null` for an exercise that has none;
   - photo URLs follow `config('services.exercise_photos.*')`; a wger photo returns `author` and `credit_url`;
   - 404 for an unknown key, in the SPEC 04 error envelope.
3. **Docs.** PHPDoc and `@example`s; `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that the operation sits under `Training` with the 401 and 404 responses, and that the detail-only fields are in the schema. `composer test` passes.
4. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `/exercises/{key}` returns the fields above, including `instructions` and `source_id`.
- [ ] Unknown keys give 404, and missing tokens give 401.
- [ ] Photo items carry the URL built from config, and the license, author and credit URL.
- [ ] `docs/openapi/v1.json` is re-exported and documents the operation under `Training`.

## Decisions

- **Yes:** `key` in the URL, not `id`. Keys are stable across environments (SPEC 08), and ids aren't.
- **Yes:** muscles and equipment as keys inside exercises, not nested objects. Items stay small, and clients join against the two catalog responses they already hold (SPEC 10).
- **Yes:** detail before list. The resource shape gets reviewed on its own, without the query and pagination work.
- **No:** instructions in the list. They are the bulk of the payload and only the detail view shows them.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Scramble doesn't document the detail-only fields, or marks them required on the list. | Check the export by hand (step 3) and pick the mechanism Scramble infers correctly; re-check in SPEC 12. |
| Free-exercise-db or wger URLs break. | `exercises:check-photos` (SPEC 08) lists broken URLs; URLs come from config. |
