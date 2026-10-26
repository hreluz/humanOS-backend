# SPEC 07 — Equipment catalog

> **Status:** Approved
> **Depends on:** none
> **Date:** 2026-10-25
> **Objective:** Store the equipment catalog (6 groups, 12 pieces of equipment) in two database tables, filled by an idempotent seeder. Exercises can then reference equipment by foreign key, and clients can filter by group.

## Why this spec exists

The frontend hardcodes three equipment types (`EquipmentKey = 'gym' | 'dumbbell' | 'bodyweight'` in `humanOS-frontend/app/types/training.ts`, labels in `app/utils/muscles.ts`). free-exercise-db, the source of the frontend's exercise photos, has a finer list (barbell, machine, cable, kettlebells, bands, …). This spec stores that finer list and groups it. Each group is a filter tab like today's "Gym", so the existing three tabs stay and three new ones are added. Like SPEC 06, it only moves the catalog. Exercises and the API come in later specs.

## Scope

**In:**

- `equipment_groups` and `equipment` tables in the `Training` module.
- Models `EquipmentGroup` and `Equipment`.
- `database/seeders/EquipmentSeeder.php` that upserts the 6 groups and 12 pieces of equipment by `key`, called from `DatabaseSeeder`.
- Feature tests for the schema and the seeder.

**Out of scope (for future specs):**

- Exercises and the exercise↔equipment link.
- API endpoints (`GET` equipment) and a `TrainingServiceProvider`.
- The frontend switching from `EquipmentKey` to the API.
- An exercise that needs more than one piece of equipment (e.g. dumbbells and a bench).
- Translations of labels.

## Data model

### Tables

```php
// database/migrations/2026_10_25_000002_create_equipment_groups_table.php
Schema::create('equipment_groups', function (Blueprint $table) {
    $table->id();
    $table->string('key', 32)->unique();          // gym, dumbbell, bodyweight, ...
    $table->string('label');
    $table->unsignedSmallInteger('sort_order');
    $table->timestamps();
});

// database/migrations/2026_10_25_000003_create_equipment_table.php
Schema::create('equipment', function (Blueprint $table) {
    $table->id();
    $table->foreignId('equipment_group_id')->constrained()->restrictOnDelete();
    $table->string('key', 32)->unique();          // barbell, ez_bar, ...
    $table->string('label');
    $table->string('source_key', 32)->nullable()->unique(); // free-exercise-db `equipment` value
    $table->unsignedSmallInteger('sort_order');   // order within the group
    $table->timestamps();
});
```

- `key` is the stable identifier. `id` is for foreign keys.
- `source_key` maps free-exercise-db's `equipment` field to our rows, so a later import can tag exercises. It is nullable for equipment that free-exercise-db doesn't list.
- `restrictOnDelete`: a group that still has equipment can't be deleted.
- The table is `equipment` (uncountable); the model sets `$table` explicitly.

### Models (`app/Modules/Training/Models`)

- `EquipmentGroup`: `equipment(): HasMany`, ordered by `sort_order`.
- `Equipment`: `group(): BelongsTo` (`equipment_group_id`).
- No factories: the seeded catalog is the only data, and tests run the seeder.

### Seed data

Groups in this order (`sort_order` 1–6), with their equipment in order (`sort_order` starts at 1 in each group):

| Group `key` | Group `label` | Equipment `key` | Equipment `label` | `source_key` |
|---|---|---|---|---|
| `gym` | Gym | `barbell` | Barbell | `barbell` |
| | | `machine` | Machine | `machine` |
| | | `cable` | Cable | `cable` |
| | | `ez_bar` | EZ curl bar | `e-z curl bar` |
| `dumbbell` | Dumbbell | `dumbbell` | Dumbbell | `dumbbell` |
| `bodyweight` | Bodyweight | `body_only` | Body only | `body only` |
| `kettlebell` | Kettlebell | `kettlebell` | Kettlebell | `kettlebells` |
| `bands` | Bands | `bands` | Resistance bands | `bands` |
| `accessories` | Accessories | `medicine_ball` | Medicine ball | `medicine ball` |
| | | `exercise_ball` | Exercise ball | `exercise ball` |
| | | `foam_roll` | Foam roll | `foam roll` |
| | | `other` | Other | `other` |

The first three group keys match the frontend's `EquipmentKey` values, so today's tags carry over.

### Seeder behaviour

Same as `MuscleSeeder`: in one transaction, upsert each group by `key` (`label`, `sort_order`), then each piece of equipment by `key` (`equipment_group_id`, `label`, `source_key`, `sort_order`).

- Running it again updates rows in place. Ids don't change.
- It never deletes rows. A removal gets its own migration.
- `DatabaseSeeder::run()` calls it. On a deployed server, run `php artisan db:seed --class=EquipmentSeeder --force` after migrating.

### Files

```
app/Modules/Training/Models/{EquipmentGroup,Equipment}.php
database/migrations/2026_10_25_000002_create_equipment_groups_table.php, 2026_10_25_000003_create_equipment_table.php
database/seeders/EquipmentSeeder.php
database/seeders/DatabaseSeeder.php        (call EquipmentSeeder)
tests/Feature/Training/EquipmentSeederTest.php
```

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`). Branch from `master`.

1. **Schema and models.** The two migrations and the models. Check: `php artisan migrate` runs, and `migrate:rollback` drops both tables.
2. **Seeder.** `EquipmentSeeder` with the seed data above, called from `DatabaseSeeder`. Check: seeding gives 6 groups and 12 pieces of equipment; running it twice gives the same.
3. **Tests.** `tests/Feature/Training/EquipmentSeederTest.php` (with `RefreshDatabase`, seeding in each test):
   - 6 groups and 12 pieces of equipment, keys and order match the table above;
   - every piece of equipment has its `source_key` and belongs to the expected group;
   - seeding twice keeps the counts and ids;
   - after a label is changed in the database, seeding again restores it;
   - deleting a group that has equipment throws a `QueryException`.
4. **Housekeeping.** `vendor/bin/pint`; `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` creates `equipment_groups` and `equipment`, and rolling back drops them.
- [ ] After `php artisan db:seed --class=EquipmentSeeder`, the database holds exactly the rows in the seed table.
- [ ] Running the seeder a second time changes no ids and adds no rows.
- [ ] `php artisan db:seed` also seeds the catalog.
- [ ] Deleting an `equipment_groups` row that has equipment fails.
- [ ] `docs/openapi/v1.json` is unchanged (no API change).

## Decisions

- **Yes:** two levels, group → equipment. Clients filter by group (Gym covers barbell, machine, cable and EZ bar), while exercises can record the exact equipment.
- **Yes:** kettlebell and bands get their own groups. They're common for home training and don't belong under Gym.
- **Yes:** an "Accessories" group for medicine ball, exercise ball, foam roll and other. Few exercises use them, so one tab is enough.
- **Yes:** `source_key` stored on the row. It makes a free-exercise-db import a lookup, not a hand-written mapping.
- **No:** a PHP enum. You can't put a foreign key to it.
- **Yes:** an idempotent upsert seeder, same as SPEC 06.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The seeder isn't run on a deployed server. | The command is documented here; the API spec that reads these tables checks for an empty catalog. |
| The frontend and backend catalogs drift until the frontend reads the API. | The first three group keys match `EquipmentKey`; the API spec removes the frontend constants. |
| A key is renamed in the seeder and a new row appears instead. | Keys are permanent; a rename gets a migration. |
