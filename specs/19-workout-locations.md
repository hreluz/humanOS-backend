# SPEC 19 — Workout locations

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 08, SPEC 10, SPEC 12, SPEC 13
> **Date:** 2026-11-10
> **Objective:** Let a user save named workout locations ("Gym Local", "House workout") with the exercises they can do at each one, picked by muscle and equipment group, and filter the exercise list by a location.

## Why this spec exists

A user doesn't have every machine everywhere they train. Their home has dumbbells and bands, a small gym has a leg press but no lat pulldown, and a big gym has everything. The 12 equipment types (SPEC 07) can't say that: `machine` is all or nothing. Saving the exercises themselves can. Ticking "Leg Press" records that the location has a leg press, with no finer equipment catalog and no remapping of the 927 exercises.

The client picks exercises with the flow the body page already uses: tap a muscle, pick an equipment group tab, then add exercises from the list (`GET /exercises?muscle=…&equipment_group[]=…`, SPEC 12/13). The only difference is that "add" saves the exercise to the location, not to today's workout. Afterwards `GET /exercises?location=<key>` answers "what can I do here" with a single parameter.

## Scope

**In:**

- `workout_locations` and `workout_location_exercise` tables in the `Training` module, with a `WorkoutLocation` model.
- CRUD for the caller's own locations under `/api/v1/training/locations` (name only).
- Adding and removing one exercise at a time on a location.
- A `location` filter on `GET /api/v1/training/exercises`.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Equipment on a location. A location stores exercises only.
- A finer equipment catalog (specific machines, benches, bars). Picking exercises replaces it for locations.
- Setting or replacing the whole exercise list in one request, or copying a location.
- Notes per exercise at a location (available weights, machine settings).
- Exercises a user creates.
- Locations given to users by default on registration.
- A default or "current" location stored on the server. The client keeps the selection.
- Sharing locations between users.
- The recommendation engine using locations.
- Frontend changes.

## Data model

### Migrations

```php
// database/migrations/<date>_create_workout_locations_table.php
Schema::create('workout_locations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('key', 28)->unique();          // l_<ulid>
    $table->string('name', 50);
    $table->timestamps();
    $table->unique(['user_id', 'name']);           // utf8mb4_unicode_ci: ignores case and accents
});

// database/migrations/<date>_create_workout_location_exercise_table.php
Schema::create('workout_location_exercise', function (Blueprint $table) {
    $table->foreignId('workout_location_id')->constrained()->cascadeOnDelete();
    $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
    $table->primary(['workout_location_id', 'exercise_id']);
});
```

- `key` is `l_` plus a ULID, generated on create. It never changes, and it's what the API exposes. `id` is only for foreign keys.
- `name` is unique per user, ignoring case and accents, through the column collation (`utf8mb4_unicode_ci`). Validation checks it first, so a duplicate is a 422 and not a 500.
- Locations are deleted with their user. Exercise rows are deleted with their location. `restrictOnDelete` on `exercise_id` follows the project rule for catalog references (CLAUDE.md, "Referencing the catalog"): an exercise a location uses can't be hard-deleted.
- No soft deletes. Nothing references a location yet.

### Model (`app/Modules/Training/Models/WorkoutLocation.php`)

- `user(): BelongsTo`. `exercises(): BelongsToMany` (`workout_location_exercise`), no timestamps, ordered by exercise `name`, then `key`.
- `scopeOwnedBy(Builder $query, User $user)`.
- `User::workoutLocations(): HasMany`.
- A factory, for tests.

### Limits

- `name`: required on create, trimmed, 1–50 characters, unique among the caller's locations. Uniqueness ignores case and accents (the `utf8mb4_unicode_ci` collation), so "Gimnasio" and "gimnásio" are the same name.
- At most **20** locations per user. Creating a 21st is a 422 on `name`, with the message "You can have at most 20 workout locations."
- No cap on exercises per location. The catalog (927 exercises) bounds it, and each exercise is in a location at most once (the primary key).

## Endpoints

All under `/api/v1/training` (bearer token, so 401 without one), using the SPEC 04 envelope. A location that belongs to another user, or an unknown key, is a **404** on every `{workoutLocation}` route. The route binding only resolves the caller's own locations. The parameter is `{workoutLocation:key}`, not `{location}`: `Route::bind` is global to the app, and a generic name would capture any later module's `{location}` parameter. `{exercise:key}` is the existing exercise binding (SPEC 11), so an unknown exercise is a 404.

### Location resource

In the list:

```json
{
  "key": "l_01JC9Z7M4Q8N2R5T6V7W8X9Y0Z",
  "name": "Gym Local",
  "exercise_count": 2,
  "created_at": "2026-11-10T10:00:00Z",
  "updated_at": "2026-11-10T10:00:00Z"
}
```

On `POST`, `GET` show and `PATCH`, it also has `exercises`:

```json
{
  "key": "l_01JC9Z7M4Q8N2R5T6V7W8X9Y0Z",
  "name": "Gym Local",
  "exercise_count": 2,
  "exercises": ["barbell_bench_press", "dumbbell_bicep_curl"],
  "created_at": "2026-11-10T10:00:00Z",
  "updated_at": "2026-11-10T10:00:00Z"
}
```

`exercises` holds exercise keys sorted by exercise name, then key, the same order as the plain exercise list. Clients use it to mark exercises already at the location while browsing. Full exercise cards come from `GET /exercises?location=<key>`.

| Method | Path | Body | Response |
|---|---|---|---|
| `GET` | `/locations` | — | 200, the caller's locations with `exercise_count`, sorted by `name`, then `key`. Not paginated (at most 20). |
| `POST` | `/locations` | `name` | 201, the location (with `exercises: []`) |
| `GET` | `/locations/{workoutLocation}` | — | 200, the location with `exercises` |
| `PATCH` | `/locations/{workoutLocation}` | `name` (optional) | 200, the location with `exercises` |
| `DELETE` | `/locations/{workoutLocation}` | — | 204 |
| `PUT` | `/locations/{workoutLocation}/exercises/{exercise}` | — | 204. Adds the exercise. Already there is also 204. |
| `DELETE` | `/locations/{workoutLocation}/exercises/{exercise}` | — | 204. Removes the exercise. Not there is also 204. |

- A `PATCH` with an empty body is a 200 that returns the location unchanged. Renaming a location to its own current name is allowed. Renaming it to the name of another of the caller's locations, in any case, is a 422.
- `PUT` and `DELETE` on an exercise are idempotent, so a double tap or a retry is safe. They move the location's `updated_at` only when they change something.

### Exercise filter

`GET /api/v1/training/exercises` gets one more optional parameter in `ListExercisesRequest`:

| Parameter | Rule | Meaning |
|---|---|---|
| `location` | a key of one of the caller's locations | Only the exercises saved at that location |

- Another user's location key, or an unknown one, is a 422, as with the other filters.
- It combines with the SPEC 12/13 filters using AND. For example, `location=…&muscle=quads` gives the location's quad exercises in the SPEC 13 ranking order, and `location=…&muscle=lower_chest&equipment_group[]=gym` is what the body page uses to mark what's already added.
- Filtering a location by muscle uses the existing SPEC 13 filter. `location=…&muscle=quads` gives its quad exercises ranked primaries first, and `&role=primary` keeps only those where quads are primary. `location=…&muscle=lower_chest&equipment_group[]=dumbbell` gives the chest exercises it has for dumbbells.
- Filtering a location by equipment uses the existing filters: `location=…&equipment_group[]=gym` gives its gym exercises, and `location=…&equipment[]=barbell` only its barbell ones. The location doesn't store or return its equipment. A client that shows all six equipment tabs gets an empty page for a tab with nothing at the location.
- A location with no exercises returns an empty page.
- `scopeFilter` adds `whereIn('exercises.id', <the location's exercise ids>)`. That's one extra query to read the ids, and the count per page stays constant.

## Code

### Files

```
app/Modules/Training/
  routes.php                                         (add the location routes)
  TrainingServiceProvider.php                        (Route::bind('workoutLocation') to the caller's own)
  Models/WorkoutLocation.php
  Models/Exercise.php                                (scopeFilter: location)
  Http/Controllers/WorkoutLocationController.php     // #[Group('Training')]
  Http/Controllers/WorkoutLocationExerciseController.php  // #[Group('Training')], PUT and DELETE
  Http/Requests/{StoreWorkoutLocationRequest,UpdateWorkoutLocationRequest}.php
  Http/Requests/ListExercisesRequest.php             (location rule)
  Http/Resources/WorkoutLocationResource.php         (withDetails() adds `exercises`)
app/Models/User.php                                  (workoutLocations())
database/migrations/<date>_create_workout_locations_table.php, <date>_create_workout_location_exercise_table.php
database/factories/WorkoutLocationFactory.php
tests/Feature/Training/{WorkoutLocationApiTest,WorkoutLocationExerciseApiTest,ExerciseLocationFilterTest}.php
tests/Feature/Docs/OpenApiDocumentTest.php          (new operations under the Training tag)
docs/openapi/v1.json                                 (re-export)
```

- No policy. The binding only resolves the caller's own locations, so ownership is settled before the controller runs. Unlike ingredients (SPEC 18), there's no shared catalog row that a user can see but not edit.
- Scramble evaluates `rules()` with no authenticated user, so rules that read the user use `$this->user()?->getKey()`.
- Docs follow `docs/code-conventions.md`: a summary and description on each method, `@example` on every resource field and request rule.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-18-user-ingredients`, and rebase onto `master` once it's merged.

1. **Schema and model.** The two migrations, `WorkoutLocation`, `User::workoutLocations()` and the factory. Check: `php artisan migrate` runs, and `migrate:rollback` drops both tables.
2. **Location CRUD.** Routes, binding, the two requests, the resource, and `WorkoutLocationController` (`index`, `store`, `show`, `update`, `destroy`). Tests in `WorkoutLocationApiTest`:
   - 401 without a token on every route;
   - create gives 201, an `l_` key, `exercise_count` 0 and `exercises: []`;
   - a missing, blank or 51-character name gives 422;
   - a duplicate name in a different case or accent gives 422, while the same name for another user is fine;
   - the 21st location gives 422 with the message, and another user's locations don't count;
   - the list holds only the caller's locations, sorted by name then key, with `exercise_count` and no `exercises`;
   - an empty `PATCH` gives 200 and changes nothing; renaming works, keeping the same name works, renaming to another location's name gives 422;
   - delete gives 204, and its exercise rows are gone;
   - another user's key gives 404 on show, update and delete;
   - deleting the user deletes their locations and exercise rows.
3. **Adding and removing exercises.** The two routes and `WorkoutLocationExerciseController`. Tests in `WorkoutLocationExerciseApiTest`:
   - `PUT` gives 204 and the exercise shows on show, sorted by name then key, with `exercise_count` updated;
   - `PUT` twice gives 204 and one row; `DELETE` gives 204 and removes it; `DELETE` of an exercise that isn't there gives 204;
   - `updated_at` moves on a change and stays on a no-op;
   - an unknown exercise key gives 404; another user's location gives 404 and their rows are untouched;
   - a location's exercise blocks hard-deleting that exercise (`QueryException`).
4. **Exercise filter.** The `location` rule and the `scopeFilter` change. Tests in `ExerciseLocationFilterTest`:
   - only the location's exercises come back;
   - an empty location gives `total` 0;
   - combined with `equipment_group[]`, `equipment[]` and `search`;
   - combined with `muscle` (SPEC 13): only the location's exercises, still in the muscle ranking order, and with `role=primary`;
   - combined with `muscle` and `equipment_group[]` together;
   - another user's or an unknown key gives 422;
   - the query count is the same for a page of 10 and a page of 100.
5. **Docs.** PHPDoc and `@example`s; `php artisan scramble:export --path=docs/openapi/v1.json`, with no Scramble warnings from the new code (`php artisan scramble:analyze`). Add the seven operations to `OpenApiDocumentTest`. Check by hand that they sit under `Training` with their 401/404/422 responses and the new `location` parameter. `composer test` passes.
6. **Housekeeping.** Update `CLAUDE.md` (a Training line for workout locations), then run `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` creates `workout_locations` and `workout_location_exercise`, and rolling back drops them.
- [ ] `php artisan route:list --path=api/v1/training/locations` lists seven routes, all behind `auth:sanctum`.
- [ ] Creating a location returns 201 with an `l_` key and `exercises: []`.
- [ ] A duplicate name (any case) for the same user, or a 21st location, gives 422.
- [ ] An empty `PATCH` gives 200 and changes nothing.
- [ ] `PUT` adds an exercise and `DELETE` removes it, both 204 and both idempotent. Show lists the keys sorted by exercise name, and the list shows `exercise_count`.
- [ ] An unknown exercise key gives 404 on `PUT` and `DELETE`.
- [ ] Another user's location is a 404 on show, update, delete, and adding or removing an exercise.
- [ ] Deleting a user deletes their locations and their exercise rows.
- [ ] `GET /exercises?location=<key>` returns only the location's exercises, and an empty location returns none. Combined with `muscle`, the SPEC 13 ranking still applies.
- [ ] `GET /exercises?location=<another user's key>` gives 422.
- [ ] `docs/openapi/v1.json` is re-exported and documents the seven operations and the `location` parameter under `Training`.

## Decisions

- **Yes:** a location stores exercises, not equipment. Ticking "Leg Press" says the location has a leg press, which the 12 equipment types can't.
- **No:** equipment on a location, alone or next to exercises. Two lists would have to agree, and it would be hard to explain why an exercise shows up.
- **No:** a finer equipment catalog (specific machines, benches, bars) and remapping the 927 exercises to it. Picking exercises gives locations the same precision with no catalog work.
- **Yes:** filtering a location's exercises by equipment with the existing `equipment_group[]` and `equipment[]` filters. Each exercise already has its equipment.
- **No:** returning the location's equipment groups, derived from its exercises, on the location. Clients show all six tabs, and an empty tab is an empty page. It can be added later without breaking anything.
- **Yes:** the client picks with the body page's flow (muscle, then equipment group, then exercises), using the existing exercise list. No new browse endpoint.
- **Yes:** one exercise at a time with `PUT` and `DELETE`, both idempotent. It matches the tap-to-add button, the client never sends the whole list, and two devices adding at once don't overwrite each other.
- **No:** a full `exercises` list on `POST` or `PATCH`. The last writer would drop the other device's additions. Bulk setup or copying a location can come later.
- **Yes:** `exercise_count` on the list and `exercises` keys only on the detail. The list stays light with 20 locations, and the detail gives the body page its "already added" marks.
- **Yes:** `exercises` sorted by exercise name, then key, like the exercise list. **No:** the order they were added, which needs a timestamp column and isn't what any screen shows.
- **No:** a cap on exercises per location. The catalog bounds it, and the primary key stops duplicates.
- **Yes:** an unknown exercise on `PUT`/`DELETE` is a 404 (the existing `{exercise:key}` binding). An unknown or foreign `location` on the list is a 422, matching SPEC 12's rule for unknown filter values. On `{workoutLocation}` routes it's a 404, matching SPEC 18.
- **Yes:** adding or removing an exercise moves `updated_at`, but only when something changed.
- **Yes:** a generated `l_<ulid>` key, not a slug of the name. A rename never changes the key a client saved.
- **Yes:** at most 20 locations, not paginated. A real user has a handful, and one list is simpler for clients.
- **Yes:** the route parameter is `workoutLocation`, not `location`. `Route::bind` is global, so a generic name would leak into other modules.
- **Yes:** an empty `PATCH` is a 200 no-op, not a 422. Sending only the fields to change is the normal `PATCH` contract.
- **No:** a policy. The binding is scoped to the caller, so there's no row a user can see but not change.
- **No:** soft deletes. Nothing references a location yet. A later spec that logs workouts at a location adds them if it needs them.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Setting up a big gym means many taps. | Accepted. The body page groups exercises by muscle and equipment, so a user adds what they use. Bulk setup or copying a location is listed as a future spec. |
| A later spec removes or renames an exercise that a location uses. | `restrictOnDelete` blocks a hard delete. Exercise keys are permanent (SPEC 08), so a rename keeps the row. |
| Case-insensitive uniqueness depends on the MySQL collation. | Validation checks it before the insert, and a test covers a different-case duplicate. |
| Two concurrent `POST`s pass the 20-location check together and leave a user with 21. | Accepted. The cap protects against abuse, not exact counts, and one extra row is harmless. No locking. |
| Scramble evaluates `rules()` without a user and drops a rule that reads it. | Rules use `$this->user()?->getKey()`, and step 5 checks `scramble:analyze` has no warnings from the new code. |

## What is **not** in this spec

- Equipment on a location, or a finer equipment catalog.
- Setting the whole exercise list at once, copying a location, or notes per exercise.
- Default locations, or a current location stored on the server.
- Sharing locations, or the recommendation engine using them.
- Frontend changes.
