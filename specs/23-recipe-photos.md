# SPEC 23 — Recipe photos

> **Status:** Draft
> **Depends on:** SPEC 19, SPEC 22
> **Date:** 2026-11-05
> **Objective:** Let a user upload, reorder and delete up to three photos of their own recipes, using the SPEC 22 upload pipeline, with the main photo shown in the recipe list.

## Why this spec exists

A recipe card without a picture is a line of text. SPEC 22 built the upload groundwork (the `photos` disk, validation, WebP re-encoding, file cleanup) for ingredients, and said recipe photos would reuse it. This spec adds only the recipe side.

## Scope

**In:**

- A `recipe_photos` table.
- `POST`, `PATCH` (reorder) and `DELETE` for a recipe's photos, at most 3 per recipe, with the same rules as SPEC 22.
- `photos` on the recipe detail, and the main `photo` on each list item.
- File removal when a photo, its recipe or its user is deleted.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Photos on steps.
- A photo on a sub-recipe line (SPEC 20). Clients get it from the sub-recipe's own detail.
- More than 3 photos.
- Everything SPEC 22 leaves out: queued processing, cropping, moderation, a prune command, quotas and rate limiting.
- Frontend changes.

## Data model

### Migration

```php
// database/migrations/<date>_create_recipe_photos_table.php
Schema::create('recipe_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
    $table->string('key', 11);
    $table->string('path');
    $table->unsignedTinyInteger('position');
    $table->timestamps();
    $table->unique(['recipe_id', 'key']);
    $table->unique(['recipe_id', 'position']);
});
```

- `key` is `ph_` + 8 random lowercase letters and digits, as in SPEC 22.
- `path` is `recipes/<recipe key>/<photo key>`. Files are `-960.webp` and `-330.webp` on the `photos` disk.
- `position` is 1–3, with 1 the main photo, renumbered in two passes after every delete and reorder (SPEC 22).
- No `source` or credit columns. Every recipe photo is an upload.

### Model

- `app/Modules/Nutrition/Models/RecipePhoto.php` with `url` and `thumbnail_url` from `Storage::disk('photos')`.
- `Recipe::photos()`: `hasMany(RecipePhoto)` ordered by `position`.
- `Recipe::mainPhoto()`: `hasOne(RecipePhoto)` with `position` 1.
- The key generation and two-pass renumbering are shared with `IngredientPhoto` through a trait, `Models/Concerns/HasOrderedPhotos`.

## Endpoints

All of them require a bearer token (401 otherwise) and live under `api/v1/nutrition`. `{key}` resolves through SPEC 19's binding, so another user's recipe is a 404. There's no 403 case, since every recipe is private.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/recipes/{key}/photos` | `201`, the recipe detail shape | 404, 422 |
| `PATCH` | `/recipes/{key}/photos` | `200`, the recipe detail shape | 404, 422 |
| `DELETE` | `/recipes/{key}/photos/{photo}` | `200`, the recipe detail shape | 404 |

- `POST` takes multipart field `photo`, with SPEC 22's rules: JPEG, PNG, WebP or HEIC, at most 10 MB, shorter side at least 330 px, decodable, and fewer than 3 photos (checked with the recipe row locked).
- `PATCH` takes `{ "order": [keys] }`, every current key exactly once.
- `DELETE` removes the row, renumbers the rest, and deletes the files after commit.
- Requests: `StoreRecipePhotoRequest` and `ReorderRecipePhotosRequest`. They share their rules with SPEC 22's requests through `PhotoUploader::rules()`, so the two never drift.

### Response changes

Recipe detail gains `photos`:

```json
"photos": [
  { "key": "ph_8k2d4x7q", "url": "http://localhost:8000/storage/photos/recipes/r_01jc.../ph_8k2d4x7q-960.webp", "thumbnail_url": "http://localhost:8000/storage/photos/recipes/r_01jc.../ph_8k2d4x7q-330.webp" }
]
```

- `[]` when there are none.
- Each list item gains `photo`: the main photo in the same shape, or `null`. The list loads it with one extra query per page, not per item.
- This is a contract change to SPEC 19's response shapes, which get updated in place, following the CLAUDE.md rule.

### File removal

- **Deleting a photo:** row and files.
- **Deleting a recipe** (`DELETE /recipes/{key}`): its photo rows are deleted before `CatalogRemover` runs, and their files after commit, whether the recipe ends up hard- or soft-deleted (SPEC 20 can soft-delete a recipe used inside another).
- **Deleting a user:** the `User` deleting hook also collects every recipe photo path and deletes the files after commit.

## Files

```
app/Modules/Nutrition/
  Models/RecipePhoto.php
  Models/Recipe.php                              (photos, mainPhoto)
  Models/IngredientPhoto.php                     (uses HasOrderedPhotos)
  Models/Concerns/HasOrderedPhotos.php
  Support/PhotoUploader.php                      (rules())
  Http/Controllers/RecipePhotoController.php     (store, reorder, destroy)
  Http/Controllers/RecipeController.php          (destroy removes photos, list eager-loads mainPhoto)
  Http/Requests/StoreRecipePhotoRequest.php
  Http/Requests/ReorderRecipePhotosRequest.php
  Http/Requests/StoreIngredientPhotoRequest.php  (uses PhotoUploader::rules())
  Http/Resources/RecipePhotoResource.php
  Http/Resources/RecipeResource.php              (photos, photo)
  routes.php
app/Models/User.php                              (deleting hook removes recipe photo files)
database/migrations/<date>_create_recipe_photos_table.php
docs/openapi/v1.json
specs/19-recipes.md                              (header note, response shapes)
CLAUDE.md
tests/Feature/Nutrition/RecipePhotoApiTest.php
```

Plus additions to `RecipeDeleteApiTest`, `RecipeListApiTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-22-ingredient-photo-uploads`, and rebase onto `master` once it's merged.

1. **Shared pieces.** Extract `HasOrderedPhotos` and `PhotoUploader::rules()` from SPEC 22's code. The SPEC 22 tests still pass unchanged.
2. **Schema and model.** Add the migration, `RecipePhoto`, `photos` and `mainPhoto`. Tests: `migrate` and `migrate:rollback` work.
3. **Upload, reorder and delete.** Add the controller, requests, resource and routes. Tests in `RecipePhotoApiTest`, on `Storage::fake('photos')`:
   - 401 without a token, 404 on another user's recipe;
   - `201` with the recipe and a photo with a `ph_` key and URLs under `recipes/`;
   - a fourth photo, a file over 10 MB, a GIF, a 300 px image and a corrupt file each give 422, with no files left behind;
   - reorder sets positions, and a list that isn't exactly the current keys gives 422;
   - delete removes the row and both files and renumbers the rest, and an unknown key gives 404.
4. **Responses.** Extend `RecipeResource`. Tests:
   - the detail returns `photos` in order, and `[]` when none;
   - list items return the main `photo` or `null`;
   - the list runs the same number of queries for 5 and 15 recipes with photos.
5. **Cleanup on deletes.** Change `RecipeController::destroy` and the `User` hook. Tests:
   - deleting a recipe removes photo rows and files, both when it's hard-deleted and when a parent recipe line makes it soft-deleted;
   - deleting a user removes every recipe photo file of theirs.
6. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest`. Update SPEC 19's shapes in place and add to its header: `> **Note:** recipes gain photos (detail) and photo (list), by SPEC 23.` Update `CLAUDE.md`.
7. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] A user can upload up to 3 photos to their own recipe, stored as WebP at 960 and 330 px through `PhotoUploader`.
- [ ] Upload validation matches SPEC 22's, and failed uploads leave no files.
- [ ] Reorder and delete work as in SPEC 22, and positions stay 1 to n.
- [ ] The detail returns `photos`, and each list item returns the main `photo` or `null`, without a query per item.
- [ ] Deleting a photo, its recipe (hard or soft) or its user removes the photo files.
- [ ] Another user's recipe gives 404 on all three endpoints.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 19 has its header note and updated shapes, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** the same rules and endpoints as ingredient photos. Clients reuse one upload and reorder UI. **No:** up to 10 photos for step-by-step shots, which belong to steps. **No:** one photo only.
- **Yes:** a separate `recipe_photos` table. Recipe photos have no `source` or credit, and a polymorphic table would weaken the foreign keys. **No:** reusing `ingredient_photos` with a morph column.
- **Yes:** shared rules and ordering code through `PhotoUploader::rules()` and `HasOrderedPhotos`, so the two photo types can't drift.
- **Yes:** the main photo on list items. It's what a recipe card shows, and it costs one query per page.
- **Yes:** photos removed with their recipe, even when it's soft-deleted, as SPEC 22 does for ingredients.

## What is **not** in this spec

- Step photos.
- Photos on sub-recipe lines.
- More than 3 photos.
- Queued processing, cropping, moderation, a prune command, quotas and rate limiting.
- Frontend changes.
