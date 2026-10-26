# SPEC 08 — Exercise catalog with muscle tags and photos

> **Status:** Approved
> **Depends on:** SPEC 06, SPEC 07
> **Date:** 2026-10-25
> **Objective:** Store the frontend's 138 curated exercises, plus new ones so that every muscle has an exercise for gym, dumbbell and bodyweight, in database tables. Each exercise has its exact equipment, its primary and secondary muscle tags and an ordered list of photo references (free-exercise-db first, wger for gaps), and is loaded from a committed data file by an idempotent seeder.

## Why this spec exists

The frontend hardcodes its exercise library in `humanOS-frontend/app/services/mocks/exercises.ts`: 138 exercises, each with one of three equipment groups, fine-grained muscle tags and a list of free-exercise-db photo ids (its own first, then close variants). About 31 of them have no photo. SPEC 06 and SPEC 07 put muscles and equipment in the database. This spec adds the exercises that point to them, so later specs (the read API, workout logging, recommendations) can reference exercises by foreign key.

The work is split in two:

- **SPEC 08 (this one):** the tables, the tagging rules, the curated list ported from the frontend, the review fixes from SPEC 06, and new exercises that fill the coverage gaps.
- **SPEC 09 (later):** the rest of free-exercise-db (~870 exercises in all categories), tagged with the same rules and the same data file format.

Photo sources: [free-exercise-db](https://github.com/yuhonas/free-exercise-db) (Unlicense, public domain, 873 exercises with two JPGs each) is the main source. [wger](https://wger.de) (images under CC-BY-SA or CC0, credit required) is used only for exercises free-exercise-db doesn't have. ExerciseDB/AscendAPI was left out (see "Decisions").

## Scope

**In:**

- Tables `exercises`, `exercise_muscle` (pivot with role and order) and `exercise_photos`, in the `Training` module.
- Enums `ExerciseCategory`, `ExerciseLevel`, `ExerciseMechanic`, `ExerciseForce`, `MuscleRole`, `PhotoSource`.
- Models `Exercise` and `ExercisePhoto`.
- `docs/exercise-tagging.md`: the rules for assigning muscles, equipment and photos. SPEC 09 reuses them.
- `database/data/exercises.json`: the curated catalog, which is the source of truth for the seeder.
- Porting the frontend's 138 exercises. Each one gets its exact equipment (barbell, machine, cable, EZ bar, dumbbell, body only), not just its group, and its muscle keys are converted to snake_case.
- The three exercise fixes from SPEC 06's review (see "Tagging rules").
- New exercises until every muscle has at least one exercise that targets it (`primary`) in each of the `gym`, `dumbbell` and `bodyweight` groups (29 × 3 = 87 pairs).
- Photos for exercises that have none today: a free-exercise-db match first, then wger. If neither has one, the exercise stays without photos and is listed in the PR.
- Photo URLs built from a configurable base per source (`config/services.php`). Nothing is downloaded.
- `database/seeders/ExerciseSeeder.php`: upserts exercises by `key` and rebuilds their tags and photos. Called from `DatabaseSeeder`.
- `TrainingServiceProvider`, registering one command: `exercises:check-photos`. It sends a HEAD request to every photo URL and reports failures.
- Feature tests for the data file, coverage, the seeder, photo URLs and the command.

**Out of scope (for future specs):**

- The rest of free-exercise-db (~740 exercises, including stretching, cardio, plyometrics, olympic, strongman): **SPEC 09**.
- Coverage for the `kettlebell`, `bands` and `accessories` groups: SPEC 09.
- API endpoints (`GET` muscles, equipment and exercises) and the frontend moving off its mocks.
- Downloading or mirroring images to our own storage (S3).
- ExerciseDB/AscendAPI GIFs.
- Exercises that need more than one piece of equipment (still out, as in SPEC 07).
- User-created exercises, translations of names, cues and instructions.

## Data model

### Enums (`app/Modules/Training/Enums`)

```php
enum ExerciseCategory: string {
    case Strength = 'strength'; case Powerlifting = 'powerlifting';
    case OlympicWeightlifting = 'olympic_weightlifting'; case Strongman = 'strongman';
    case Plyometrics = 'plyometrics'; case Cardio = 'cardio'; case Stretching = 'stretching';
}
enum ExerciseLevel: string { case Beginner = 'beginner'; case Intermediate = 'intermediate'; case Expert = 'expert'; }
enum ExerciseMechanic: string { case Compound = 'compound'; case Isolation = 'isolation'; }
enum ExerciseForce: string { case Push = 'push'; case Pull = 'pull'; case Static = 'static'; }
enum MuscleRole: string { case Primary = 'primary'; case Secondary = 'secondary'; }
enum PhotoSource: string { case FreeExerciseDb = 'free_exercise_db'; case Wger = 'wger'; }
```

The category values are free-exercise-db's, in snake_case. All seven are defined now, so SPEC 09 needs no enum change.

### Tables

```php
// database/migrations/2026_10_25_000004_create_exercises_table.php
Schema::create('exercises', function (Blueprint $table) {
    $table->id();
    $table->string('key', 64)->unique();             // barbell_bench_press, push_up, ...
    $table->string('name');
    $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
    $table->string('category');                      // ExerciseCategory
    $table->string('level')->nullable();             // ExerciseLevel
    $table->string('mechanic')->nullable();          // ExerciseMechanic
    $table->string('force')->nullable();             // ExerciseForce
    $table->string('cue')->nullable();               // one-line coaching cue
    $table->json('instructions')->nullable();        // list of steps
    $table->string('source_id', 128)->nullable()->unique(); // free-exercise-db `id`
    $table->timestamps();
});

// database/migrations/2026_10_25_000005_create_exercise_muscle_table.php
Schema::create('exercise_muscle', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
    $table->foreignId('muscle_id')->constrained()->restrictOnDelete();
    $table->string('role');                          // MuscleRole
    $table->unsignedSmallInteger('sort_order');      // order within the role; most direct first
    $table->unique(['exercise_id', 'muscle_id']);
    $table->timestamps();
});

// database/migrations/2026_10_25_000006_create_exercise_photos_table.php
Schema::create('exercise_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
    $table->string('source');                        // PhotoSource
    $table->string('path');                          // relative to the source's base URL
    $table->string('caption');
    $table->unsignedSmallInteger('position');        // 1-based display order
    $table->string('license', 32);                   // Unlicense, CC-BY-SA 4.0, CC0 1.0, ...
    $table->string('author')->nullable();            // required for wger
    $table->string('credit_url')->nullable();        // page to link for credit (wger)
    $table->unique(['exercise_id', 'position']);
    $table->timestamps();
});
```

- `key` is the stable identifier, in snake_case and derived from the name (`Push-up` → `push_up`). `id` is for foreign keys.
- `equipment_id` points to the exact piece of equipment (SPEC 07). The group comes through `equipment.equipment_group_id`.
- `source_id` is the free-exercise-db id of the exercise itself, or null when there is none. It is unique, so SPEC 09 can skip exercises this spec already holds.
- A muscle appears at most once per exercise, so it can't be both primary and secondary.
- `restrictOnDelete` on `muscle_id` and `equipment_id`: you can't delete a muscle or piece of equipment that an exercise uses. Tags and photos are deleted with their exercise.

### Models (`app/Modules/Training/Models`)

- `Exercise`: `equipment(): BelongsTo`; `muscles(): BelongsToMany` (`withPivot('role', 'sort_order')`, `withTimestamps()`); `primaryMuscles()` and `secondaryMuscles()` (the same relation filtered by role and ordered by `sort_order`); `photos(): HasMany`, ordered by `position`. Casts: `category`, `level`, `mechanic` and `force` to their enums, `instructions` to `array`.
- `ExercisePhoto`: `exercise(): BelongsTo`; casts `source` to `PhotoSource`; a `url` accessor that returns `rtrim(config("services.exercise_photos.{source}"), '/').'/'.$path`.
- `Muscle` gets `exercises(): BelongsToMany` (with the pivot) and `Equipment` gets `exercises(): HasMany`.
- No factories: the seeded catalog is the only data.

### Photo URL config

```php
// config/services.php
'exercise_photos' => [
    'free_exercise_db' => env('EXERCISE_PHOTOS_FREE_EXERCISE_DB_URL',
        'https://raw.githubusercontent.com/yuhonas/free-exercise-db/f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5/exercises'),
    'wger' => env('EXERCISE_PHOTOS_WGER_URL', 'https://wger.de/media'),
],
```

The free-exercise-db base is pinned to a commit (the latest on `main`, 2026-09-27), so a rename upstream can't break our paths. Both variables go into `.env.example` and `.env.development`. A move to S3 later means copying the files and changing these two values.

### Data file (`database/data/exercises.json`)

A JSON array, one object per exercise, sorted by muscle area and then by name. Muscles and equipment use the keys from SPEC 06 and SPEC 07.

```json
{
  "key": "barbell_bench_press",
  "name": "Barbell Bench Press",
  "equipment": "barbell",
  "category": "strength",
  "level": "beginner",
  "mechanic": "compound",
  "force": "push",
  "cue": "Shoulder blades pinned back, bar to mid-chest.",
  "instructions": ["Lie back on a flat bench...", "..."],
  "source_id": "Barbell_Bench_Press_-_Medium_Grip",
  "primary": ["lower_chest", "upper_chest"],
  "secondary": ["front_delts", "triceps_lateral", "triceps_long"],
  "photos": [
    { "source": "free_exercise_db", "id": "Barbell_Bench_Press_-_Medium_Grip" },
    { "source": "free_exercise_db", "id": "Smith_Machine_Bench_Press" },
    { "source": "wger", "path": "exercise-images/91/Crunches-1.png", "caption": "Start",
      "license": "CC-BY-SA 4.0", "author": "…", "credit_url": "https://wger.de/en/exercise/91/view/" }
  ]
}
```

- `level`, `mechanic`, `force` and `instructions` are copied from free-exercise-db when `source_id` is set. Otherwise `level` and `mechanic` are set by hand, and `force` and `instructions` may be null.
- `cue` comes from the frontend. New exercises get a cue written in the same style.
- A `free_exercise_db` photo entry expands to two rows: `<id>/0.jpg` and `<id>/1.jpg`. If `<id>` is the exercise's own `source_id`, the captions are `Start` and `End`. Otherwise they are `<label> · start` and `<label> · end`, where the label comes from the id as in the frontend's `photoIdLabel` (`Smith_Machine_Bench_Press` → `Smith Machine Bench Press`). The license is `Unlicense`.
- A `wger` photo entry is one row and states its own caption, license, author and credit URL. Only images licensed `CC-BY-SA` or `CC0` are allowed.
- `position` follows the file order: 1, 2, 3, …

### Tagging rules (`docs/exercise-tagging.md`)

The doc holds these rules. The data file must follow them.

1. **Primary** muscles do most of the work. **Secondary** muscles assist. Every exercise has at least one primary. List the primaries with the most direct one first: the frontend sorts by the muscle's position in `primary`.
2. **Chest:** incline work → `upper_chest`; flat work → `upper_chest` and `lower_chest`; decline work and dips → `lower_chest`.
3. **Pressing:** flat and incline presses list both triceps heads (`triceps_lateral` and `triceps_long`) as secondary. *(SPEC 06 fix: Incline Barbell Press and Incline Dumbbell Press.)*
4. **Flyes:** flat flyes target both chest heads. *(SPEC 06 fix: Dumbbell Fly gets `upper_chest` and `lower_chest` as primary, like Cable Fly.)*
5. **Neutral- and reverse-grip curls:** `biceps` and `forearm_extensors` (brachioradialis) as primary, `forearm_flexors` as secondary. *(SPEC 06 fix: Hammer Curl, Cable Reverse Curl and Reverse Dumbbell Curl.)*
6. **Hamstrings:** tag both heads (`hamstrings_inner` and `hamstrings_outer`). Use only one if the exercise biases a head on purpose (toes in or out), and say so in the cue.
7. **Equipment:** the exact piece the exercise needs, with no fallback to a group: Smith machine and leverage machines → `machine`; EZ bar → `ez_bar`; bodyweight → `body_only`. If an exercise also needs a bench or a bar to hang from, it still gets only its main load (multiple equipment is out of scope).
8. **Photos:** its own free-exercise-db photos first. Then close variants that show the same movement pattern, at most three entries in all. wger only when free-exercise-db has nothing that matches.
9. A muscle that is neither primary nor secondary is not tagged. Don't tag stabilisers.

### Coverage gaps to fill

SPEC 06 listed these pairs with no targeting exercise. They are the starting point. The coverage test is the final word.

| Muscle | Group | Candidates (final pick during implementation) |
|---|---|---|
| `side_delts` | bodyweight | Side-lying lateral raise (bodyweight), wall lateral raise hold |
| `rear_delts` | bodyweight | Prone reverse snow angel, wide-grip inverted row to neck |
| `upper_traps` | bodyweight | `Scapular_Pull-Up`, bar dip shrug |
| `triceps_long` | bodyweight | `Body_Tricep_Press`, bench overhead triceps extension |
| `forearm_extensors` | bodyweight | Reverse-grip bar hang, towel reverse-grip hold |
| `hip_flexors` | dumbbell | Seated dumbbell knee raise, lying dumbbell leg raise |
| `lower_traps` | gym | Cable Y raise, prone incline Y raise on a machine bench |

The data must be accurate, so each new exercise is a recognised exercise. Use the free-exercise-db version when one exists.

### Seeder behaviour

`ExerciseSeeder` reads `database/data/exercises.json`. It checks the whole file before writing, and if the file references an unknown muscle or equipment key, it throws and writes nothing. Then, in one transaction, for each exercise it:

1. Upserts the exercise by `key` (all columns, with `equipment_id` taken from the equipment key).
2. Deletes its `exercise_muscle` rows and inserts them again from `primary` and `secondary` (`sort_order` from 1 within each role).
3. Deletes its `exercise_photos` rows and inserts them again from `photos`.

- Running it again updates exercises in place. Exercise ids don't change, and tag and photo ids are not stable (nothing references them).
- It never deletes exercises. Future workout logs will hold foreign keys to them, so a removal gets its own migration.
- The JSON file is not runtime data. Only the seeder and `ExerciseCatalogTest` read it; the app (and the future API) reads the database. Catalog changes are made in the file and reseeded, and a direct database edit is overwritten on the next seed. If exercises ever become editable in the app (admins or user-created), the database becomes the source of truth and the file a one-time import; that decision belongs to that spec.
- `DatabaseSeeder::run()` calls it after `MuscleSeeder` and `EquipmentSeeder`. On a deployed server, run `php artisan db:seed --class=ExerciseSeeder --force` after migrating.

### Command

`php artisan exercises:check-photos` (in `app/Modules/Training/Console/CheckExercisePhotosCommand.php`) sends a HEAD request to each `ExercisePhoto::url`, at most 10 at a time (`Http::pool`). It prints the exercise key and URL of every response that isn't 2xx. It exits with `0` when all photos resolve and `1` otherwise. The command needs the network, so it runs by hand (and before merging this spec). The test suite only runs it against `Http::fake()`.

`TrainingServiceProvider` (in `app/Modules/Training`, registered in `bootstrap/providers.php`) registers this command and nothing else for now. Routes come with the API spec.

### Files

```
app/Modules/Training/
  TrainingServiceProvider.php
  Console/CheckExercisePhotosCommand.php
  Enums/{ExerciseCategory,ExerciseLevel,ExerciseMechanic,ExerciseForce,MuscleRole,PhotoSource}.php
  Models/{Exercise,ExercisePhoto}.php
  Models/{Muscle,Equipment}.php                (add exercises() relation)
bootstrap/providers.php                         (register TrainingServiceProvider)
config/services.php                             (exercise_photos)
.env.example, .env.development                  (EXERCISE_PHOTOS_* variables)
database/migrations/2026_10_25_000004_create_exercises_table.php
database/migrations/2026_10_25_000005_create_exercise_muscle_table.php
database/migrations/2026_10_25_000006_create_exercise_photos_table.php
database/data/exercises.json
database/seeders/ExerciseSeeder.php
database/seeders/DatabaseSeeder.php             (call ExerciseSeeder)
docs/exercise-tagging.md
tests/Feature/Training/ExerciseCatalogTest.php
tests/Feature/Training/ExerciseSeederTest.php
tests/Feature/Training/CheckExercisePhotosCommandTest.php
```

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`). Branch from `master` (after SPEC 07 is merged).

1. **Enums.** The six enums. Check: `composer test` passes.
2. **Schema and models.** The three migrations, the `Exercise` and `ExercisePhoto` models, and the new relations on `Muscle` and `Equipment`. Check: `php artisan migrate` runs, and `migrate:rollback` drops the three tables.
3. **Photo config.** `services.exercise_photos` and the two env variables in `.env.example` and `.env.development`. Check: `ExercisePhoto::url` builds the pinned free-exercise-db URL in tinker.
4. **Tagging rules.** Write `docs/exercise-tagging.md` with the rules above.
5. **Seeder with a minimal file.** `ExerciseSeeder` with validation, and `database/data/exercises.json` with three exercises (one per group), called from `DatabaseSeeder`. Check: seeding twice gives the same counts and ids.
6. **Port the curated list.** Convert all 138 frontend exercises into the data file: snake_case keys, exact equipment, `source_id` and free-exercise-db metadata and instructions (a one-off script you don't commit can read free-exercise-db's `dist/exercises.json` at the pinned commit), and the three SPEC 06 fixes. Check: the seeder loads 138 exercises.
7. **Fill missing photos.** For each exercise without photos, look for a free-exercise-db match, then a wger image (CC-BY-SA or CC0, with credit). Check: `php artisan exercises:check-photos` exits with `0`.
8. **Fill coverage gaps.** Add exercises until the coverage test (step 9) passes, starting with the table above. Check: the coverage test passes.
9. **Tests.**
   - `ExerciseCatalogTest` (reads the JSON file and the seeded muscles and equipment):
     - keys are unique and snake_case; `source_id`s are unique;
     - every equipment, muscle, category, level, mechanic and force value is valid;
     - every exercise has at least one primary, and no muscle is listed twice;
     - every wger photo has a license of `CC-BY-SA …` or `CC0 …`, an author and a `credit_url`;
     - **coverage:** each of the 29 muscles has at least one exercise that lists it as primary in each of the `gym`, `dumbbell` and `bodyweight` groups, except the exceptions documented in `docs/exercise-tagging.md`;
     - **SPEC 06 fixes:** `dumbbell_fly` has `upper_chest` and `lower_chest` as primary; `incline_barbell_press` and `incline_dumbbell_press` have both triceps heads as secondary; `hammer_curl`, `cable_reverse_curl` and `reverse_dumbbell_curl` have `forearm_flexors` as secondary.
   - `ExerciseSeederTest` (`RefreshDatabase`, seeding in each test):
     - the exercise count matches the file;
     - the tags, their order and the photo rows of `barbell_bench_press` match the file, including the expanded captions;
     - seeding twice keeps the exercise count and ids;
     - after a name is changed and a tag removed in the database, seeding again restores both;
     - an unknown muscle key in the file throws and writes nothing (use a temporary file);
     - deleting a muscle that an exercise uses throws a `QueryException`; deleting an exercise deletes its tags and photos.
     - `ExercisePhoto::url` follows `config('services.exercise_photos.*')`.
   - `CheckExercisePhotosCommandTest`: with `Http::fake()` returning 200 for all URLs, the command exits with `0`; with one 404, it exits with `1` and prints that URL.
10. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues. The PR description lists the exercises that are still without photos and the exercises added in step 8.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` creates `exercises`, `exercise_muscle` and `exercise_photos`, and rolling back drops them.
- [ ] `php artisan db:seed` seeds muscles, equipment and exercises. The exercise count equals the number of entries in `database/data/exercises.json` and is at least 138.
- [ ] Every one of the frontend's 138 exercises is in the data file, under its snake_case name.
- [ ] Every muscle × `gym`/`dumbbell`/`bodyweight` pair has at least one exercise with that muscle as primary, except the documented exceptions in `docs/exercise-tagging.md` (today only `forearm_extensors` × bodyweight). Enforced by the coverage test.
- [ ] The three SPEC 06 exercise fixes are in the data and covered by tests.
- [ ] Running `ExerciseSeeder` a second time changes no exercise ids and adds no exercises.
- [ ] Every exercise references exact equipment (no group-level equipment).
- [ ] `php artisan exercises:check-photos` exits with `0` against the real sources.
- [ ] Every wger photo has a CC-BY-SA or CC0 license, an author and a credit URL.
- [ ] `docs/exercise-tagging.md` exists and holds the tagging rules.
- [ ] `EXERCISE_PHOTOS_FREE_EXERCISE_DB_URL` and `EXERCISE_PHOTOS_WGER_URL` are in both `.env.example` and `.env.development`.
- [ ] `docs/openapi/v1.json` is unchanged (no API change).

## Decisions

- **Yes:** free-exercise-db as the main photo source. It is public domain, needs no API key and has two photos for almost every exercise, and the frontend already uses it.
- **Yes:** wger for gaps, with license, author and credit URL stored. Its CC-BY-SA images require credit, and storing it lets clients show it.
- **No:** ExerciseDB/AscendAPI GIFs. They sit behind RapidAPI with a paid key, and the license limits storing and redistributing the media.
- **Yes:** store photo references (source + path) and build URLs from config. Nothing to download now, and a later move to S3 means copying the files and changing two env values.
- **Yes:** pin free-exercise-db to a commit in the default base URL. A rename on `main` can't break photos without notice.
- **No:** mirroring images to our own storage now. Deferred to a future spec.
- **Yes:** split the catalog into SPEC 08 (curated list and gaps) and SPEC 09 (the rest of free-exercise-db). Tagging ~870 exercises accurately is too much to review in one PR.
- **Yes:** a committed JSON data file with explicit per-exercise tags, written to rules in `docs/exercise-tagging.md`. Tags stay accurate and reviewable in diffs, and the seeder works offline.
- **No:** keyword rules at import time (e.g. "incline" → `upper_chest`). Too many edge cases for fine-grained muscles.
- **Yes:** a pivot with `role` and `sort_order`. The frontend orders results by the muscle's position in `primary`, so the order is data.
- **Yes:** exact equipment per exercise (SPEC 07's finer list), not the group. Groups are only for filtering.
- **Yes:** an ordered photo list that includes variant photos with captions. This keeps the frontend's current behaviour.
- **Yes:** all seven categories defined in the enum now. SPEC 09 needs no migration or enum change.
- **Yes:** the full metadata set (level, mechanic, force, instructions). Level and mechanic feed the recommendation engine later.
- **Yes:** coverage required for gym, dumbbell and bodyweight only. Those are the frontend's groups today; the other three fill in with SPEC 09.
- **Yes:** the seeder rebuilds tags and photos for each exercise. Nothing references their ids, and rebuilding is simpler than diffing.
- **Yes:** `TrainingServiceProvider` now, though SPEC 06 planned it for the first route. A module command has to be registered somewhere, and this follows `AuthServiceProvider`.
- **Yes:** the photo check is a command run by hand, not a test. Tests stay offline and deterministic.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| wger moves or removes an image. | `exercises:check-photos` lists broken URLs. wger is used only for gaps, so few exercises are affected. |
| GitHub raw throttles or blocks hotlinking at scale. | URLs come from config. Mirroring to S3 is a copy job plus an env change (future spec). |
| Wrong tags (e.g. a muscle marked primary that only stabilises). | Written rules, a JSON diff to review, and tests for the known fixes and coverage. |
| A coverage gap has no recognised exercise for a pair (e.g. bodyweight side delts). | Candidates are listed above. If none holds up, the PR proposes a documented exception to the coverage test instead of an invented exercise. |
| The frontend and backend catalogs drift until the frontend reads the API. | Keys are the frontend names in snake_case. The API spec removes the frontend mocks. |
| An exercise key is renamed and a duplicate exercise appears. | Keys are permanent; a rename gets a migration that updates the row in place. |

## What is **not** in this spec

- The rest of free-exercise-db and coverage for kettlebell, bands and accessories (SPEC 09).
- API endpoints and the frontend switching to them.
- Mirroring images, ExerciseDB GIFs, exercises with multiple equipment, user-created exercises, translations.

Each one of those, if it lands, goes in its own spec.
