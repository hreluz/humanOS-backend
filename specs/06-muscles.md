# SPEC 06 — Muscle catalog

> **Status:** Approved
> **Depends on:** none
> **Date:** 2026-10-25
> **Objective:** Store the muscle catalog that the frontend's Body section draws (6 areas, 29 muscles) in two database tables, filled by an idempotent seeder, so later features can reference muscles by foreign key.

## Why this spec exists

The frontend hardcodes the muscle list in `humanOS-frontend/app/utils/muscles.ts` (`MUSCLE_LABELS`, `MUSCLE_AREAS`) and `app/types/training.ts` (`MuscleKey`, `MuscleArea`). Exercises, workout logging (README §3) and the recommendation engine all need to point at muscles, so the catalog belongs in the database, where they can hold foreign keys to it. This spec only moves the catalog. Exercises and the API come in later specs that build on these tables.

A review of the frontend mapping found it sound: every region of the body figure is mapped, and every muscle is drawn on at least one view. One fix concerns the catalog itself and is applied here: the forearm extensor label (see "Seed data"). The other fixes concern exercise tags and go into the exercises spec.

## Scope

**In:**

- A new module folder `app/Modules/Training` with `Enums/` and `Models/`.
- `muscle_areas` and `muscles` tables.
- Enum `BodyView` (`front` | `back`).
- Models `MuscleArea` and `Muscle`.
- `database/seeders/MuscleSeeder.php` that upserts the 6 areas and 29 muscles by `key`, called from `DatabaseSeeder`.
- Feature tests for the schema and the seeder.

**Out of scope (for future specs):**

- Exercises, exercise photos and the exercise↔muscle tags (primary/secondary). The review's exercise fixes go there:
  - Dumbbell Fly targets both chest heads (`upper_chest` and `lower_chest`), like Cable Fly.
  - Incline Barbell Press and Incline Dumbbell Press list both triceps heads as secondary, like the flat presses.
  - Hammer Curl, Cable Reverse Curl and Reverse Dumbbell Curl get `forearm_flexors` as a secondary, so they show up when the front forearm is tapped.
  - Exercises are added so every muscle and equipment pair has at least one exercise that targets it. Today these pairs have none: bodyweight for side delts, rear delts, upper traps, long-head triceps and forearm extensors; dumbbell for hip flexors; gym for lower traps.
- API endpoints (`GET` muscles, `GET` exercises) and a `TrainingServiceProvider`. The provider is added with the first route.
- The body-muscles SVG region mapping (`LIBRARY_TO_MUSCLE`, `NEUTRAL_REGION_IDS`). It describes the frontend's artwork, so it stays in the frontend.
- Muscles the figure has no region for: brachialis, rhomboids, teres major, rotator cuff, neck.
- Translations of labels.

## Data model

### Tables

```php
// database/migrations/2026_10_25_xxxxxx_create_muscle_areas_table.php
Schema::create('muscle_areas', function (Blueprint $table) {
    $table->id();
    $table->string('key', 32)->unique();          // chest, shoulders, arms, core, back, legs
    $table->string('label');
    $table->unsignedSmallInteger('sort_order');
    $table->timestamps();
});

// database/migrations/2026_10_25_xxxxxx_create_muscles_table.php
Schema::create('muscles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('muscle_area_id')->constrained()->restrictOnDelete();
    $table->string('key', 32)->unique();          // upper_chest, triceps_long, ...
    $table->string('label');
    $table->string('body_view');                  // BodyView enum
    $table->unsignedSmallInteger('sort_order');   // order within the area
    $table->timestamps();
});
```

- `key` is the stable identifier clients and future specs use. `id` is for foreign keys.
- `body_view` is the side of the figure the muscle is drawn on. A muscle drawn on both sides gets `front`, the same rule as the frontend's `muscleView()`. Only `forearm_flexors` is drawn on both.
- `restrictOnDelete`: an area that still has muscles can't be deleted.

### Enum (`app/Modules/Training/Enums`)

```php
enum BodyView: string { case Front = 'front'; case Back = 'back'; }
```

### Models (`app/Modules/Training/Models`)

- `MuscleArea`: `muscles(): HasMany`, ordered by `sort_order`.
- `Muscle`: `area(): BelongsTo` (`muscle_area_id`); casts `body_view` to `BodyView`.
- No factories: the seeded catalog is the only data, and tests run the seeder.

### Seed data

Areas, in this order (`sort_order` 1–6):

| `key` | `label` |
|---|---|
| `chest` | Chest |
| `shoulders` | Shoulders |
| `arms` | Arms |
| `core` | Core |
| `back` | Back |
| `legs` | Legs |

Muscles, in this order within each area (`sort_order` starts at 1 in each area):

| Area | `key` | `label` | `body_view` | Frontend key |
|---|---|---|---|---|
| chest | `upper_chest` | Upper chest | front | `upperChest` |
| chest | `lower_chest` | Mid & lower chest | front | `lowerChest` |
| chest | `serratus` | Serratus | front | `serratus` |
| shoulders | `front_delts` | Front delts | front | `frontDelts` |
| shoulders | `side_delts` | Side delts | front | `sideDelts` |
| shoulders | `rear_delts` | Rear delts | back | `rearDelts` |
| arms | `biceps` | Biceps | front | `biceps` |
| arms | `triceps_long` | Triceps (long head) | back | `tricepsLong` |
| arms | `triceps_lateral` | Triceps (lateral head) | back | `tricepsLateral` |
| arms | `forearm_flexors` | Forearm flexors | front | `forearmFlexors` |
| arms | `forearm_extensors` | Brachioradialis & forearm extensors | back | `forearmExtensors` |
| core | `upper_abs` | Upper abs | front | `upperAbs` |
| core | `lower_abs` | Lower abs | front | `lowerAbs` |
| core | `obliques` | Obliques | front | `obliques` |
| back | `upper_traps` | Upper traps | back | `upperTraps` |
| back | `mid_traps` | Mid traps | back | `midTraps` |
| back | `lower_traps` | Lower traps | back | `lowerTraps` |
| back | `lats` | Lats | back | `lats` |
| back | `lower_back` | Lower back | back | `lowerBack` |
| legs | `glute_max` | Glute max | back | `gluteMax` |
| legs | `glute_med` | Glute med | back | `gluteMed` |
| legs | `hip_flexors` | Hip flexors | front | `hipFlexors` |
| legs | `adductors` | Adductors | front | `adductors` |
| legs | `quads` | Quads | front | `quads` |
| legs | `hamstrings_inner` | Inner hamstrings | back | `hamstringsInner` |
| legs | `hamstrings_outer` | Outer hamstrings | back | `hamstringsOuter` |
| legs | `gastrocnemius` | Calves (gastrocnemius) | back | `gastrocnemius` |
| legs | `soleus` | Soleus | back | `soleus` |
| legs | `tibialis` | Tibialis | front | `tibialis` |

**Fix from the review:** the frontend labels `forearmExtensors` "Forearm extensors". Hammer and reverse curls mainly train the brachioradialis, which belongs to the extensor group, and the exercises spec tags them with this muscle. The label now names it. Frontend follow-up: the front-view `forearm` region (where the brachioradialis is drawn) maps only to `forearmFlexors`. That is handled in the exercises spec by tagging those curls with `forearm_flexors` as a secondary, and the frontend should take this label when it reads the catalog from the API.

### Seeder behaviour

`MuscleSeeder` holds the two tables above as PHP arrays. In one transaction it:

1. Upserts each area by `key` (`label`, `sort_order`).
2. Upserts each muscle by `key` (`muscle_area_id` resolved from the area key, `label`, `body_view`, `sort_order`).

- Running it again updates labels, order, view and area in place. Ids don't change.
- It never deletes rows. Removing a muscle would break foreign keys that future tables hold, so a removal gets its own migration.
- `DatabaseSeeder::run()` calls it. On a deployed server, run `php artisan db:seed --class=MuscleSeeder --force` after migrating.

### Files

```
app/Modules/Training/
  Enums/BodyView.php
  Models/{MuscleArea,Muscle}.php
database/migrations/…create_muscle_areas_table.php, …create_muscles_table.php
database/seeders/MuscleSeeder.php
database/seeders/DatabaseSeeder.php        (call MuscleSeeder)
tests/Feature/Training/MuscleSeederTest.php
```

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`). Branch from `master`.

1. **Enum.** Create `BodyView`. Check: `composer test` passes.
2. **Schema and models.** The two migrations and the `MuscleArea` and `Muscle` models. Check: `php artisan migrate` runs, and `migrate:rollback` drops both tables.
3. **Seeder.** `MuscleSeeder` with the seed data above, called from `DatabaseSeeder`. Check: `php artisan db:seed --class=MuscleSeeder` gives 6 areas and 29 muscles; running it twice gives the same.
4. **Tests.** `tests/Feature/Training/MuscleSeederTest.php` (with `RefreshDatabase`, seeding in each test):
   - 6 areas and 29 muscles, keys and order match the tables above;
   - every muscle has a `BodyView` and belongs to the expected area;
   - seeding twice keeps the counts and ids;
   - after a row's label is changed in the database, seeding again restores the seed label;
   - deleting an area that has muscles throws a `QueryException`.
5. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` creates `muscle_areas` and `muscles`, and rolling back drops them.
- [ ] After `php artisan db:seed --class=MuscleSeeder`, the database holds exactly the 6 areas and 29 muscles in the seed tables, with the same keys, labels, views and order.
- [ ] `forearm_extensors` is labelled "Brachioradialis & forearm extensors".
- [ ] Running the seeder a second time changes no ids and adds no rows.
- [ ] `php artisan db:seed` (the `DatabaseSeeder`) also seeds the catalog.
- [ ] Deleting a `muscle_areas` row that has muscles fails.
- [ ] `docs/openapi/v1.json` is unchanged (no API change).

## Decisions

- **Yes:** tables for the catalog. Exercises and workout logs will hold foreign keys to muscles.
- **No:** a PHP enum for muscles. You can't put a foreign key to it, and areas, labels and order would all live in code.
- **Yes:** `key` in snake_case (`upper_chest`). Matches the repo's enum values (`lightly_active`). The frontend maps its camelCase keys when it moves to the API.
- **Yes:** areas get their own table. They have a label and an order, and the frontend groups muscles by them.
- **Yes:** `body_view` stored on the muscle. Clients need it to turn the figure around when a muscle is picked from the list.
- **No:** the body-muscles region ids in the database. They describe one SVG library's artwork, not anatomy.
- **Yes:** data loaded by an idempotent upsert seeder. Seeding can be repeated, and data fixes ship by editing the seeder.
- **No:** inserting the data inside the migration. A fixed label would need a new migration each time.
- **No:** a service provider yet. The module has no routes; the provider comes with the first endpoint.
- **Yes:** the review's catalog fix (forearm extensor label) in this spec; its exercise fixes in the exercises spec.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The seeder isn't run on a deployed server, so the tables are empty. | The command is documented here; the API spec that reads these tables should fail clearly (or its tests check it) when the catalog is empty. |
| Frontend and backend catalogs drift until the frontend reads the API. | The table above lists the frontend key next to each row; the API spec makes the backend the source and removes the frontend constants. |
| A key is renamed in the seeder and a new row appears instead of an update. | Keys are treated as permanent; a rename gets a migration that updates the row in place. |

## What is **not** in this spec

- Exercises, photos and exercise↔muscle tags (with the review's exercise fixes).
- API endpoints and the module's service provider.
- The SVG region mapping, extra muscles without a figure region, label translations.

Each one of those, if it lands, goes in its own spec.
