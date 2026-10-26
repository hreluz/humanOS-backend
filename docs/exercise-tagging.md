# Exercise tagging

Rules for adding or changing exercises in `database/data/exercises.json` (SPEC 08). SPEC 09 uses the same rules for the rest of free-exercise-db.

The file is the source of truth for the exercise catalog. Only `ExerciseSeeder` and `ExerciseCatalogTest` read it; the app reads the database. To change the catalog, edit the file and reseed (`php artisan db:seed --class=ExerciseSeeder`). A direct database edit is overwritten on the next seed.

## Entry format

One object per exercise, sorted by muscle area and then by name. Muscle keys come from `MuscleSeeder` (SPEC 06) and equipment keys from `EquipmentSeeder` (SPEC 07).

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
    { "source": "free_exercise_db", "id": "Smith_Machine_Bench_Press" }
  ]
}
```

- `key` is the name in snake_case (`Push-up` → `push_up`). Keys are permanent: a rename needs a migration that updates the row in place.
- `source_id` is the free-exercise-db `id` of the exercise itself, or `null` when free-exercise-db doesn't have it. Two exercises can't share a `source_id`.
- `level`, `mechanic`, `force` and `instructions` are copied from free-exercise-db when `source_id` is set. Otherwise set `level` and `mechanic` by hand; `force` and `instructions` may be `null`.
- `cue` is one line, imperative, about form (`Lead with the elbow, stop at shoulder height.`).

### Photo entries

- `{ "source": "free_exercise_db", "id": "<free-exercise-db id>" }` becomes two photos, `<id>/0.jpg` and `<id>/1.jpg`. If `<id>` is the exercise's own `source_id`, they are captioned `Start` and `End`; otherwise `<label> · start` and `<label> · end`, where `<label>` is the id with `_-_` as `, ` and `_` as a space.
- `{ "source": "wger", "path": "exercise-images/…", "caption": "…", "license": "…", "author": "…", "credit_url": "https://wger.de/en/exercise/<id>/view/" }` becomes one photo. The license must be CC-BY-SA or CC0, and `author` and `credit_url` are required, since CC-BY-SA needs credit.

## Rules

1. **Primary** muscles do most of the work. **Secondary** muscles assist. Every exercise has at least one primary. List the primaries most direct first: clients rank exercises by the muscle's position in `primary`.
2. **Chest:** incline work → `upper_chest`; flat work → `upper_chest` and `lower_chest`; decline work and dips → `lower_chest`.
3. **Pressing:** flat and incline presses list both triceps heads (`triceps_lateral` and `triceps_long`) as secondary.
4. **Flyes:** flat flyes target both chest heads (`upper_chest` and `lower_chest` as primary).
5. **Neutral- and reverse-grip curls:** `biceps` and `forearm_extensors` (brachioradialis) as primary, `forearm_flexors` as secondary.
6. **Hamstrings:** tag both heads (`hamstrings_inner` and `hamstrings_outer`). Use only one if the exercise biases a head on purpose (toes in or out), and say so in the cue.
7. **Equipment:** the exact piece the exercise needs, never a group. Smith machine and leverage machines → `machine`; EZ bar → `ez_bar`; bodyweight → `body_only`. If an exercise also needs a bench or a bar to hang from, it still gets only its main load.
8. **Photos:** the exercise's own free-exercise-db photos first, then close variants that show the same movement pattern, at most three entries in all. Use wger only when free-exercise-db has nothing that matches.
9. A muscle that is neither primary nor secondary isn't tagged. Don't tag stabilisers.

## Coverage

Every muscle needs at least one exercise that lists it as primary in each of the `gym`, `dumbbell` and `bodyweight` equipment groups. `ExerciseCatalogTest` checks this. A new exercise added to close a gap must be a recognised exercise; use the free-exercise-db version when one exists.

When no recognised exercise makes a muscle the primary mover with a group's equipment, the pair becomes a documented exception in `ExerciseCatalogTest` instead of an invented exercise or a stretched tag. Current exceptions:

| Muscle | Group | Why |
|---|---|---|
| `forearm_extensors` | `bodyweight` | No bodyweight exercise loads wrist extension or the brachioradialis as the main mover. Neutral-grip pulling uses it, but as an assistant to the lats and biceps. |

## Checking photos

`php artisan exercises:check-photos` sends a HEAD request to every photo URL and lists the ones that fail. It needs the network, so run it by hand after changing photos.
