# SPEC 17 — Ingredient photos

> **Status:** Approved
> **Depends on:** SPEC 15, SPEC 16
> **Date:** 2026-11-04
> **Note:** user ingredients, their keys and their source are defined by SPEC 18; photo uploads are still pending.
> **Objective:** Give every catalog ingredient an ordered list of one to three Wikimedia Commons photo references, each with its license and credit, return them from the ingredient API, and add a command that checks every photo still resolves.

## Why this spec exists

Photos were part of SPEC 15. Finding, licensing and reviewing photos for about 200 ingredients is separate work from copying nutrition values, and it shouldn't hold the catalog back. The check command also needs `NutritionServiceProvider` to register it, and that provider arrives in SPEC 16. So photos come after both.

One photo per ingredient is often not enough. A whole avocado doesn't show what a halved one looks like, and a raw chicken breast photo says little about the grilled entry. An ingredient can therefore have several photos, in display order, with the first one as its main photo.

A later spec lets users create their own ingredients and upload photos. This spec doesn't build that, but it shapes the tables for it now, while they hold only catalog data: `ingredients` gets an owner (`user_id`, `null` for the system catalog), and `ingredient_photos` stores a photo's source next to its path, so uploaded photos fit beside Commons ones without a rework.

## Scope

**In:**

- A nullable `user_id` on `ingredients`, `null` for system ingredients, and `IngredientSeeder` limited to system rows.
- An `ingredient_photos` table with a `source` per photo, an `IngredientPhotoSource` enum (`commons` only, for now), an `IngredientPhoto` model with `url`, `thumbnail_url` and `credit_url` accessors, and `Ingredient::photos()`.
- `services.ingredient_photos` config.
- An optional `photos` list in the SPEC 15 data files, validated and loaded by `IngredientSeeder`.
- One to three photos for every catalog ingredient, added to the existing data files.
- An `ingredients:check-photos` command, registered in `NutritionServiceProvider`.
- `photos` on both SPEC 16 endpoints, and `docs/openapi/v1.json` re-exported.
- The photo rule in `docs/ingredient-catalog.md`.

**Out of scope:**

- Hosting or mirroring images, resizing or caching them.
- Photo captions and dimensions (see "Decisions").
- User-created ingredients and photo uploads: the endpoints, storage, who sees which ingredient, and what a user's ingredient stores in `source` and `source_id`. A later spec adds them on top of the columns here (see "Ready for user ingredients").
- Re-checking licenses after the import. The command checks that files resolve, not that their license is unchanged.
- Frontend changes.

## Data model

### Ingredient owner

```php
// database/migrations/<date>_add_user_id_to_ingredients_table.php
Schema::table('ingredients', function (Blueprint $table) {
    $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
});
```

- `user_id` is `null` for system ingredients, the catalog loaded from the data files. Every row seeded today stays `null`.
- `cascadeOnDelete`: deleting a user deletes their ingredients. Never `nullOnDelete`, which would turn a user's private ingredient into a system one.
- `Ingredient::user(): BelongsTo` and `Ingredient::scopeSystem()` (`whereNull('user_id')`).
- `source` and `source_id` keep their SPEC 15 definition. The user-ingredients spec decides what a user's ingredient stores there.
- Search and detail don't filter by `user_id` yet: no user rows exist until that spec, which decides who sees which ingredient.

### Photo table

```php
// database/migrations/<date>_create_ingredient_photos_table.php
Schema::create('ingredient_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->string('source', 16);                 // IngredientPhotoSource: `commons` for now, `upload` later
    $table->string('path');                       // for `commons`, the file name without the `File:` prefix
    $table->string('license', 32)->nullable();    // Commons `LicenseShortName`, e.g. `CC BY-SA 4.0`; required for `commons`
    $table->string('license_url')->nullable();    // Commons `LicenseUrl`; required for CC BY and CC BY-SA
    $table->string('author')->nullable();         // plain-text credit, at most 255 characters; `null` only for `CC0` and `Public domain`
    $table->unsignedTinyInteger('position');      // 1-based display order; 1 is the main photo
    $table->unique(['ingredient_id', 'position']);
    $table->unique(['ingredient_id', 'source', 'path']);
    $table->timestamps();
});
```

- `enum IngredientPhotoSource: string { case Commons = 'commons'; }`. The uploads spec adds `Upload`, with `path` as the file's path on the storage disk.
- A photo belongs to whoever owns its ingredient, so the table has no `user_id`. System ingredients only ever have `commons` photos. Users will upload photos only to their own ingredients, never to system ones.
- For `commons`, `path` is e.g. `Avocado_Hass_-_single_and_halved.jpg`, always in the canonical form below.

**Canonical file names.** On Commons, spaces and underscores in a file name mean the same thing, and the first letter's case doesn't matter. So `Avocado Hass.jpg`, `Avocado_Hass.jpg` and `avocado_Hass.jpg` are one file. `IngredientPhoto::canonicalFile()` turns any of them into one spelling: it removes a leading `File:`, trims, turns spaces into underscores, merges repeated underscores into one, and uppercases the first character (multibyte-safe). The seeder stores that form, and duplicates are found by comparing it, so two spellings of one file count as a repeat.
- `license` is nullable in the table because a user's own uploaded photo won't have one. The seeder still requires it for every `commons` photo.
- `license_url` is e.g. `https://creativecommons.org/licenses/by-sa/4.0`. It's stored as Commons returns it, never built from the short name. `CC0` files usually have one too and keep it; `Public domain` files often don't.
- `cascadeOnDelete` on `ingredient_id`: nothing references a photo, so photos go with their ingredient. A soft-deleted ingredient keeps its photos.
- The same file may appear on several ingredients. Grilled and boiled chicken breast can share one. It can't appear twice on the same ingredient.

### Models and config

```php
// config/services.php
'ingredient_photos' => [
    'base_url' => env('INGREDIENT_PHOTOS_BASE_URL', 'https://commons.wikimedia.org/wiki/Special:FilePath'),
    'width' => 960,           // detail view; must be a Wikimedia standard width
    'thumbnail_width' => 330, // lists and cards; must be a Wikimedia standard width
    'user_agent' => env('INGREDIENT_PHOTOS_USER_AGENT', 'humanOS/1.0 (https://github.com/hreluz/humanOS-backend)'),
],
```

`INGREDIENT_PHOTOS_BASE_URL` and `INGREDIENT_PHOTOS_USER_AGENT` go into both `.env.example` and `.env.development`. The widths aren't env variables.

**Standard widths.** Wikimedia serves thumbnails at a fixed set of widths and rate-limits the rest, answering `429` once the limit is hit. `IngredientPhoto::STANDARD_WIDTHS` holds the list (20, 40, 60, 120, 250, 330, 500, 960, 1280, 1920 and 3840; see [Common thumbnail sizes](https://www.mediawiki.org/wiki/Common_thumbnail_sizes)), and a test fails if `width` or `thumbnail_width` isn't in it.

- `Ingredient::photos(): HasMany`, ordered by `position`.
- `IngredientPhoto::ingredient(): BelongsTo`. Casts `source` to `IngredientPhotoSource`.
- The URL accessors branch on `source` with a `match`, so the uploads spec adds one arm per accessor. For `commons`:
  - `IngredientPhoto::url` is `<base_url>/<path>?width=<width>`, with the file name URL-encoded (`rawurlencode`).
  - `IngredientPhoto::thumbnail_url` is the same with `<thumbnail_width>`.
  - `IngredientPhoto::credit_url` is `https://commons.wikimedia.org/wiki/File:<path>`, encoded the same way.

### Allowed licenses

A license is allowed if it matches one of:

- `CC0` (any version, e.g. `CC0 1.0`)
- `Public domain`
- `CC BY <version>`, optionally followed by a country port (`CC BY 2.0`, `CC BY 3.0 de`)
- `CC BY-SA <version>`, optionally followed by a country port

As a pattern: `^(CC0( \d\.\d)?|Public domain|CC BY(-SA)? \d\.\d( [a-z]{2,})?)$`. Licenses that forbid commercial use (`NC`) or derivatives (`ND`), and GFDL on its own, are not allowed. A file under both GFDL and a CC license stores the CC license.

The pattern lives in one place, `IngredientPhotoLicense::isAllowed()`, so the seeder and the catalog test can't disagree. `IngredientPhotoLicense::requiresAttribution()` is `false` for `CC0` and `Public domain` and `true` otherwise. When it's `true`, `author` and `license_url` are required.

### Data files

Each entry in `database/data/ingredients/<category>.json` gets a `photos` list, main photo first:

```json
"photos": [
  {
    "file": "Example_avocado_whole.jpg",
    "license": "CC BY-SA 4.0",
    "license_url": "https://creativecommons.org/licenses/by-sa/4.0",
    "author": "Example Author"
  },
  { "file": "Example_avocado_halved.jpg", "license": "CC0 1.0", "license_url": "https://creativecommons.org/publicdomain/zero/1.0/deed.en" }
]
```

The values show the format. They aren't data to copy. Every photo in the data files is a `commons` photo, so the files have no `source` field; the seeder sets it.

### Seeder behaviour

`IngredientSeeder` keeps the SPEC 15 contract, limited to system ingredients:

- It only upserts and removes rows with `user_id` `null`. The final `CatalogRemover::remove(...)` runs on `Ingredient::system()->whereNotIn('key', $keys)`, so a user's ingredient survives every seed.
- A key in the files that belongs to a user's ingredient throws and writes nothing, instead of the upsert taking over that row. The user-ingredients spec generates keys that can't clash with catalog keys.

For photos, it adds:

- `photos` is optional. Absent and `[]` mean the same: the ingredient has no photos.
- If present, it's a list of at most 3 items. Each has `file` and `license`. `author` and `license_url` are required when the license requires attribution, and optional otherwise. `file` is stored in `path` in its canonical form (`IngredientPhoto::canonicalFile()`), so the data files may use either spelling. A file can't repeat within one ingredient, compared in canonical form.
- `author` is at most 255 characters. A longer one isn't cut short: the person reviewing writes a shorter, accurate credit in the file.
- `license_url`, when present, is an `http` or `https` URL, stored as Commons returns it (many older licenses come back as `http://`).
- A license that isn't allowed, a missing required field, an author over 255 characters, a `license_url` that isn't an `http` or `https` URL, more than 3 photos or a repeated file throws and writes nothing.
- For every ingredient in the files, it deletes all its photos and inserts them again from `photos`, with `source` `commons` and `position` from the list order. That's safe because system ingredients only have `commons` photos. Removing a photo, or every photo, from a file clears it on the next run. Photo ids are not stable; nothing references them.
- Ingredients removed from the files are handled by `CatalogRemover` as today. A hard delete takes the photos with it; a soft-deleted ingredient keeps them.

## Catalog rule (`docs/ingredient-catalog.md`)

Added as rule 10:

> **Photos** come from Wikimedia Commons, under `CC0`, `Public domain`, `CC BY` or `CC BY-SA`. Non-commercial and no-derivatives licenses aren't allowed. Every ingredient has one to three photos. The first is the main photo: it shows the food itself, recognisably, in the ingredient's form where possible. The others add something the first doesn't show, such as the food cut open, cooked, or in a common serving. Every photo shows the same food, never a different one. A cooked entry may use photos of the raw food when Commons has none of its own form. A variant may reuse photos of the same food. License, license URL and author come from the Commons API, never typed by hand. The author is the file's `Attribution` text when the uploader set one, and its `Artist` otherwise. An author longer than 255 characters is shortened by hand to an accurate credit.

## API

Both SPEC 16 endpoints gain `photos` on every ingredient, in `position` order:

```json
"photos": [
  {
    "url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_avocado_whole.jpg?width=960",
    "thumbnail_url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_avocado_whole.jpg?width=330",
    "license": "CC BY-SA 4.0",
    "license_url": "https://creativecommons.org/licenses/by-sa/4.0",
    "author": "Example Author",
    "credit_url": "https://commons.wikimedia.org/wiki/File:Example_avocado_whole.jpg"
  }
]
```

- `photos` is always a list, `[]` when the ingredient has none. Seeded ingredients always have at least one. The first item is the main photo, for lists and thumbnails.
- `thumbnail_url` is for lists and cards, `url` for the detail view. Clients use them as given and never build Commons URLs themselves, so a later move to our own hosting doesn't change them.
- `author` is `null` for `CC0` and `Public domain`. `license_url` may be `null` for them too, never for `CC BY` or `CC BY-SA`.
- Clients must show `author`, the license (linked to `license_url` when set) and a link to `credit_url` wherever they show a photo. Those licenses require credit.
- `position`, `source` and the file name aren't returned; the list order is the position.
- `license`, `license_url`, `author` and `credit_url` are documented as nullable in the OpenAPI document, even though every `commons` photo has a `license` and `credit_url`. A user's uploaded photo will have none of them, and making a field nullable later would be a breaking change for the generated clients.
- The list eager-loads `photos` next to `portions`, so the search goes from two queries to three, whatever the number of matches. The detail endpoint adds one query too.

This is an additive contract change, so SPEC 16's response shapes and its query count get updated in place when this spec is implemented, following the CLAUDE.md rule.

## Check command

`ingredients:check-photos` (`app/Modules/Nutrition/Console/CheckIngredientPhotosCommand.php`), modelled on `exercises:check-photos`:

- Checks `commons` photos only. Sends a `HEAD` request to every distinct `IngredientPhoto::url`, following redirects. A file shared by several ingredients is requested once. `thumbnail_url` isn't checked: it's the same file at another standard width.
- For each URL that fails, prints the file, the status (or the connection error), and every ingredient key that uses it.
- Sends the configured `User-Agent` on every request, as the Wikimedia User-Agent policy requires.
- Runs at most 2 requests at a time with a 250 ms delay between them, to stay within Wikimedia's rate limits.
- Exits `0` when every photo resolves, `1` otherwise. With no photos at all, it exits `0`.

It's registered in `NutritionServiceProvider::boot()` with `$this->commands([...])`, like the Training module's command.

## Ready for user ingredients

What the later spec, user-created ingredients with photo uploads, finds in place and what it still adds:

| Already here (SPEC 17) | Added by the later spec |
|---|---|
| `ingredients.user_id`, `null` for system ingredients, deleted with the user | Endpoints to create, edit and delete a user's ingredients, and the rule for who sees which ingredient in search and detail |
| The seeder only touches system rows, and rejects a key a user's ingredient holds | Generated keys for user ingredients that can't clash with catalog keys |
| `source` and `source_id` unchanged | What a user's ingredient stores there (e.g. a `user` source and a nullable `source_id`) |
| `ingredient_photos.source`, with `path` and nullable license fields | `IngredientPhotoSource::Upload`, storage, upload validation (types, size, EXIF stripping) and the `upload` arm of each URL accessor |
| Photos owned through their ingredient; system ingredients only have `commons` photos, and the seeder rebuilds all of them | Uploads only to the user's own ingredients, never to system ones. Allowing uploads to system ingredients would need a `user_id` on photos and a seeder that leaves them alone |
| `user_id` deletes a user's ingredients with the user | Deleting the user's meal logs, recipes and other rows that reference those ingredients **before** the ingredients. Those references are `ON DELETE RESTRICT` (CLAUDE.md), and MySQL doesn't guarantee the order of cascades, so a plain cascade from `users` can fail |
| `license`, `license_url`, `author` and `credit_url` nullable in the API contract | Nothing: uploaded photos return them as `null` |
| Portions, nutrients, aliases and translations keyed by ingredient id | Nothing: they work the same for a user's ingredient |

## Process

- A one-off script, not committed, reads candidate Commons files for each ingredient and takes their license (`LicenseShortName`), license URL (`LicenseUrl`) and author from the Commons API (`prop=imageinfo&iiprop=extmetadata`). The author is `Attribution` when it's set, since under CC BY and CC BY-SA a credit text the licensor asks for should be used, and `Artist` otherwise. HTML is stripped from both.
- A person looks at every photo against rule 10, and checks the order (main photo first), before it's committed. An author over 255 characters is shortened by hand.
- Each PR description lists the photos reused across variants, and any ingredient whose main photo shows a form other than its own (e.g. a raw photo for a cooked entry).

## Files

```
app/Modules/Nutrition/
  Console/CheckIngredientPhotosCommand.php
  Enums/IngredientPhotoSource.php
  Http/Resources/IngredientPhotoResource.php
  Models/IngredientPhoto.php
  Models/Ingredient.php                     (user and photos relations, system scope)
  Support/IngredientPhotoLicense.php
  NutritionServiceProvider.php              (registers the command)
config/services.php                         (ingredient_photos)
.env.example, .env.development              (INGREDIENT_PHOTOS_* variables)
database/migrations/<date>_add_user_id_to_ingredients_table.php
database/migrations/<date>_create_ingredient_photos_table.php
database/seeders/IngredientSeeder.php
specs/15-ingredient-catalog.md               (header note)
database/data/ingredients/*.json
docs/ingredient-catalog.md
docs/openapi/v1.json
tests/Unit/Nutrition/IngredientPhotoLicenseTest.php
tests/Feature/Nutrition/IngredientPhotoTest.php
tests/Feature/Nutrition/CheckIngredientPhotosCommandTest.php
```

Plus additions to the existing `IngredientSeederTest`, `IngredientCatalogTest` and SPEC 16 endpoint tests.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-16-ingredient-api` (the work lives on `spec-17-ingredient-photos`), and rebase onto `master` once SPEC 15 and 16 are merged. Until then, SPEC 17's PRs target `spec-16-ingredient-api`, so their diff shows only this spec.

| PR | Steps |
|---|---|
| 1 | 1, 2, 3 (schema, seeder, command, API) |
| 2 to n | 4 (photos, a few categories each) |
| Last | 5 (catalog test, housekeeping) |

1. **Schema and seeder.** Add both migrations, `IngredientPhotoSource`, `IngredientPhoto`, `Ingredient::user()`, `Ingredient::photos()` and `Ingredient::scopeSystem()`, the config and env variables, and `IngredientPhotoLicense`. Extend `IngredientSeeder`. Tests in `IngredientSeederTest`, using fixture files:
   - seeded ingredients have `user_id` `null`;
   - an ingredient owned by a user, with a key not in the files, is still there, not soft-deleted, after a seed;
   - a key in the files that belongs to a user's ingredient throws and writes nothing;
   - deleting a user deletes their ingredients and leaves system ones;
   - an entry with two photos loads both rows with every column, `source` `commons`, in order (`position` 1 and 2);
   - an entry without `photos`, or with `[]`, loads none;
   - reordering, removing one photo, or removing them all is reflected on the next run;
   - a disallowed license (`CC BY-NC 4.0`, `CC BY-ND 4.0`, `GFDL`) throws and writes nothing;
   - a missing author or `license_url` throws under `CC BY` and `CC BY-SA`, and not under `CC0` and `Public domain`;
   - an author of 256 characters, or a `license_url` that isn't an `http` or `https` URL, throws and writes nothing, and an `http` one is stored as given;
   - four photos, or the same file twice on one ingredient, throws and writes nothing, including when the two are spelled differently (`Avocado Hass.jpg` and `avocado_Hass.jpg`);
   - a file written with spaces, a lowercase first letter or a `File:` prefix is stored in canonical form;
   - the same file on two ingredients loads on both;
   - hard-deleting an ingredient deletes its photos.
   - Unit tests for `IngredientPhotoLicense::isAllowed()` and `requiresAttribution()` covering every form above. In `IngredientPhotoTest`: `canonicalFile()` handles spaces, repeated underscores, a `File:` prefix, and a lowercase or accented first letter (`école.jpg` → `École.jpg`); `url`, `thumbnail_url` and `credit_url` encode a file name with spaces, commas and accents; `url` and `thumbnail_url` follow `config('services.ingredient_photos.*')`; and the configured `width` and `thumbnail_width` are both in `IngredientPhoto::STANDARD_WIDTHS`.
   - Check: `php artisan migrate` adds `ingredients.user_id` and creates `ingredient_photos`, and `migrate:rollback` drops both.
2. **Check command.** Add `ingredients:check-photos` and register it. Tests with `Http::fake()`:
   - all succeed gives `0`;
   - a 404 and a connection error give `1` and print both files with their ingredient keys;
   - a file shared by two ingredients is requested once and, when it fails, lists both keys;
   - only `url` is requested, not `thumbnail_url`;
   - the `User-Agent` header is sent on every request;
   - no photos at all gives `0` and sends nothing.
3. **API.** Add `IngredientPhotoResource` and `photos` to `IngredientResource`, and eager-load `photos` on both endpoints. Tests:
   - every field above on both endpoints, in `position` order;
   - `author` is `null` for a `CC0` photo, and `license_url` is `null` when none is stored;
   - `photos` is `[]` for an ingredient without any;
   - the search runs three queries whether it matches 5 ingredients or 15;
   - the exported document marks `license`, `license_url`, `author` and `credit_url` as nullable.
   - Update SPEC 16's response examples and its query count, then `php artisan scramble:export --path=docs/openapi/v1.json`.
4. **Photo data.** Add `photos` to every entry, a few category files per PR. Check for each: the suite passes, and `ingredients:check-photos` exits `0`.
5. **Catalog test and housekeeping.** Extend `IngredientCatalogTest`: every entry has one to three photos, each with an allowed license, and an author and `license_url` unless the license is `CC0` or `Public domain`. No file repeats within an entry, compared with `IngredientPhoto::canonicalFile()`. Add rule 10 to `docs/ingredient-catalog.md`. Update `CLAUDE.md`, including that `user_id` `null` means a system ingredient and that the seeder only touches those. Add to SPEC 15's header: `> **Note:** ingredients gain a nullable user_id (null for system ingredients), and IngredientSeeder only touches system rows, by SPEC 17.` Run `vendor/bin/pint`.

The catalog test only requires photos in the last PR, so the batches in step 4 can land in any order.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `php artisan migrate` adds `ingredients.user_id` and creates `ingredient_photos`, and rolling back removes both.
- [ ] Seeding leaves users' ingredients untouched, and rejects a key a user's ingredient holds.
- [ ] Deleting a user deletes their ingredients and no system ones.
- [ ] Every seeded ingredient has one to three photos, each with an allowed license, and an author and license URL unless the license is `CC0` or `Public domain`.
- [ ] `php artisan ingredients:check-photos` exits with `0`.
- [ ] `IngredientSeeder` rejects a disallowed license, a missing author or license URL, an author over 255 characters, more than three photos and a repeated file, and writes nothing.
- [ ] Both endpoints return `photos` in order, each with `url` and `thumbnail_url` built from config, license, license URL, author and credit URL, or `[]`.
- [ ] `width` and `thumbnail_width` are Wikimedia standard widths, enforced by a test.
- [ ] The search runs three queries whatever the number of matches.
- [ ] `docs/openapi/v1.json` is re-exported and SPEC 16's response shapes include `photos`.
- [ ] `INGREDIENT_PHOTOS_BASE_URL` and `INGREDIENT_PHOTOS_USER_AGENT` are in both `.env.example` and `.env.development`.
- [ ] `docs/ingredient-catalog.md` has the photo rule.

## Decisions

- **Yes:** photos as a separate spec after SPEC 16. The nutrition catalog doesn't wait on photo sourcing, and the command has a provider to register in.
- **Yes:** Wikimedia Commons. It has the widest free coverage of foods, and each file states its license and author.
- **No:** Pexels or Unsplash, which are weaker on regional foods, and our own hosting, which adds storage the repo doesn't have (SPEC 08 also chose not to mirror images).
- **Yes:** an ordered list of photos per ingredient, in an `ingredient_photos` table like `exercise_photos`. One photo can't show a food whole, cut and cooked, and columns on `ingredients` would cap the count at one.
- **Yes:** shape the tables for user ingredients and uploads now, without building either. Changing `ingredient_photos` after thousands of catalog rows are in it means a data migration; a column and an enum now cost almost nothing.
- **Yes:** a nullable `user_id` on `ingredients`, `null` for system ingredients, instead of a separate table for users' ingredients. Meal logs, recipes and portions then reference one table, and a user's ingredient works everywhere a catalog one does.
- **Yes:** `cascadeOnDelete` on `user_id`, never `nullOnDelete`. A user's private ingredient must never become a system one.
- **Yes:** the seeder only touches system rows and rejects a key held by a user's ingredient. Without that, the next seed would delete or take over users' ingredients without any warning.
- **Yes:** users upload photos only to their own ingredients. A photo's owner is its ingredient's owner, so photos need no `user_id`, and the seeder can rebuild every photo of a system ingredient without clashing with a user's upload. A user who wants their own photo of a catalog food creates their own ingredient.
- **Yes:** the credit is Commons' `Attribution` when it's set, `Artist` otherwise, in the one `author` field. It matches the credit the licensor asked for, without a second field for clients.
- **Yes:** the seeder writes Commons file names in one canonical form instead of rejecting other spellings. The data files can be written loosely, and duplicates and the check command still see one file.
- **No:** changing `source`, `source_id` or search visibility here. They depend on how user ingredients work, and no user rows exist until that spec.
- **Yes:** a `source` per photo and nullable license fields, in the table and in the API contract. Uploaded photos fit without a migration, and the generated clients already handle `null`.
- **Yes:** one to three photos per seeded ingredient. One keeps sourcing feasible for every entry; three is enough to show the food's main forms without turning the review into a gallery.
- **Yes:** the first photo is the main one. Lists and thumbnails need one photo without a separate flag, and the order is already stored.
- **Yes:** a cooked entry may use raw photos of the same food. Free photos of cooked forms are scarce, and requiring one would leave some entries without a valid photo. The PR lists these cases.
- **No:** captions. They would need translating once the language spec lands, and what a photo shows is clear from the photo. A later spec can add a nullable column.
- **No:** photo dimensions or aspect ratio. No client needs them yet, and the Commons API can supply them for a later spec.
- **Yes:** only Wikimedia standard widths, checked by a test. Other widths are rate-limited and can answer `429`, and the test stops a non-standard width from slipping in later.
- **Yes:** two URLs per photo, `thumbnail_url` (330 px) and `url` (960 px). Lists don't download detail-size images, the detail view stays sharp on high-density screens, and clients never build Commons URLs themselves.
- **Yes:** `license_url` stored from the Commons API. CC BY and CC BY-SA attribution should link to the license, and building the URL from the short name means mapping every version and country port by hand.
- **Yes:** an author over 255 characters fails the seeder instead of being cut short or stored as `text`. The credit is a license requirement, so a person writes a short, accurate one.
- **Yes:** the seeder rebuilds each ingredient's photos, as `ExerciseSeeder` does. Nothing references photo ids, and rebuilding is simpler than diffing.
- **Yes:** variants may share photos. Finding distinct free photos for every variant of a food isn't worth the work.
- **Yes:** license matched by a pattern that allows versions and country ports. Commons returns strings like `CC BY-SA 3.0 de`, and a fixed list would reject valid files.
- **Yes:** `photos` optional in the seeder, required by the catalog test. Photo batches land without breaking the suite, and the final state is still enforced.
- **Yes:** `Special:FilePath` with a width, not direct `upload.wikimedia.org` URLs. Those embed a hash path and a thumbnail size, and change if the file is re-uploaded.
- **Yes:** `photos` on the list endpoint, at the cost of one more query. Search results need a thumbnail, and the query count stays constant.
- **No:** checking licenses in the command. A file's license rarely changes, and reading extmetadata for every file on every run is slow and rate-limited.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Commons renames or deletes a file, or limits hotlinking. | `ingredients:check-photos` lists broken files and the ingredients using them, the base URL comes from config, and a later move to our own hosting only changes the `IngredientPhoto` URL accessors. |
| Wikimedia changes its list of standard widths, and our widths start being rate-limited. | Both widths live in config and `STANDARD_WIDTHS` in one constant, so a change is one edit plus the test. |
| A wrong or inaccurate credit breaks the license terms. | License, license URL and author come from the Commons API, and clients must show the credit. |
| Wikimedia blocks requests without a proper `User-Agent`, or rate-limits the check. | A descriptive `User-Agent` from config, distinct URLs only, at most 2 requests at a time, and a delay between them. Up to three photos per ingredient means up to about 600 requests, a few minutes per run. |
| `Artist` or `Attribution` metadata contains HTML, is empty or is too long. | The script strips HTML and prefers `Attribution`, and a person reviews every credit. An empty author under a CC BY license, or one over 255 characters, fails the seeder. |
| A photo shows the wrong food or form, or the main photo isn't the best one. | A person looks at every photo and its order, and the PR lists reused and off-form main photos. |

## What is **not** in this spec

- User-created ingredients and photo uploads (only the tables are ready).
- Hosted or mirrored images.
- Photo captions and dimensions.
- More than three photos per ingredient.
- Ongoing license re-checks.
- Frontend changes.
