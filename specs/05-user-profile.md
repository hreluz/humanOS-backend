# SPEC 05 — User profile

> **Status:** Approved
> **Depends on:** SPEC 01, SPEC 02, SPEC 04
> **Date:** 2026-10-11
> **Objective:** Add a `Profile` module where an authenticated user saves their birth date, sex, height, weight, target weight and activity level in metric or imperial units, with every saved weight and target weight kept as readable history.

## Why this spec exists

The calorie and macro targets in the README (§1 "Personal Profile & Goals") and the recommendation engine both need body data. This spec only stores and returns that data. Weight and target weight change over time and their history matters (progress, plateaus, goal changes), so both are stored as entries, not as profile columns. It is the first module after `Auth`, so it also sets how a second module plugs in (its own provider, models, enums and tests).

## Scope

**In:**

- A new module `app/Modules/Profile` with its own service provider, registered in `bootstrap/providers.php`, loading `routes.php` under `api/v1/profile` with the `api` and `auth:sanctum` middleware.
- `profiles` table (one row per user, no weight columns), `weight_entries` table and `target_weight_entries` table.
- `PUT /api/v1/profile`: create or fully replace the profile. All fields required. Also appends one weight entry and one target weight entry.
- `GET /api/v1/profile`: return the profile with the latest weight and target weight, 404 if the user has none yet.
- `GET /api/v1/profile/weight-entries`: the user's weight history, newest first.
- `GET /api/v1/profile/target-weight-entries`: the user's target weight history, newest first.
- Units: the request says `unit_system` (`metric` | `imperial`). Values are read as cm/kg or in/lb, converted and stored in metric. The preference is saved and responses return values in it, plus the metric fields.
- Enums `ActivityLevel`, `Sex`, `UnitSystem` (with the conversion helpers).
- `hasOne` profile, `hasMany` weightEntries and targetWeightEntries relations on `User`.
- Factories, feature and unit tests, Scramble docs, `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Calorie / macronutrient target calculation (the reason `sex` and `activity_level` exist).
- Fitness objectives (fat loss, muscle gain, maintenance) and target dates.
- Logging a weight or target weight on its own (outside `PUT /profile`), editing or deleting entries, back-dating, pagination of the history.
- Other body metrics (body fat, measurements), Apple Health import.
- Feet+inches or stones as input formats; imperial height is total inches.
- A `PATCH` partial update.
- Including the profile in `/api/v1/auth/me`.
- Requiring a verified email.

## Data model

### Tables

```php
// database/migrations/2026_10_11_xxxxxx_create_profiles_table.php
Schema::create('profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->date('birth_date');
    $table->string('sex');                       // Sex enum
    $table->decimal('height_cm', 5, 1);
    $table->string('activity_level');            // ActivityLevel enum
    $table->string('unit_system');               // UnitSystem enum
    $table->timestamps();
});

// database/migrations/2026_10_11_xxxxxx_create_weight_entries_table.php
Schema::create('weight_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->decimal('weight_kg', 5, 2);
    $table->timestamp('recorded_at');
    $table->timestamps();
    $table->index(['user_id', 'recorded_at']);
});

// database/migrations/2026_10_11_xxxxxx_create_target_weight_entries_table.php
Schema::create('target_weight_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->decimal('target_weight_kg', 5, 2);
    $table->timestamp('recorded_at');
    $table->timestamps();
    $table->index(['user_id', 'recorded_at']);
});
```

- The current weight is the latest `weight_entries` row; the current target weight is the latest `target_weight_entries` row. `profiles` has no weight columns, so there is one source of truth for each.
- Both history tables hang off `user_id`, not `profile_id`, so later weight sources (Health metrics) can write to them without a profile.
- Weigh-ins and goal changes are separate tables: they are different events and will be written by different features later.

### Enums (`app/Modules/Profile/Enums`)

```php
enum Sex: string { case Male = 'male'; case Female = 'female'; }

enum ActivityLevel: string {
    case Sedentary = 'sedentary';
    case LightlyActive = 'lightly_active';
    case ModeratelyActive = 'moderately_active';
    case VeryActive = 'very_active';
    case ExtraActive = 'extra_active';
}

enum UnitSystem: string {
    case Metric = 'metric';
    case Imperial = 'imperial';

    // input unit → metric: cm rounded to 1 decimal, kg rounded to 2 decimals
    public function toCm(float $height): float;   // imperial: inches × 2.54
    public function toKg(float $weight): float;   // imperial: lb × 0.45359237
    // metric → this unit, rounded to 1 decimal
    public function fromCm(float $cm): float;
    public function fromKg(float $kg): float;
}
```

### Models (`app/Modules/Profile/Models`)

- `Profile`: `belongsTo(User)`; casts `birth_date` → `date`, `sex`, `activity_level`, `unit_system` → enums, `height_cm` → `decimal:1`. Accessor `age` = `birth_date->age`.
- `WeightEntry`: `belongsTo(User)`; casts `recorded_at` → `datetime`, `weight_kg` → `decimal:2`.
- `TargetWeightEntry`: `belongsTo(User)`; casts `recorded_at` → `datetime`, `target_weight_kg` → `decimal:2`.
- `User` (in `app/Models`): `profile(): HasOne`, `weightEntries(): HasMany`, `targetWeightEntries(): HasMany`, plus `latestWeightEntry()` / `latestTargetWeightEntry()` as `HasOne … ->latestOfMany('recorded_at')`.
- Factories `database/factories/{ProfileFactory,WeightEntryFactory,TargetWeightEntryFactory}.php`, linked from the models with `#[UseFactory]` (the models are outside `App\Models`).

### `PUT /api/v1/profile` request (`UpdateProfileRequest`)

```json
{
  "unit_system": "imperial",
  "birth_date": "1990-04-15",
  "sex": "female",
  "height": 65,
  "weight": 154.3,
  "target_weight": 140,
  "activity_level": "moderately_active"
}
```

| Field | Rule |
|---|---|
| `unit_system` | required, `UnitSystem` value |
| `birth_date` | required, `Y-m-d`, user is 13–120 years old today |
| `sex` | required, `Sex` value |
| `height` | required, numeric; metric 50–272 cm, imperial ≈ 19.7–107.1 in |
| `weight`, `target_weight` | required, numeric, at most 1 decimal; metric 20–500 kg, imperial ≈ 44.1–1102.3 lb |
| `activity_level` | required, `ActivityLevel` value |

Bounds are defined once in metric; the imperial bounds are derived from them with `UnitSystem`. The range check is a closure with the same check and message as `between`, so Scramble does not publish the metric bounds as the schema's `minimum`/`maximum` (they would reject valid imperial values); the ranges for both systems are in each field's description. Each rule has an `@example`.

### Save behaviour

In one DB transaction, with one `$now = now()`:

1. `updateOrCreate` the user's profile (birth date, sex, `height_cm`, activity level, unit system).
2. Create a `WeightEntry` with the converted `weight_kg`, `recorded_at = $now`.
3. Create a `TargetWeightEntry` with the converted `target_weight_kg`, `recorded_at = $now`.

Every successful PUT adds exactly one row to each history table, even if the values did not change. Response: 200 with `ProfileResource`.

### `ProfileResource`

```json
{
  "data": {
    "birth_date": "1990-04-15",
    "age": 36,
    "sex": "female",
    "activity_level": "moderately_active",
    "unit_system": "imperial",
    "height": 65.0,
    "weight": 154.3,
    "target_weight": 140.0,
    "height_cm": 165.1,
    "weight_kg": 69.99,
    "target_weight_kg": 63.5,
    "updated_at": "2026-10-11T09:30:00Z"
  }
}
```

- `weight` / `weight_kg` come from the latest weight entry; `target_weight` / `target_weight_kg` from the latest target weight entry (eager-loaded with `latestOfMany`, no N+1).
- `height` / `weight` / `target_weight` are in `unit_system` units (inches, lb for imperial), converted back from the stored metric values. Numbers are JSON numbers, not strings.
- If a history table has no row for the user (not reachable through this API, but possible later), the matching fields are `null`.

### History resources (lists, `{ "data": [ ... ] }`)

```json
// WeightEntryResource
{ "id": 12, "weight": 154.3, "weight_kg": 69.99, "recorded_at": "2026-10-11T09:30:00Z" }

// TargetWeightEntryResource
{ "id": 7, "target_weight": 140.0, "target_weight_kg": 63.5, "recorded_at": "2026-10-11T09:30:00Z" }
```

`weight` / `target_weight` use the profile's `unit_system` (metric if the user has no profile). Each list is all of the user's entries, newest first (`recorded_at desc, id desc`), not paginated.

### Files

```
app/Modules/Profile/
  ProfileServiceProvider.php
  routes.php
  Enums/{ActivityLevel,Sex,UnitSystem}.php
  Models/{Profile,WeightEntry,TargetWeightEntry}.php
  Http/Controllers/{ProfileController,WeightEntryController,TargetWeightEntryController}.php   // #[Group('Profile')]
  Http/Requests/UpdateProfileRequest.php
  Http/Resources/{ProfileResource,WeightEntryResource,TargetWeightEntryResource}.php
app/Models/User.php                         (relations)
bootstrap/providers.php                     (register provider)
database/migrations/…create_profiles_table.php, …create_weight_entries_table.php, …create_target_weight_entries_table.php
database/factories/{ProfileFactory,WeightEntryFactory,TargetWeightEntryFactory}.php
tests/Unit/Profile/UnitSystemTest.php
tests/Feature/Profile/{ProfileTest,WeightEntryTest,TargetWeightEntryTest}.php
tests/Feature/Docs/OpenApiDocumentTest.php  (Profile operations; tag expected per module)
docs/openapi/v1.json                        (re-export)
```

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`). Branch from `master`.

1. **Enums.** Create `Sex`, `ActivityLevel`, `UnitSystem` with the conversion methods. Add `tests/Unit/Profile/UnitSystemTest.php` (inches↔cm, lb↔kg, metric is identity, 1-decimal rounding). Check: unit tests pass.
2. **Schema and models.** The three migrations, `Profile`, `WeightEntry`, `TargetWeightEntry` models, factories, `User` relations. Check: `php artisan migrate` runs; `composer test` still passes.
3. **Module skeleton.** `ProfileServiceProvider` (prefix `api/v1/profile`, middleware `api` + `auth:sanctum`), empty `routes.php`, registered in `bootstrap/providers.php`. Check: suite passes.
4. **GET /profile.** `ProfileController::show` + `ProfileResource`; 404 via `firstOrFail()` with `@throws ModelNotFoundException`. Tests: 401 without token, 404 without profile, 200 with metric and imperial profiles (factory profile + entries), weight fields come from the latest entries.
5. **PUT /profile.** `UpdateProfileRequest` + `ProfileController::update` with the transaction. Tests: creates profile (200), replaces it on second PUT, imperial input stored as metric and returned in imperial, each PUT adds one weight entry and one target weight entry (also when values are unchanged), a 422 adds none, 422 for each missing field, bad enum, out-of-range metric and imperial values, too young/old birth date, 401.
6. **GET /profile/weight-entries.** `WeightEntryController::index` + `WeightEntryResource`. Tests: newest first, only the user's own entries, `weight` follows `unit_system`, empty list without profile, 401.
7. **GET /profile/target-weight-entries.** `TargetWeightEntryController::index` + `TargetWeightEntryResource`. Same tests as step 6.
8. **Docs.** PHPDoc summaries/descriptions, `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Check by hand that the four operations sit under the `Profile` tag with 200/401/404/422 as applicable. `composer test` passes (drift and envelope tests included).
9. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` clean.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `ProfileServiceProvider` is listed in `bootstrap/providers.php` and the four routes appear in `php artisan route:list` under `api/v1/profile`.
- [ ] All four endpoints return 401 without a bearer token.
- [ ] `GET /api/v1/profile` returns 404 for a user with no profile.
- [ ] The `profiles` table has no `weight_kg` or `target_weight_kg` column.
- [ ] `PUT /api/v1/profile` with a complete metric body returns 200 and `{ data: { ... } }` with every field in the `ProfileResource` example, including a computed `age`.
- [ ] A second PUT replaces the profile values; the user still has exactly one `profiles` row, and `GET /profile` returns the second PUT's weight and target weight.
- [ ] A PUT with `unit_system: imperial`, `height: 65`, `weight: 154.3` stores `height_cm = 165.1` and a weight entry with `weight_kg = 69.99`, and the response returns `height: 65.0`, `weight: 154.3`.
- [ ] An imperial `weight: 180` is stored as `weight_kg = 81.65` and returned as `weight: 180.0`; every weight or target weight with at most 1 decimal comes back exactly as entered, in either unit system.
- [ ] Every successful PUT adds exactly one `weight_entries` row and one `target_weight_entries` row with the same `recorded_at`, including when the values are unchanged; a failed (422) PUT adds none.
- [ ] PUT returns 422 when any field is missing, an enum value is unknown, a value is outside the bounds (checked in both unit systems), or the birth date makes the user younger than 13 or older than 120.
- [ ] `GET /api/v1/profile/weight-entries` and `GET /api/v1/profile/target-weight-entries` each return only the caller's entries, newest first, with values in their `unit_system`, and `{ "data": [] }` when there are none.
- [ ] Deleting a user deletes their profile, weight entries and target weight entries.
- [ ] `docs/openapi/v1.json` is identical to a fresh export and lists the four operations under the `Profile` tag.

## Decisions

- **Yes:** store `birth_date`, return computed `age`. Age never goes stale.
- **No:** raw age integer. Wrong after the next birthday.
- **Yes:** accept metric or imperial through one `unit_system` flag; store metric only. One canonical unit in the database, and the flag doubles as the saved display preference.
- **No:** per-field unit objects or `weight_lb`/`weight_kg` suffixed inputs. More shapes to validate and document.
- **Yes:** responses carry both the user's units and the metric fields. Clients display without converting, and calculations read metric.
- **Yes:** imperial height as total inches. Feet+inches is a client display concern.
- **Yes:** DECIMAL(5,1) for `height_cm`: off by at most 0.05 cm (0.02 in), so any 1-decimal inch value comes back exactly.
- **Yes:** DECIMAL(5,2) for `weight_kg` / `target_weight_kg`, with inputs limited to 1 decimal. Off by at most 0.005 kg (0.011 lb), so any 1-decimal lb or kg value comes back exactly.
- **No:** DECIMAL(5,1) for weights. 0.1 kg ≈ 0.22 lb, so 180 lb → 81.6 kg → 179.9 lb.
- **Yes:** a separate `Profile` module and `profiles` table, 1:1 with users. Keeps `Auth` about identity and leaves room for goals.
- **No:** columns on `users` / profile inside `/me`.
- **Yes:** weight and target weight live only in their history tables; the profile reads the latest entry. One source of truth, no copy to keep in sync.
- **No:** `weight_kg` / `target_weight_kg` columns on `profiles` as a cached copy.
- **Yes:** separate `weight_entries` and `target_weight_entries` tables. Weigh-ins and goal changes are different events; future weigh-in sources won't touch goals.
- **No:** one table with both values per row. Couples the two and forces a target value on every future weigh-in.
- **Yes:** one row in each table on every PUT, even with unchanged values. Each save is a weigh-in and a goal confirmation; repeated values are real data for plateau detection.
- **No:** entry only on change.
- **Yes:** history tables keyed by `user_id`. Future sources don't need a profile.
- **Yes:** history lists unpaginated for now. Pagination joins SPEC 04's convention when entries grow.
- **Yes:** `PUT` full replace, all fields required. A profile is useless to the calorie spec if half empty.
- **No:** `PATCH` partial updates (deferred).
- **Yes:** 5 activity levels matching the standard TDEE multipliers (1.2 → 1.9).
- **Yes:** `sex` (`male`/`female`) added now, at the user's request, so the profile is complete for the calorie-targets spec.
- **Yes:** any authenticated user, verified email not required. Same as `/me`.
- **Yes:** PUT always returns 200 (create or replace), as an idempotent upsert of the profile. The controller returns `$profile->fresh()`, because Laravel answers 201 for a resource wrapping a model created in the same request.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Round-trip rounding: storing kg with 1 decimal turns 180 lb into 179.9 lb. | Weights stored with 2 decimals and inputs limited to 1 decimal; a unit test checks every 0.1 lb from 44.1 to 1102.3 and every 0.1 kg from 20 to 500 come back exactly. |
| Scramble infers decimal casts as strings. | Resources cast to `float`; step 8 checks the exported types and adds `@var float` / `@example` if needed. |
| Two entries saved in the same second make "latest" ambiguous. | `latestOfMany` and the list order break ties by `id`. |
| `sex` as a binary field may not fit every user. | It is only an input to calorie formulas; revisit in the calorie-targets spec. |
| History grows unbounded with many PUTs. | One row per save is small; pagination is a follow-up spec. |

## What is **not** in this spec

- Calorie or macro target calculation.
- Fitness objectives and goal dates.
- Standalone weight or target weight logging, editing, deleting, back-dating or paginating entries.
- Other body metrics or Apple Health import.
- `PATCH`, feet+inches input, profile in `/me`, verified-email requirement.

Each one of those, if it lands, goes in its own spec.
