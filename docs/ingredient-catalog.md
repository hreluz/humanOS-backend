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
  "aliases": {},
  "photos": [
    { "file": "Example_egg_whole.jpg", "license": "CC BY-SA 4.0", "license_url": "https://creativecommons.org/licenses/by-sa/4.0", "author": "Example Author" }
  ]
}
```

- Every value is **per 100 g**, liquids included. `kcal` is copied from the source, never derived from the macros.
- `key` is snake_case words and never starts with `u_`, the prefix of users' ingredient keys (SPEC 18).
- `source` is `usda_foundation` or `usda_sr_legacy`, and `source_id` is the food's FDC id as a string. The `user` source is only for users' ingredients.
- `fiber` is always set. The column is nullable for users' ingredients, whose labels may not list it, but a catalog entry never leaves it out.
- `nutrients` lists only what the source reports. A nutrient left out means "not measured"; `0` means "measured as zero".
- A portion's `position` is its order in the array. Its `grams` is the weight of the measure; there is no ml or density.
- `translations` holds the name (and each portion's label) in every language but English; only `es` is accepted for now. `aliases` holds other names to search by, in `en` or `es`.
- Keys are permanent. A renamed ingredient or portion key is a removal plus a new entry: the old row is hard-deleted, or soft-deleted if something references it.
- `photos` lists the Commons photos, main photo first (SPEC 17). `file` is the Commons file name without `File:`; any spelling Commons accepts works, and the seeder stores one canonical form. `author` and `license_url` are required unless the license is `CC0` or `Public domain`. Photos are rebuilt on every seed.
- The files hold system ingredients only (`user_id` `null`). The seeder never touches a user's ingredient, and rejects a key a user's ingredient already holds.

`IngredientSeeder` rejects the whole catalog, writing nothing, on a duplicate key or `source_id`, a missing `source_id` or macro, a duplicate portion key, an unknown category, source, nutrient or locale, the `user` source, a key starting with `u_`, a key held by a user's ingredient, or a photo problem: a license that isn't allowed, a missing author or license URL where the license requires one, an author over 255 characters, a `license_url` that isn't an `http` or `https` URL, more than three photos, or the same file twice on one ingredient.

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
10. **Photos** come from Wikimedia Commons, under `CC0`, `Public domain`, `CC BY` or `CC BY-SA`. Non-commercial and no-derivatives licenses aren't allowed. Every ingredient has one to three photos. The first is the main photo: it shows the food itself, recognisably, in the ingredient's form where possible. The others add something the first doesn't show, such as the food cut open, cooked, or in a common serving. Every photo shows the same food, never a different one. A cooked entry may use photos of the raw food when Commons has none of its own form. A variant may reuse photos of the same food. License, license URL and author come from the Commons API, never typed by hand. The author is the file's `Attribution` text when the uploader set one, and its `Artist` otherwise. An author longer than 255 characters is shortened by hand to an accurate credit.

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

## Adding photos

A one-off script, not committed, searches Commons for candidate files and reads each one's license (`LicenseShortName`), license URL (`LicenseUrl`) and author (`Attribution`, else `Artist`, HTML stripped) from the API (`prop=imageinfo&iiprop=extmetadata`). A person looks at every photo against rule 10, and its order, before it's committed. `php artisan ingredients:check-photos` then checks that every file still resolves.

## Sources and credits

- **Wikimedia Commons.** Each photo's author, license and file page are stored with it and returned by the API; clients must show them wherever they show the photo.
- **USDA FoodData Central.** U.S. Department of Agriculture, Agricultural Research Service. FoodData Central: Foundation Foods (October 2026) and SR Legacy (April 2018). fdc.nal.usda.gov. The data is in the public domain under CC0 1.0 Universal; USDA asks users to list FoodData Central as the source.

The Peruvian food composition table (CENAN/INS, 10th ed., 2017) was considered for foods USDA lacks and left out: its terms forbid reproduction without the Instituto Nacional de Salud's authorization.
