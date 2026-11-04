# Ingredient catalog

Rules for adding or changing ingredients in `database/data/ingredients/` (SPEC 15).

The files are the source of truth for the ingredient catalog. Only `IngredientSeeder` and the Nutrition tests read them; the app reads the database. To change the catalog, edit a file and reseed (`php artisan db:seed --class=IngredientSeeder`). A direct database edit is overwritten on the next seed.

## Files

One file per category, named after its key (`eggs.json`, `plant_protein.json`, …), each a JSON array sorted by `name`. A `key` and a `source_id` are unique across all files. A category with no ingredients has no file (`supplements` may end up empty).

The 29 nutrients beyond the macros are listed in `NutrientSeeder`, which runs before `IngredientSeeder`.

## Entry format

An entry, with its nutrients and portions shortened:

```json
{
  "key": "egg_whole_raw",
  "name": "Egg, whole, raw",
  "category": "eggs",
  "source": "usda_foundation",
  "source_id": "748967",
  "kcal": 148, "protein": 12.4, "carbs": 0.96, "fat": 9.96, "fiber": 0,
  "nutrients": { "cholesterol": 411, "selenium": 31.1 },
  "portions": [
    { "key": "large", "label": "1 large egg", "grams": 50, "translations": { "es": "1 huevo grande" } }
  ],
  "translations": { "es": "Huevo entero, crudo" },
  "aliases": {}
}
```

- Every value is **per 100 g**, liquids included. `kcal` is copied from the source, never derived from the macros.
- `source` is `usda_foundation` or `usda_sr_legacy`, and `source_id` is the food's FDC id as a string.
- `nutrients` lists only what the source reports. A nutrient left out means "not measured"; `0` means "measured as zero".
- A portion's `position` is its order in the array. Its `grams` is the weight of the measure; there is no ml or density.
- `translations` holds the name (and each portion's label) in every language but English; only `es` is accepted for now. `aliases` holds other names to search by, in `en` or `es`.
- Keys are permanent. A renamed ingredient or portion key is a removal plus a new entry: the old row is hard-deleted, or soft-deleted if something references it.

`IngredientSeeder` rejects the whole catalog, writing nothing, on a duplicate key or `source_id`, a missing `source_id` or macro, a duplicate portion key, an unknown category, source, nutrient or locale.

## Rules

1. **Include** about 200 basic generic ingredients sold almost anywhere in the world. Foods usually eaten cooked get one raw (or as bought) and one common cooked form, and foods eaten as bought (fruit, nuts, oils, cheese, spices) get one entry. Canned or frozen forms only for foods mostly bought that way.
2. **Exclude** restaurant and fast-food items, baby food and infant formula, prepared dishes, branded products, processed meats beyond bacon and ham, juices and dressings beyond a few basics, regional foods not sold widely, rare and game species, organ meats beyond liver, lean-only and fat-level variants, rare cuts, and near-duplicates.
3. **USDA only.** Foundation Foods wins over SR Legacy for the same food. A food USDA lacks is left out.
4. **Required macros.** If fiber is missing, foods that contain none (meat, eggs, fish, dairy, oils, sugar, alcoholic drinks) get `0`. Otherwise, choose another entry for that food or leave it out. A negative value (carbohydrate by difference can come out below zero) counts as unusable in the same way.
5. **Names** are rewritten to be readable, keeping the preparation: `Chicken breast, roasted`, not USDA's long form. No two ingredients share a name in English or in Spanish.
6. **Category** is one of the 13 keys, chosen by how a person shops for the food. Potatoes, sweet potatoes and cassava are `grains` (the frontend's "Grains & starches"); nuts, seeds and nut butters are `fats` ("Fats & nuts"); plant milks, drinks, coffee and chocolate are `other`.
7. **Portions** come from USDA's measures, with the grams copied. A Foundation entry may take its measures from the SR Legacy entry for the same food, since Foundation lists few household measures. An ingredient may have none, because grams always work: measures in ounces, pounds, packages and USDA's reference amounts (RACC, NLEA serving) are left out. Each portion has a short snake_case `key` describing the measure (`large`, `cup_chopped`). Keys aren't renamed once committed.
8. **Spanish** names are neutral and widely understood. Regional words go in `aliases` (`palta` under Avocado, `choclo` under Sweet corn). English alternatives go there too (`coriander leaves` under Cilantro).
9. **Nutrition values are copied, never estimated.** They are stored at the column's precision: 2 decimals for `kcal` and `grams`, 3 for macros and nutrients.

## Source mapping

How the values are read from FoodData Central (checked in SPEC 15, step 1):

| Field | FDC nutrient id |
|---|---|
| `protein`, `fat`, `fiber` | `1003`, `1004`, `1079` |
| `carbs` | `1005` (by difference), else `1050` (by summation; it includes fiber) |
| `kcal` | `1008`, else `2048` (Atwater specific), else `2047` (Atwater general) |
| `sugars` | `2000`, else `1063` (Foundation Foods' total sugars) |
| `folate` | `1190` (DFE) only; Foundation Foods doesn't report it, so its entries have no folate |
| Other nutrients | The FDC id in `NutrientSeeder` |

Foundation Foods is read from its JSON download, because its CSV download has no food, nutrient value or portion files.

## Adding ingredients

A one-off script, not committed, reads the pinned USDA downloads, copies the values by the mapping above and flags near-duplicates and any entry whose `kcal` is far from 4/4/9. Foods high in fiber or acids (spices, cocoa, lemon, vinegar) and alcoholic drinks are flagged as expected: their energy isn't 4/4/9.

Names, category, inclusion, Spanish names, aliases, portion keys and labels may be drafted with an LLM, and a person reads every one against the rules before it's committed. Nutrition values are never typed in.

`IngredientCatalogTest` checks the files against these rules and against `tests/Fixtures/ingredient-values.json`: values read from FoodData Central's own pages or API for a few foods in every category. When you add a category's first ingredient, add 2–3 entries for it to the fixture.

## Sources and credits

- **USDA FoodData Central.** U.S. Department of Agriculture, Agricultural Research Service. FoodData Central: Foundation Foods (October 2026) and SR Legacy (April 2018). fdc.nal.usda.gov. The data is in the public domain under CC0 1.0 Universal; USDA asks users to list FoodData Central as the source.

The Peruvian food composition table (CENAN/INS, 10th ed., 2017) was considered for foods USDA lacks and left out: its terms forbid reproduction without the Instituto Nacional de Salud's authorization.
