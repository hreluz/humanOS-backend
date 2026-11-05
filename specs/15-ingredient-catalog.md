# SPEC 15 — Worldwide ingredient catalog

> **Status:** Approved
> **Depends on:** None
> **Date:** 2026-11-03
> **Objective:** Store about 200 reviewed basic ingredients available anywhere in the world in database tables, with nutrition per 100 g, portions, and English and Spanish names, loaded from committed data files by an idempotent seeder.
> **Note:** Moving IngredientSeeder's locales to config was deferred from SPEC 16 to the language spec by SPEC 16.
> **Note:** ingredients gain a nullable user_id (null for system ingredients), and IngredientSeeder only touches system rows, by SPEC 17.

## Why this spec exists

The frontend's Food section runs on 50 mock ingredients, some of them branded and US-specific. Everything else in Food (My foods, recipes, logging, meal plans) builds on a real ingredient catalog, so that comes first. SPEC 16 exposes it through the API, and SPEC 17 adds photos.

The catalog follows the exercise catalog's method (SPEC 08, SPEC 09): a script drafts committed data files, a person reviews them in batches, and a seeder loads them.

## Scope

**In:**

- A `Nutrition` module with the catalog's enums and models.
- Tables for ingredients, the nutrient list, ingredient nutrient values, portions, translations and aliases.
- 29 nutrients in addition to the five macro columns.
- About 200 ingredients from USDA FoodData Central. (A few Peruvian table entries were planned too; step 1 dropped them, see "Source check results".)
- A Spanish name for every ingredient and portion, and search aliases for regional names (`palta`, `aguacate`).
- `NutrientSeeder`, `IngredientSeeder` and catalog tests.
- Removing ingredients, portions and nutrients that leave the data: a hard delete, or a soft delete if something references them.
- `docs/ingredient-catalog.md` with the inclusion, naming and sourcing rules, and the source credits.

**Out of scope:**

- API endpoints and the Nutrition service provider (SPEC 16).
- Photos, their columns and `ingredients:check-photos` (SPEC 17).
- User-created ingredients (My foods), recipes, meal logging and meal plans.
- Prices and brands.
- Languages other than English and Spanish. The schema allows them without a migration.
- Moving the frontend off its mocks.

## Data sources

| Source | `source` value | Release | Where | Use |
|---|---|---|---|---|
| USDA Foundation Foods | `usda_foundation` | October 2026 | https://fdc.nal.usda.gov/download-datasets | First choice: the most recent analytical data |
| USDA SR Legacy | `usda_sr_legacy` | April 2018 (final) | https://fdc.nal.usda.gov/download-datasets | Everything Foundation Foods doesn't cover |

- Every entry stores its FDC id in `source_id`.
- A food USDA lacks is left out.
- FoodData Central is credited in `docs/ingredient-catalog.md` in the form its terms ask for.
- The Peruvian table (CENAN/INS, 10th ed., 2017) was planned as a third source, `food_table`, and dropped in step 1 because its terms forbid reproduction without authorization (see "Source check results").

### Source check results (step 1, 2026-11-03)

Files checked: `FoodData_Central_foundation_food_json_2026-10-29.zip` and `..._csv_2026-10-29.zip` (424 foods), `FoodData_Central_sr_legacy_food_json_2018-04.zip` and `..._csv_2018-04.zip` (7,793 foods).

- **Nutrient ids:** every id in "Nutrients" and "Source mapping" exists in both releases' `nutrient.csv`, with the unit listed (`G`, `MG`, `UG`, `KCAL`).
- **Carbs:** holds. `1050` equals total sugars + starch + fiber on every Foundation food that reports its parts (e.g. broccoli: 1.4 + 0 + 2.4 = 3.8), so it includes fiber like `1005`. In this release, all 30 foods with `1050` also report `1005`, so the fallback is kept but no entry uses it. The spec's "many Foundation foods only report `1050`" was wrong.
- **Energy:** holds. Foundation reports `1008` on 95 foods, `2048` on 200 and `2047` on 279, and no food has both `1008` and `2048`. SR Legacy reports only `1008`.
- **Macro gaps:** 50 Foundation foods have no carbs and no energy, among them every oil, both butters, salt, dry beans at 0% moisture, blackberries, grapefruit, leeks and raisins. Rule 4 applies: those foods use SR Legacy or are left out.
- **Sugars:** holds. SR Legacy uses only `2000`. Foundation uses `1063` (161 foods, nutrient number 269.3, "Sugars, Total") and `2000` on 5 apples, where both values match (10.6 against 10.7 at most).
- **Folate:** holds. Foundation reports no `1190`, only `1177` (172 foods). SR Legacy reports `1190` on 6,482 foods.
- **File format:** holds. Foundation's CSV download has no `food.csv`, `food_nutrient.csv` or `food_portion.csv`, so the script reads the JSON download.
- **USDA terms:** "USDA FoodData Central data are in the public domain and they are not copyrighted. They are published under CC0 1.0 Universal (CC0 1.0)." No permission is needed, "but we request that users list FoodData Central as the source of the data". Suggested citation: "U.S. Department of Agriculture, Agricultural Research Service. FoodData Central" with the release and fdc.nal.usda.gov (https://fdc.nal.usda.gov/api-guide/).
- **Peruvian table:** fetched from https://lamejorreceta.ins.gob.pe/sites/default/files/2020-12/tablas-peruanas-QR_0.pdf (146 pages, ISBN 978-612-310-117-6). Its copyright page reads "Prohibida la reproducción total o parcial de esta obra por cualquier medio sin autorización del Instituto Nacional de Salud". Its terms don't allow reuse, so `food_table` is dropped, as step 1 requires. For the record, its basis: total carbohydrate by difference including fiber (it also reports available carbohydrate); energy in kcal and kJ from FAO factors; vitamin A as total retinol equivalents (not RAE); folate as "ácido fólico"; minerals reported are calcium, phosphorus, zinc, iron, sodium and potassium.

## Data model

### Enums (`app/Modules/Nutrition/Enums`)

- `IngredientCategory`: `meat`, `seafood`, `eggs`, `plant_protein`, `dairy`, `grains`, `legumes`, `vegetables`, `fruit`, `fats`, `condiments`, `supplements`, `other`. These are the frontend's 13 keys (`app/types/food.ts`). A category may have no entries; `supplements` likely won't, because branded products are excluded and USDA has few generic ones.
- `IngredientSource`: `usda_foundation`, `usda_sr_legacy`.
- `NutrientGroup`: `fats_and_sugars`, `minerals`, `vitamins`.
- `NutrientUnit`: `g`, `mg`, `ug`.

### Tables

**`ingredients`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `key` | string, unique | Stable snake_case, e.g. `chicken_breast_raw`. Unique including soft-deleted rows |
| `name` | string | English; the default display name |
| `category` | string, indexed | `IngredientCategory` |
| `kcal` | decimal(7,2) | Per 100 g, required |
| `protein` | decimal(7,3) | Grams per 100 g, required |
| `carbs` | decimal(7,3) | Grams per 100 g, required |
| `fat` | decimal(7,3) | Grams per 100 g, required |
| `fiber` | decimal(7,3) | Grams per 100 g, required |
| `source` | string | `IngredientSource` |
| `source_id` | string, indexed | FDC id. Unique in the data files, not in the database (see "Removing entries") |
| `deleted_at` | timestamp, nullable | Soft delete |
| timestamps | | |

**`nutrients`**: `id`, `key` (unique), `label` (English), `group`, `unit`, `fdc_nutrient_id` (unsigned int, indexed; unique in `NutrientSeeder`'s list, not in the database), `sort_order`, `deleted_at`.

**`nutrient_translations`**: `id`, `nutrient_id`, `locale`, `label`. Unique on `nutrient_id` + `locale`.

**`ingredient_nutrient`**: `ingredient_id`, `nutrient_id`, `amount` decimal(10,3) in the nutrient's unit per 100 g. Primary key on both ids.

**`ingredient_portions`**: `id`, `ingredient_id`, `key` (snake_case, e.g. `whole`, `cup_cubes`), `label` (English, e.g. `1 large egg`), `grams` decimal(7,2), `position`, `deleted_at`. Unique on `ingredient_id` + `key`, including soft-deleted rows. Indexed on `ingredient_id` + `position`, not unique, because a soft-deleted portion keeps its old position.

**`ingredient_portion_translations`**: `id`, `ingredient_portion_id`, `locale`, `label`. Unique on `ingredient_portion_id` + `locale`.

**`ingredient_translations`**: `id`, `ingredient_id`, `locale`, `name`. Unique on `ingredient_id` + `locale`. English lives in `ingredients.name`, not here.

**`ingredient_aliases`**: `id`, `ingredient_id`, `locale`, `name`. Unique on `ingredient_id` + `locale` + `name`, indexed on `name`.

All child tables cascade on delete. `Ingredient`, `IngredientPortion` and `Nutrient` use `SoftDeletes`. Translations, aliases and `ingredient_nutrient` have no `deleted_at`: nothing references them, so they are always hard-deleted.

### Calculation rules

Every client and later spec calculates the same way:

```
grams    = portion.grams × quantity
nutrient = value_per_100g × grams / 100
```

- **Everything is per 100 g, liquids included.** USDA reports liquids per 100 g, and `1 cup` of milk is a portion with a gram weight. No ml column or density conversion exists.
- **`kcal` is stored, never derived from macros.** USDA's value comes first. 4/4/9 is wrong for some foods.
- **Raw and cooked are separate ingredients**, because cooking changes the weight.
- **A missing `ingredient_nutrient` row means "not measured", and `0` means "measured as zero".** A sum over several ingredients must not treat a missing row as zero. Showing that gap is up to the feature that does the summing.
- **Values are stored as the source gives them**, at the column's precision. Rounding for display is up to clients.

### Rules for later specs

Anything that references the catalog (meal logs, recipes, My foods):

- references `ingredients`, `ingredient_portions` or `nutrients` by id, with a foreign key declared `ON DELETE RESTRICT`. That is what makes the seeder soft-delete instead of hard-delete (see "Removing entries").
- stores the grams eaten at the time it's saved. A portion's grams can change in the files, and a past entry must not recalculate.

### Nutrients

`NutrientSeeder` upserts these 29 by `key`, with English and Spanish labels.

| Group | `key` (unit, FDC nutrient id) |
|---|---|
| `fats_and_sugars` | `sugars` (g, 2000), `saturated_fat` (g, 1258), `monounsaturated_fat` (g, 1292), `polyunsaturated_fat` (g, 1293), `trans_fat` (g, 1257), `cholesterol` (mg, 1253) |
| `minerals` | `calcium` (mg, 1087), `iron` (mg, 1089), `magnesium` (mg, 1090), `phosphorus` (mg, 1091), `potassium` (mg, 1092), `sodium` (mg, 1093), `zinc` (mg, 1095), `copper` (mg, 1098), `manganese` (mg, 1101), `selenium` (ug, 1103) |
| `vitamins` | `vitamin_a` (ug, 1106), `vitamin_c` (mg, 1162), `vitamin_d` (ug, 1114), `vitamin_e` (mg, 1109), `vitamin_k` (ug, 1185), `thiamin` (mg, 1165), `riboflavin` (mg, 1166), `niacin` (mg, 1167), `pantothenic_acid` (mg, 1170), `vitamin_b6` (mg, 1175), `folate` (ug, 1190), `vitamin_b12` (ug, 1178), `choline` (mg, 1180) |

`vitamin_a` is in RAE and `folate` in DFE. Their unit stays `ug`, and the basis goes in the label in both languages: `Vitamin A (RAE)` / `Vitamina A (RAE)`, `Folate (DFE)` / `Folato (DFE)`.

### Source mapping

The draft script reads these FDC nutrient ids. Step 1 checks every id and rule below against the pinned releases, and this section changes if one is wrong.

- **Macros:** protein `1003`, fat `1004`, fiber `1079`.
- **Carbs:** `1005` (by difference), then `1050` (by summation). Many Foundation foods only report `1050`. Step 1 confirms that `1050` includes fiber like `1005`. If it doesn't, the fallback is dropped and rule 4 applies.
- **Energy:** `1008`, then `2048` (Atwater specific), then `2047` (Atwater general). Specific factors come first because SR Legacy's `1008` was calculated with them.
- **Sugars:** `2000`, then `1063`. The plan assumes Foundation Foods reports the same measurement under `1063`, and SR Legacy only uses `2000`. `fdc_nutrient_id` stays `2000`.
- **Folate:** `1190` only. The plan assumes Foundation Foods doesn't report DFE, and its `1177` (total folate) is a different measure, so Foundation entries have no `folate` row.
- **File format:** the plan assumes Foundation Foods' CSV download lacks the food, nutrient value and portion files, so the script reads its JSON download.

Step 1 confirmed every rule here; see "Source check results".

### Data files (`database/data/ingredients/<category>.json`)

One file per category, a JSON array sorted by `name`:

```json
{
  "key": "egg_whole_raw",
  "name": "Egg, whole, raw",
  "category": "eggs",
  "source": "usda_foundation",
  "source_id": "000000",
  "kcal": 143, "protein": 12.56, "carbs": 0.72, "fat": 9.51, "fiber": 0,
  "nutrients": { "cholesterol": 372, "selenium": 30.7 },
  "portions": [
    { "key": "large", "label": "1 large egg", "grams": 50, "translations": { "es": "1 huevo grande" } }
  ],
  "translations": { "es": "Huevo entero crudo" },
  "aliases": { "es": ["blanquillo"] }
}
```

The values above show the format. They aren't data to copy. Ingredients and portions share the `translations` shape, so a new language is one more key in each. A portion's `position` is its order in the array. SPEC 17 adds an optional `photo` object to each entry.

### Seeder behaviour

Same contract as `ExerciseSeeder`, plus removal:

- `IngredientSeeder` reads every file in the directory and validates all entries before writing.
- A duplicate `key` or `source_id`, a missing `source_id`, a duplicate portion `key` within an ingredient, an unknown category, source or nutrient key, an unknown locale, or a missing macro throws and writes nothing. Translations (ingredient and portion) accept only `es`. Aliases accept `en` and `es`. The seeder holds that list in a constant until SPEC 16 adds `config('nutrition.locales')`. A soft-deleted nutrient counts as unknown.
- It upserts ingredients by `key` and portions by `ingredient_id` + `key`, soft-deleted rows included, so ids stay the same between runs. A soft-deleted ingredient or portion whose key is back in the files is restored with its old id.
- It rebuilds each ingredient's nutrient values, translations, aliases and portion translations from the files.
- It removes ingredients and portions that are no longer in the files, as described in "Removing entries".
- It accepts a directory in its constructor, so tests use small fixture files instead of seeding the whole catalog.
- It writes child rows in bulk, and runs in one transaction.
- `NutrientSeeder` follows the same upsert, restore and removal rules, by `key`. It accepts its nutrient list in the constructor (the 29 above by default), so a test can drop one.
- `DatabaseSeeder` calls `NutrientSeeder`, then `IngredientSeeder`.

### Removing entries

An ingredient, portion or nutrient that is in the database but no longer in the data:

1. **Not referenced:** hard-deleted. An ingredient's children go with it through the cascade.
2. **Referenced:** soft-deleted. The seeder finds out by trying the hard delete inside a savepoint (a nested `DB::transaction`). A later table's `ON DELETE RESTRICT` foreign key blocks it, and the seeder rolls back to the savepoint and sets `deleted_at` instead. An ingredient also counts as referenced when one of its portions is, because the cascade is blocked.

Nothing keeps a list of referencing tables: the database's foreign keys decide. The savepoint is needed on PostgreSQL, where a failed statement aborts the whole transaction. A soft-deleted row keeps its children (a soft-deleted portion keeps its translations), so whatever references it can still show it. `Ingredient`, `IngredientPortion` and `Nutrient` hide soft-deleted rows through `SoftDeletes`, so SPEC 16 lists only what's in the files.

One helper used by both seeders does this.

## Catalog rules (`docs/ingredient-catalog.md`)

1. **Include** about 200 basic generic ingredients sold almost anywhere in the world. Foods usually eaten cooked get one raw (or as bought) and one common cooked form, and foods eaten as bought (fruit, nuts, oils, cheese, spices) get one entry. Canned or frozen forms only for foods mostly bought that way.
2. **Exclude** restaurant and fast-food items, baby food and infant formula, prepared dishes, branded products, processed meats beyond bacon and ham, juices and dressings beyond a few basics, regional foods not sold widely, rare and game species, organ meats beyond liver, lean-only and fat-level variants, rare cuts, and near-duplicates.
3. **USDA only.** Foundation Foods wins over SR Legacy for the same food. A food USDA lacks is left out.
4. **Required macros.** If fiber is missing, foods that contain none (meat, eggs, fish, oils) get `0`. Otherwise, choose another entry for that food or leave it out. A negative value (carbohydrate by difference below zero, found on two Foundation meats in step 4) counts as unusable in the same way.
5. **Names** are rewritten to be readable, keeping the preparation: `Chicken breast, grilled`, not USDA's long form. No two ingredients share a name in English or in Spanish.
6. **Category** is one of the 13 keys, chosen by how a person shops for the food.
7. **Portions** come from USDA's measures. A Foundation entry may take its measures from the SR Legacy entry for the same food, since Foundation lists few household measures. An ingredient may have none, because grams always work. Each has a short snake_case `key` describing the measure (`large`, `cup_cubes`). Keys aren't renamed once committed: a renamed key is a removed portion plus a new one.
8. **Spanish** names are neutral and widely understood. Regional words go in `aliases` (`palta` under Avocado, `choclo` under Corn). English alternatives go there too (`coriander` under Cilantro).
9. **Nutrition values are copied, never estimated.** Nutrients the source doesn't report stay absent.

SPEC 17 adds rule 10, for photos.

## Process

- A one-off script, not committed, reads the pinned USDA files, drafts entries, and flags near-duplicates and any entry whose `kcal` is far from 4/4/9.
- Nutrition values are copied by the script and aren't retyped.
- Names, category, inclusion, Spanish names, aliases, portion keys and portions may be drafted with an LLM, and a person reads every one against the rules before it's committed. SPEC 09 and SPEC 14 set this standard.
- Each PR description lists the judgement calls.

## Implementation plan

All commands run inside the `app` container. Branch from `master`.

| PR | Steps |
|---|---|
| 1 | 1, 2, 3 (source check, schema, seeders, rules, tests, eggs and dairy) |
| 2 to n | 4 (one category group each) |
| Last | 5, 6 (count check, housekeeping) |

1. **Source check and schema.** Download the pinned USDA releases and the Peruvian table. Check, and record the results in this spec:
   - every FDC nutrient id in "Nutrients" and "Source mapping" exists in both USDA releases' `nutrient.csv`;
   - the carbs, energy, sugars and folate rules and the file format hold as written;
   - the basis the Peruvian table reports each of the 29 nutrients on;
   - USDA's usage terms, and how it asks to be credited;
   - the Peruvian table's usage terms and credit, and its URL.

   If the Peruvian table's terms don't allow reuse, drop `food_table` from this spec before any data is committed. Then add the enums, migrations and models, the removal helper, then `NutrientSeeder`. Check: `php artisan migrate:fresh` works, and `NutrientSeeder` seeds 29 nutrients with Spanish labels. Tests in `NutrientSeederTest`: running it twice changes no ids; a nutrient dropped from the list is hard-deleted, or soft-deleted if a temporary table references it with `RESTRICT`; a dropped nutrient that comes back is restored with its old id.
2. **Ingredient seeder.** Add `IngredientSeeder` and its call in `DatabaseSeeder`. Tests in `IngredientSeederTest`, using fixture files:
   - it loads an entry with all its child rows, including portion translations;
   - an entry without a given nutrient has no `ingredient_nutrient` row for it, and a nutrient given as `0` has a row with `0`;
   - running it twice changes no ingredient or portion ids and leaves no duplicate child rows;
   - a changed value, and a changed portion label or grams, update in place;
   - reordering portions changes only `position`;
   - an ingredient or portion removed from the files is hard-deleted when nothing references it;
   - one that a temporary table references with `RESTRICT` is soft-deleted, keeps its children, and is hidden from `Ingredient::query()` or the ingredient's `portions`;
   - an ingredient whose portion is referenced is soft-deleted, not hard-deleted;
   - a soft-deleted ingredient or portion whose key is back in the files is restored with its old id;
   - a duplicate key, duplicate `source_id`, duplicate portion key, unknown nutrient, soft-deleted nutrient, unknown locale (`sp` in translations, `fr` in aliases) or missing macro throws and writes nothing.
3. **Rules, catalog test and first batch.** Add `docs/ingredient-catalog.md` with the rules and source credits, `IngredientCatalogTest` and `tests/Fixtures/ingredient-values.json`, then `eggs.json` and `dairy.json`. The test reads the data files and checks:
   - keys are snake_case and unique across files, and `source_id` is unique;
   - portion keys are snake_case and unique within their ingredient;
   - English names are unique, and Spanish names are unique;
   - every entry has a `source_id`;
   - `kcal` is between 0 and 902, macros aren't negative, and protein + carbs + fat is at most 102 g;
   - every ingredient has a Spanish name, and every portion has a Spanish label and `grams` above 0;
   - nutrient keys exist and amounts aren't negative;
   - every entry in the values fixture exists in the data files, and its values match.

   The fixture starts with 2–3 values read by hand from the FoodData Central website for eggs and dairy.
4. **Batches.** Meat, then seafood, plant protein and legumes, grains, vegetables, fruit, fats, and condiments, supplements and other. Each batch adds 2–3 hand-read entries for its categories to the values fixture. Check for each: the suite passes.
5. **Count check.** Add a check in `IngredientCatalogTest` that the data files hold 180 to 230 entries. (This step also added the Peruvian entries until step 1 dropped them.) The PR lists which catalog ingredient replaces each of the frontend's generic mock ingredients.
6. **Housekeeping.** Add the module, the catalog and the "Rules for later specs" to `CLAUDE.md`. Run `vendor/bin/pint`. Check: `docs/openapi/v1.json` is unchanged.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] The data files hold between 180 and 230 ingredients, `php artisan migrate:fresh --seed` loads all of them, and running `php artisan db:seed --class=IngredientSeeder` again changes no ingredient or portion ids.
- [ ] Every ingredient has all five macro values, a source and a Spanish name, and no two share an English or Spanish name.
- [ ] Every ingredient has a unique FDC id.
- [ ] `NutrientSeeder` seeds exactly 29 nutrients, and every FDC nutrient id and source mapping rule was checked against the pinned releases in step 1.
- [ ] USDA's and the Peruvian table's usage terms were checked, `food_table` was dropped because the Peruvian table's terms don't allow reuse, and FoodData Central is credited in `docs/ingredient-catalog.md`.
- [ ] A nutrient the source doesn't report for a food has no `ingredient_nutrient` row.
- [ ] An ingredient, portion or nutrient removed from the data is hard-deleted when unreferenced and soft-deleted when referenced, and is restored with its old id when its key comes back.
- [ ] An unknown locale in a data file makes `IngredientSeeder` throw and write nothing.
- [ ] The values fixture has entries for every category that has ingredients, and they match the catalog.
- [ ] `docs/ingredient-catalog.md` has the catalog rules, and `CLAUDE.md` mentions the module and the rules for later specs.
- [ ] `docs/openapi/v1.json` is unchanged.

## Decisions

- **Yes:** USDA only. Values trace back to a measurement. **No:** the Peruvian table, planned for a few foods USDA lacks. Step 1 found its terms forbid reproduction without INS authorization; asking for it is a later decision.
- **No:** a `source_citation` column. It only existed for Peruvian entries, and every remaining entry has an FDC id.
- **No:** LLM-written nutrition values. They can be subtly wrong and can't be traced.
- **No:** Open Food Facts. It's mostly packaged products, with crowd-sourced quality.
- **Yes:** about 200 universal basic entries, not USDA's full 7,793 in SR Legacy. Dozens of near-identical cuts make search worse.
- **No:** the 1,500–2,500 entries first planned, or a 471-entry intermediate version. Both were mostly variants and foods not sold worldwide, which made search and review worse without helping people log everyday meals.
- **No:** a world staples check (15 regional foods per region). It conflicts with a catalog of foods available anywhere.
- **No:** the West African (FAO/INFOODS) and Indian (IFCT) tables. They report carbs without fiber, and the Indian table gives energy only in kJ, so their values aren't comparable with USDA's.
- **Yes:** per 100 g for everything, with portions in grams. There's one formula, and "2 eggs" still works.
- **No:** the frontend's `per`, `step` and `pieceName`. They're UI details, and the frontend maps to them.
- **Yes:** macros as required columns, and the other 29 nutrients in a table. Macros are read constantly and always present; the others can be absent.
- **Yes:** 29 label-level nutrients, not all of USDA's roughly 150. The FDC id makes adding more a data change.
- **Yes:** carbs fall back from `1005` to `1050`. Many Foundation foods only report carbs by summation, and without the fallback they'd go to SR Legacy or be left out.
- **Yes:** energy falls back to Atwater specific (`2048`) before general (`2047`). It's the method SR Legacy used, so all entries are calculated alike.
- **Yes:** sugars fall back from `2000` to `1063`, if step 1 confirms they're the same measurement.
- **No:** copying total folate (`1177`) into `folate`, or switching the catalog to total folate. DFE is the label figure, and a mixed column would mislabel values. Foundation entries show folate as not measured.
- **Yes:** RAE and DFE in the label, with the unit kept as `ug`. It's how nutrition labels print them, and clients handle one set of units.
- **Yes:** USDA's data is expected to be CC0 1.0, and step 1 confirms it. USDA asks users to list FoodData Central as the source, so `docs/ingredient-catalog.md` does.
- **Yes:** a translations table, with English on the main table. Every ingredient always has a default name, and a new language needs no migration.
- **Yes:** Spanish for portions and nutrient labels too. Otherwise a Spanish screen would show `1 cup`.
- **Yes:** the same `translations` shape for ingredients and portions in the data files. The seeder reads both the same way, and a new language is one more key.
- **Yes:** separate translation tables per entity, not one polymorphic table. Foreign keys keep them consistent.
- **Yes:** an `id` on every translation table, with the unique index on parent + locale. Eloquent doesn't support composite primary keys, and the other child tables have one. Only the `ingredient_nutrient` pivot uses a composite key.
- **Yes:** aliases apart from translations. A display name is one per language, and regional names are many.
- **Yes:** unique names in English and Spanish. Search results never show two identical rows.
- **Yes:** the seeder rejects unknown locales. A typo like `sp` would otherwise load silently and leave an ingredient without a Spanish name.
- **Yes:** an index on `ingredients.category`. SPEC 16 filters by it, and it costs nothing.
- **Yes:** a `key` on each portion, upserted by ingredient + key. Ids survive a reseed, a removed portion can come back with its old id, and reordering doesn't repoint anything. **No:** matching portions by position, which repoints ids when portions are reordered.
- **Yes:** removed ingredients, portions and nutrients are hard-deleted when unreferenced and soft-deleted when referenced. The database matches the files, and nothing a log points at disappears. This replaces an earlier "never delete an ingredient" rule, which left stale rows that the API kept showing.
- **Yes:** the database's `RESTRICT` foreign keys decide whether a row is referenced, tried inside a savepoint. **No:** a list of referencing tables in the seeder, which a later spec could forget to update.
- **No:** soft deletes on translations, aliases and `ingredient_nutrient`. Nothing references them, so the rule would always hard-delete. A `deleted_at` there would also have to be filtered by hand in SPEC 16's search subqueries, and would add a third state to "not measured".
- **Yes:** `source_id` and `fdc_nutrient_id` unique in the data, not in the database. A soft-deleted row keeps its id, and a renamed key would otherwise fail on the unique index.
- **Yes:** later specs store the grams eaten at the time. Portions are updated in place, and a past entry must not recalculate.
- **Yes:** the values fixture grows with each batch. Every PR stays green, and every category gets hand-checked values.
- **Yes:** `supplements` stays in the enum even if it's empty. It's one of the frontend's keys.
- **Yes:** photos split into SPEC 17. Sourcing and reviewing them is separate work that shouldn't hold back the nutrition data, and their check command needs the Nutrition service provider from SPEC 16.
- **No:** prices and brands. One USD price is wrong almost everywhere, and the frontend already handles a missing price.
- **No:** a `skipped.json` like SPEC 09's. More than 5,000 USDA foods are left out by rule, and listing each adds nothing.
- **Yes:** split from the API (SPEC 16), matching how exercises were built.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The draft script maps a nutrient id or unit wrongly across every entry. | Ids and mapping rules are checked in step 1, and the hand-read values fixture fails on a wrong mapping. |
| USDA's usage terms aren't what we expect. | Step 1 confirmed CC0 1.0 before any data was committed. |
| The Peruvian table's terms don't allow reuse. | Step 1 checked them; they don't, so `food_table` was dropped. |
| `1050` (carbs by summation) is calculated differently from `1005`. | Step 1 confirmed it includes fiber. |
| Macro gaps in Foundation Foods. | The carbs and energy fallbacks, and rule 4 picks another entry or leaves the food out. |
| Reviewing about 200 entries plus Spanish takes time. | Each PR leaves the suite green, so the catalog is usable before the last one. The count check only lands at the end. |
| A later spec references the catalog without `ON DELETE RESTRICT`, and a removal hard-deletes a row it points at. | The rule is in "Rules for later specs" and `CLAUDE.md`. The seeder tests show the behaviour with a temporary referencing table. |
| A failed delete aborts the transaction on PostgreSQL. | Each delete runs in a savepoint. The tests run on MySQL, so a move to PostgreSQL has to keep the removal tests passing. |
| Hard-deleting an ingredient with many children is slow. | About 200 ingredients, and removals are rare. |

## What is **not** in this spec

- API endpoints (SPEC 16).
- Photos (SPEC 17).
- My foods, recipes, logging and meal plans.
- Prices and brands.
- Languages beyond English and Spanish.
- Frontend changes.
