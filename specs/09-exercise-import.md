# SPEC 09 — Import the rest of free-exercise-db

> **Status:** Approved
> **Depends on:** SPEC 08
> **Date:** 2026-10-25
> **Objective:** Add the 770 free-exercise-db exercises that SPEC 08 didn't port to the exercise catalog, tagged with the rules in `docs/exercise-tagging.md` and in the same data format. Add a `neck` muscle, and extend the coverage check to the `kettlebell`, `bands` and `accessories` groups. No schema change and no API change.

## Why this spec exists

SPEC 08 put the frontend's curated list in the database: 145 exercises, 106 of them linked to a free-exercise-db `source_id`. free-exercise-db has 876 exercises at the pinned commit (`f00c92c7…`), so 770 are still missing. They include every stretching, olympic, strongman and most plyometrics exercises, and almost everything for kettlebells, bands and accessories. Later specs (the read API, workout logging, recommendations) need the full catalog, and the three new equipment tabs from SPEC 07 are empty without it.

SPEC 08 chose explicit tags per exercise, reviewed in a JSON diff, over keyword rules at import time. This spec keeps that choice. A script drafts the entries, but every tag in the committed file has been read and corrected by hand.

## What's left in free-exercise-db

Counted against the pinned `dist/exercises.json`, excluding the 106 `source_id`s already in the catalog:

| Category | Count | | Equipment (free-exercise-db) | Count |
|---|---|---|---|---|
| strength | 482 | | barbell | 155 |
| stretching | 122 | | other | 118 |
| plyometrics | 60 | | body only | 91 |
| powerlifting | 37 | | dumbbell | 91 |
| olympic weightlifting | 35 | | *(none)* | 72 |
| strongman | 21 | | cable | 65 |
| cardio | 13 | | kettlebells | 56 |
| | | | machine | 53 |
| | | | bands | 20 |
| | | | medicine ball | 17 |
| | | | exercise ball | 12 |
| | | | foam roll | 11 |
| | | | e-z curl bar | 9 |

Things that need handling:

- **72 have no equipment.** 62 are stretches, 7 plyometrics, 2 strength, 1 cardio. Nearly all are bodyweight.
- **free-exercise-db's muscles are coarse** (17 values: `shoulders`, `quadriceps`, `hamstrings`, `abdominals`, `traps`, …). Ours are fine-grained (29, SPEC 06). `shoulders` has to become front, side or rear delts; `abdominals` upper or lower abs or obliques; `traps` upper, mid or lower; `triceps` one or both heads.
- **`neck` has no muscle of ours.** 8 exercises list it as primary: 2 isometric (bodyweight), 3 plate or harness resistance (`other`), 2 stretches and 1 SMR. This spec adds the muscle (see "Neck muscle").
- **`abductors` maps to `glute_med`**; `middle back` maps to `mid_traps` (with `rear_delts` or `lats` where the movement says so).
- **3 key collisions** with curated exercises: `goblet_squat` (ours is dumbbell, theirs kettlebell), `russian_twist` (ours dumbbell, theirs bodyweight), `seated_dumbbell_press` (ours links `Dumbbell_Shoulder_Press`).
- **3 have no photos:** `Kettlebell_Halo`, `Kettlebell_Halo_With_Overhead_Extension`, `Kettlebell_Overhead_Triceps_Extension`.
- **79 free-exercise-db ids** are already used as variant photos on curated exercises. They still get their own entry here.

## Scope

**In:**

- A `neck` muscle in the `back` area (SPEC 06's catalog grows from 29 to 30 muscles).
- The tagging rules for categories SPEC 08 didn't cover (stretching, self-myofascial release, plyometrics, cardio, olympic, strongman), added to `docs/exercise-tagging.md`.
- Splitting the data file into a directory (`database/data/exercises/*.json`) so each batch is its own file and its own diff. `ExerciseSeeder` reads every file in the directory.
- All 770 remaining exercises, each with exact equipment, primary and secondary tags, its free-exercise-db metadata and instructions, and its own photos.
- Resolving the three key collisions and the near-duplicates of curated exercises (see "Import rules").
- Coverage for `kettlebell`, `bands` and `accessories`: fill the gaps with recognised exercises, and document the rest as exceptions.
- Updated tests: the catalog test reads the directory, checks the new categories' rules and the new groups' coverage, and checks that every free-exercise-db id at the pinned commit is either in the catalog or on the skip list.

**Out of scope:**

- API endpoints and the frontend moving off its mocks.
- Cues for imported exercises (see "Decisions").
- Drawing the neck on the frontend's body map. The frontend adds it in its own repo; until then it has a muscle it can't draw.
- Mirroring images, ExerciseDB GIFs, multiple equipment, user-created exercises, translations (unchanged from SPEC 08).

## Neck muscle

`MuscleSeeder` gets one more muscle:

| Area | `key` | `label` | `body_view` |
|---|---|---|---|
| `back` | `neck` | `Neck` | `back` |

- It goes first in the `back` area, before `upper_traps`, so the other back muscles move down one `sort_order`. The seeder upserts by `key`, so no muscle id changes.
- One muscle for flexion, extension and side bending. The figure would need separate front and back regions to justify a split, and no exercise in the catalog trains one side only at a level a client could show.
- `body_view` is `back`: most neck loading (harness, face-down plate) is extension, and the neck sits next to the traps on the back view.
- `MuscleSeederTest` expects 30 muscles and the new order. SPEC 06 stays as it was; this spec records the change.
- The coverage test now checks 30 muscles. `neck` needs an exercise or an exception in every group (see "Coverage").

## Data files

```
database/data/exercises/
  curated.json          # SPEC 08's exercises.json, moved unchanged
  strength.json         # strength + powerlifting
  olympic.json          # olympic_weightlifting + strongman
  conditioning.json     # plyometrics + cardio
  stretching.json       # stretching, including foam roll (SMR)
  skipped.json          # free-exercise-db ids left out on purpose, each with a reason
```

- The entry format is SPEC 08's, unchanged. A file is a JSON array sorted by muscle area and then by name.
- A `key` is unique across all files, and so is a `source_id`.
- `skipped.json` is a list of `{ "source_id": "…", "reason": "…" }`. The seeder ignores it; only `ExerciseCatalogTest` reads it.
- `ExerciseSeeder` loads `database/data/exercises/*.json` except `skipped.json`, validates all of them before writing, and then behaves as in SPEC 08 (upsert by `key`, rebuild tags and photos, never delete).
- Why a directory: one 25,000-line file is hard to review, and per-batch files let each implementation step be its own PR.

## Import rules

These go into `docs/exercise-tagging.md` next to SPEC 08's rules.

1. **Key:** the free-exercise-db name in snake_case. If that key is already taken, prefix the equipment key, with `body_only` written `bodyweight` (`kettlebell_goblet_squat`, `bodyweight_russian_twist`). Free-exercise-db's naming quirks stay in `name` only where they're the real name (`Farmer's Walk`); obvious formatting noise is cleaned (`Neck-SMR` → `Neck SMR`).
2. **Duplicates:** if a curated exercise is the same movement with the same equipment as a free-exercise-db exercise and has no `source_id`, set its `source_id` instead of importing a second copy. If it already has a different `source_id` (e.g. `seated_dumbbell_press` → `Dumbbell_Shoulder_Press`), the free-exercise-db one goes in `skipped.json` as a duplicate.
3. **Equipment:** free-exercise-db's value through `equipment.source_key` (SPEC 07). No equipment → `body_only`, unless the instructions name a prop, then `other`.
4. **Muscles:** map each coarse muscle to the fine ones the movement loads, following SPEC 08's rules 1 to 9. free-exercise-db's primary/secondary split is a starting point, not a given: if it marks a stabiliser as secondary, drop it (rule 9).
5. **Stretching:** primary = the muscles being stretched. No secondary.
6. **Self-myofascial release (foam roll, `-SMR`):** primary = the muscle being rolled. No secondary. Category `stretching`, as free-exercise-db has it.
7. **Plyometrics, cardio, olympic, strongman:** tag the main movers as for strength. For whole-body lifts (clean, snatch, atlas stone), list at most three primaries, most direct first.
8. **Photos:** the exercise's own two photos only; no variants. An exercise without photos gets a wger image if one matches (SPEC 08 rule 8), or none.
9. **Cue:** `null`.

## Coverage

SPEC 08's coverage test checks every muscle × `gym`/`dumbbell`/`bodyweight` pair. This spec adds `kettlebell`, `bands` and `accessories` and the `neck` muscle, counting only exercises in `strength`, `powerlifting`, `olympic_weightlifting`, `strongman`, `plyometrics` and `cardio` (a stretch doesn't train the muscle).

free-exercise-db alone leaves big gaps there. At its coarse level, kettlebell exercises have no primary for abductors, adductors, biceps, calves, glutes, lats or lower back; band exercises have none for abdominals, biceps, forearms, lats, lower back or middle back. At our 29-muscle level the gaps are wider.

For each gap: add a recognised exercise if one exists (kettlebell curl, kettlebell calf raise, band lat pulldown, band pallof press, …), with a wger photo or none. Otherwise add a documented exception to `docs/exercise-tagging.md` and the test. The PR lists every added exercise and every new exception.

Expected for `neck`: `bodyweight` (isometric neck exercises) and `accessories` (plate and harness resistance) are covered by free-exercise-db; `gym` with a recognised machine neck extension (4-way neck machine); `bands` with a recognised band neck extension; `dumbbell` and `kettlebell` as exceptions unless a recognised exercise turns up.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 08 is merged. The work ships as five PRs, each leaving the suite green:

| PR | Steps |
|---|---|
| 1 | 1 (directory, seeder, neck) |
| 2 | 2 (strength, powerlifting) |
| 3 | 3 and 4 (olympic, strongman, plyometrics, cardio) |
| 4 | 5 (stretching) |
| 5 | 6 and 7 (coverage for the new groups, completeness) |


1. **Data directory and seeder.** Move `exercises.json` to `exercises/curated.json`, make the seeder and `ExerciseCatalogTest` read the directory and ignore `skipped.json`, add the import rules to `docs/exercise-tagging.md`. Resolve `goblet_squat` and `russian_twist` (rule 2). Add the `neck` muscle, and close its `gym`, `dumbbell` and `bodyweight` coverage in this PR (the two isometric neck exercises, a machine neck extension, and a `dumbbell` exception), because SPEC 08's coverage test fails otherwise. Check: the suite passes, the seeder still loads the 145 curated exercises with the same ids, and muscle ids don't change.
2. **Strength and powerlifting** (519) → `strength.json`.
3. **Olympic and strongman** (56) → `olympic.json`.
4. **Plyometrics and cardio** (73) → `conditioning.json`.
5. **Stretching** (122) → `stretching.json`.
6. **Coverage for kettlebell, bands, accessories.** Add exercises and exceptions until the extended coverage test passes.
7. **Completeness test.** `ExerciseCatalogTest` reads a committed list of free-exercise-db ids at the pinned commit (`tests/Fixtures/free-exercise-db-ids.json`, 876 ids) and checks that each one is a `source_id` in the catalog or a `source_id` in `skipped.json`, never both.

For steps 2 to 5, a one-off script (not committed) reads the pinned `dist/exercises.json`, drafts each entry (key, name, equipment, category, metadata, instructions, photos, and a first guess at the fine tags from the coarse ones), and writes the batch file. Then every entry is reviewed by hand against the rules, and the PR description lists the tag corrections that weren't mechanical.

Tests added or changed:

- `ExerciseCatalogTest`: reads the directory; keys and `source_id`s unique across files; stretching entries have no secondary; the extended coverage check; the completeness check; `skipped.json` entries have a reason and a valid free-exercise-db id.
- `ExerciseSeederTest`: the count matches the sum of the files; a duplicate key in two files throws and writes nothing.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] Every free-exercise-db id at the pinned commit is in the catalog or in `skipped.json` with a reason.
- [ ] `php artisan db:seed --class=ExerciseSeeder` loads at least 900 exercises, and running it twice changes no ids.
- [ ] The 145 curated exercises keep their ids and tags (except the `source_id` fixes from rule 2).
- [ ] `MuscleSeeder` seeds 30 muscles, including `neck` first in the `back` area, with no muscle id changed.
- [ ] Every muscle × group pair (30 muscles, all six groups) has an exercise with that muscle as primary, or a documented exception.
- [ ] `php artisan exercises:check-photos` exits with `0`.
- [ ] `docs/exercise-tagging.md` has the import rules and the full exception table.
- [ ] `docs/openapi/v1.json` is unchanged.

## Decisions

- **Yes:** a directory of data files, one per batch. Reviewable diffs, one PR per batch.
- **Yes:** a script drafts the entries and a person reviews every one. Writing 770 entries by hand is error-prone; committing script output unreviewed would be the keyword rules SPEC 08 rejected.
- **Yes:** `skipped.json` plus a completeness test, so nothing is dropped silently.
- **No:** cues for imported exercises. free-exercise-db has none, and writing 770 good cues is its own piece of work. `cue` is already nullable.
- **No:** variant photos for imported exercises. Almost all of them have their own two photos.
- **Yes:** stretches are tagged with the muscle stretched, and don't count for coverage.
- **No:** schema or enum changes. SPEC 08 defined all seven categories already.
- **Yes:** a single `neck` muscle now, before the frontend can draw it. Skipping the neck exercises would leave holes in the import; the frontend adds the region later.
- **Yes:** full coverage for `kettlebell`, `bands` and `accessories`, with documented exceptions, not just a report.
- **Yes:** five PRs. A batch of a few hundred entries is the most that can be reviewed tag by tag.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Wrong fine-grained tags across 770 entries. | Hand review per batch, the rules doc, per-batch PRs small enough to read, and listed non-mechanical corrections. |
| A near-duplicate of a curated exercise gets in under another key. | Rule 2, and the draft script flags names within one word of a curated name for review. |
| Clients show 900+ exercises with no cue. | Clients already handle `cue: null`. Cues can be added in a later spec. |
| The frontend gets `neck` from the API before it can draw it. | Clients already have to ignore muscles they have no region for; the frontend spec that switches to the API handles it. |
| free-exercise-db changes upstream. | We stay on the pinned commit; the id list fixture makes a later bump an explicit diff. |
