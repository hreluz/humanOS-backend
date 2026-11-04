# SPEC 17 — Ingredient photos

> **Status:** Draft
> **Depends on:** SPEC 15, SPEC 16
> **Date:** 2026-11-03
> **Objective:** Give every catalog ingredient one Wikimedia Commons photo reference with its license and credit, return it from the ingredient API, and add a command that checks every photo still resolves.

## Why this spec exists

Photos were part of SPEC 15. Finding, licensing and reviewing a photo for each of about 200 ingredients is separate work from copying nutrition values, and it shouldn't hold the catalog back. The check command also needs `NutritionServiceProvider` to register it, and that provider arrives in SPEC 16. So photos come after both.

## Scope

**In:**

- Photo columns on `ingredients` and the `photo_url` and `photo_credit_url` accessors on `Ingredient`.
- `services.ingredient_photos` config.
- An optional `photo` object in the SPEC 15 data files, validated and loaded by `IngredientSeeder`.
- A photo for every catalog ingredient, added to the existing data files.
- An `ingredients:check-photos` command, registered in `NutritionServiceProvider`.
- `photo` on both SPEC 16 endpoints, and `docs/openapi/v1.json` re-exported.
- The photo rule in `docs/ingredient-catalog.md`.

**Out of scope:**

- Hosting or mirroring images, resizing or caching them.
- More than one photo per ingredient.
- Photos for user-created ingredients (they don't exist yet; the columns allow it).
- Re-checking licenses after the import. The command checks that files resolve, not that their license is unchanged.
- Frontend changes.

## Data model

### Columns

A migration adds to `ingredients`, all nullable:

| Column | Type | Notes |
|---|---|---|
| `photo_file` | string, nullable | Commons file name without the `File:` prefix, e.g. `Avocado_Hass_-_single_and_halved.jpg` |
| `photo_license` | string, nullable | Commons `LicenseShortName`, e.g. `CC BY-SA 4.0` |
| `photo_author` | string, nullable | Credit text, plain text; `null` only for `CC0` and `Public domain` |

The columns are nullable so a future user-created ingredient can omit them. Only the seeded catalog requires a photo, as with exercise cues (SPEC 14).

### Accessors and config

```php
// config/services.php
'ingredient_photos' => [
    'base_url' => env('INGREDIENT_PHOTOS_BASE_URL', 'https://commons.wikimedia.org/wiki/Special:FilePath'),
    'width' => 640,
    'user_agent' => env('INGREDIENT_PHOTOS_USER_AGENT', 'humanOS/1.0 (https://github.com/hreluz/humanOS-backend)'),
],
```

- `Ingredient::photo_url` is `<base_url>/<file>?width=<width>`, with the file name URL-encoded (`rawurlencode`). It's `null` when `photo_file` is `null`.
- `Ingredient::photo_credit_url` is `https://commons.wikimedia.org/wiki/File:<file>`, encoded the same way. It's `null` when `photo_file` is `null`.
- Several ingredients may share a file. Grilled and boiled chicken breast can use one photo.

### Allowed licenses

A license is allowed if it matches one of:

- `CC0` (any version, e.g. `CC0 1.0`)
- `Public domain`
- `CC BY <version>`, optionally followed by a country port (`CC BY 2.0`, `CC BY 3.0 de`)
- `CC BY-SA <version>`, optionally followed by a country port

As a pattern: `^(CC0( \d\.\d)?|Public domain|CC BY(-SA)? \d\.\d( [a-z]{2,})?)$`. Licenses that forbid commercial use (`NC`) or derivatives (`ND`), and GFDL on its own, are not allowed. A file under both GFDL and a CC license stores the CC license.

The pattern lives in one place, `IngredientPhotoLicense::isAllowed()`, so the seeder and the catalog test can't disagree.

### Data files

Each entry in `database/data/ingredients/<category>.json` gets:

```json
"photo": { "file": "Example_egg.jpg", "license": "CC BY-SA 4.0", "author": "Example Author" }
```

The values show the format. They aren't data to copy.

### Seeder behaviour

`IngredientSeeder` keeps the SPEC 15 contract and adds:

- `photo` is optional. If it's absent, the three columns are set to `null`, so removing a photo from a file clears it on the next run.
- If `photo` is present, `file` and `license` are required, and `author` is required unless the license is `CC0` or `Public domain`.
- A license that isn't allowed, or a missing required field, throws and writes nothing.

## Catalog rule (`docs/ingredient-catalog.md`)

Added as rule 10:

> **Photos** come from Wikimedia Commons, under `CC0`, `Public domain`, `CC BY` or `CC BY-SA`. Non-commercial and no-derivatives licenses aren't allowed. A photo shows the food itself, recognisably, in the ingredient's form where possible. A variant may reuse a photo of the same food, but never of a different food. License and author come from the Commons API, never typed by hand.

## API

Both SPEC 16 endpoints gain `photo` on every ingredient:

```json
"photo": {
  "url": "https://commons.wikimedia.org/wiki/Special:FilePath/Example_egg.jpg?width=640",
  "license": "CC BY-SA 4.0",
  "author": "Example Author",
  "credit_url": "https://commons.wikimedia.org/wiki/File:Example_egg.jpg"
}
```

- `photo` is `null` when the ingredient has no `photo_file`. Seeded ingredients always have one.
- `author` is `null` for `CC0` and `Public domain`.
- Clients must show `author`, the license and a link to `credit_url` wherever they show the photo. Those licenses require credit.
- It's on the list too, and adds no query, because the columns are on `ingredients`.

This is an additive contract change, so SPEC 16's response shapes get updated in place when this spec is implemented, following the CLAUDE.md rule.

## Check command

`ingredients:check-photos`, modelled on `exercises:check-photos`:

- Sends a `HEAD` request to every distinct `photo_url`, following redirects, and lists each ingredient key whose photo fails with its status.
- Sends the configured `User-Agent` on every request, as the Wikimedia User-Agent policy requires.
- Runs at most 2 requests at a time with a 250 ms delay between them, to stay within Wikimedia's rate limits.
- Skips ingredients without a photo.
- Exits `0` when every photo resolves, `1` otherwise.

It's registered in `NutritionServiceProvider::boot()` with `$this->commands([...])`, like the Training module's command.

## Process

- A one-off script, not committed, reads candidate Commons files for each ingredient and takes their license (`LicenseShortName`) and author (`Artist`, with HTML stripped) from the Commons API (`prop=imageinfo&iiprop=extmetadata`).
- A person looks at every photo against rule 10 before it's committed.
- Each PR description lists the photos reused across variants, and any ingredient whose photo shows a form other than its own (e.g. a raw photo for a cooked entry).

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 16 is merged.

| PR | Steps |
|---|---|
| 1 | 1, 2, 3 (schema, seeder, command, API) |
| 2 to n | 4 (photos, a few categories each) |
| Last | 5 (catalog test, housekeeping) |

1. **Schema and seeder.** Add the migration, accessors, config and `IngredientPhotoLicense`. Extend `IngredientSeeder`. Tests in `IngredientSeederTest`, using fixture files:
   - an entry with a photo loads all three columns;
   - an entry without one loads `null`s, and removing a photo clears it on the next run;
   - a disallowed license (`CC BY-NC 4.0`, `CC BY-ND 4.0`, `GFDL`) throws and writes nothing;
   - a missing author throws, except under `CC0` and `Public domain`.
   - Unit tests for `IngredientPhotoLicense::isAllowed()` covering every form above, and for `photo_url` encoding a file name with spaces, commas and accents.
2. **Check command.** Add `ingredients:check-photos` and register it. Tests with `Http::fake()`: all succeed gives `0`; a 404 and a connection error give `1` and list both keys; the `User-Agent` header is sent; an ingredient without a photo is skipped.
3. **API.** Add `photo` to `IngredientResource`. Tests: every field above on both endpoints, `url` follows `config('services.ingredient_photos.*')`, and `photo` is `null` for an ingredient without one. Update SPEC 16's response examples, then `php artisan scramble:export --path=docs/openapi/v1.json`.
4. **Photo data.** Add `photo` to every entry, a few category files per PR. Check for each: the suite passes, and `ingredients:check-photos` exits `0`.
5. **Catalog test and housekeeping.** Extend `IngredientCatalogTest`: every entry has a photo, an allowed license, and an author unless the license is `CC0` or `Public domain`. Add rule 10 to `docs/ingredient-catalog.md`. Update `CLAUDE.md`. Run `vendor/bin/pint`.

The catalog test only requires photos in the last PR, so the batches in step 4 can land in any order.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] Every seeded ingredient has a photo with an allowed license, and an author unless the license is `CC0` or `Public domain`.
- [ ] `php artisan ingredients:check-photos` exits with `0`.
- [ ] `IngredientSeeder` rejects a disallowed license and writes nothing.
- [ ] Both endpoints return `photo` with its URL built from config, license, author and credit URL, or `null`.
- [ ] `docs/openapi/v1.json` is re-exported and SPEC 16's response shapes include `photo`.
- [ ] `docs/ingredient-catalog.md` has the photo rule.

## Decisions

- **Yes:** photos as a separate spec after SPEC 16. The nutrition catalog doesn't wait on photo sourcing, and the command has a provider to register in.
- **Yes:** Wikimedia Commons. It has the widest free coverage of foods, and each file states its license and author.
- **No:** Pexels or Unsplash, which are weaker on regional foods, and our own hosting, which adds storage the repo doesn't have (SPEC 08 also chose not to mirror images).
- **Yes:** one required photo per seeded ingredient, shared between variants. Finding a distinct free photo for every variant of a food isn't worth the work.
- **Yes:** photo columns on `ingredients`, not a table like `exercise_photos`. There's one photo per ingredient, and the file name is the only reference.
- **Yes:** license matched by a pattern that allows versions and country ports. Commons returns strings like `CC BY-SA 3.0 de`, and a fixed list would reject valid files.
- **Yes:** `photo` optional in the seeder, required by the catalog test. Photo batches land without breaking the suite, and the final state is still enforced.
- **Yes:** `Special:FilePath` with a width, not direct `upload.wikimedia.org` URLs. Those embed a hash path and a thumbnail size, and change if the file is re-uploaded.
- **No:** checking licenses in the command. A file's license rarely changes, and reading extmetadata for every file on every run is slow and rate-limited.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Commons renames or deletes a file, or limits hotlinking. | `ingredients:check-photos` lists broken files, the base URL comes from config, and a later move to our own hosting only changes `photo_url`. |
| A wrong or inaccurate credit breaks the license terms. | License and author come from the Commons API, and clients must show the credit. |
| Wikimedia blocks requests without a proper `User-Agent`, or rate-limits the check. | A descriptive `User-Agent` from config, at most 2 requests at a time, and a delay between them. |
| `Artist` metadata contains HTML or is empty. | The script strips HTML, and a person reviews every credit. An empty author under a CC BY license fails the seeder. |
| A photo shows the wrong food or form. | A person looks at every photo, and the PR lists reused and off-form photos. |

## What is **not** in this spec

- Hosted or mirrored images.
- Multiple photos per ingredient.
- Ongoing license re-checks.
- Frontend changes.
