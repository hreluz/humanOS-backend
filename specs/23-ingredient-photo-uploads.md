# SPEC 23 — Ingredient photo uploads

> **Status:** Draft
> **Depends on:** SPEC 17, SPEC 18, SPEC 20
> **Date:** 2026-11-05
> **Objective:** Let a user upload, reorder and delete up to three photos of their own ingredients, stored as metadata-free WebP files in two sizes on a configurable disk.

## Why this spec exists

SPEC 18 left users' ingredients with `photos: []` and promised uploads in their own spec, next. Users photograph the package to recognize it in search. This spec also builds the upload groundwork (storage disk, validation, re-encoding, file cleanup) that the recipe photos spec (SPEC 24) reuses.

## Scope

**In:**

- A `photos` filesystem disk: local in development and Docker, S3-compatible on deployed servers through `.env`.
- Upload validation: JPEG, PNG, WebP or HEIC, at most 10 MB, shorter side at least 330 px.
- Re-encoding to WebP at 960 px and 330 px wide, auto-rotated, with all metadata stripped. The original isn't kept.
- Processing during the request.
- `POST`, `DELETE` and a reorder `PATCH` for a user's ingredient's photos, at most 3 per ingredient.
- `IngredientPhotoSource::Upload`, a photo `key` for uploads, and `url` and `thumbnail_url` for uploads built from the disk.
- File removal when a photo, its ingredient or its user is deleted.
- A reusable `Support/PhotoUploader` (validate, re-encode, store, delete after commit) for SPEC 24.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Recipe photos (SPEC 24).
- Queued processing, which comes with Redis and a queue worker.
- Uploading photos for catalog ingredients, by any user or admin.
- Cropping, filters or a choice of focal point.
- A `photos:prune` command for orphan files.
- Image moderation.
- Rate limiting and storage quotas per user.
- Frontend changes.

## Data model

### Storage

- `config/filesystems.php` gets a `photos` disk. Its driver comes from `PHOTOS_DISK_DRIVER` (default `local`):
  - `local`: root `storage_path('app/public/photos')`, URL `APP_URL/storage/photos`, public visibility;
  - `s3`: bucket, region, endpoint and URL from `PHOTOS_S3_*` variables, which work for S3, Cloudflare R2 and MinIO.
- `league/flysystem-aws-s3-v3` is added to `composer.json`, so switching to S3 is an `.env` change only.
- `.env.example` and `.env.development` get the `PHOTOS_*` variables, kept in sync per CLAUDE.md.
- `php artisan storage:link` is added to the Docker first-time setup in `docs/docker.md`.
- File layout: `ingredients/<ingredient key>/<photo key>-960.webp` and `-330.webp`.

### Image processing

- `intervention/image` (v3) with the Imagick driver.
- The `Dockerfile` installs ImageMagick with HEIC support (`libmagickwand-dev`, `libheif-dev`) and the `imagick` PHP extension.
- Steps for every upload: read, auto-orient from EXIF, scale down to 960 px and to 330 px wide (never up), encode each to WebP at quality 80. Encoding from pixels drops EXIF, GPS, ICC comments and every other metadata block.
- A file the decoder can't read is a 422, whatever its extension or MIME type.

### Migration

```php
// database/migrations/<date>_add_key_to_ingredient_photos_table.php
Schema::table('ingredient_photos', function (Blueprint $table) {
    $table->string('key', 11)->nullable()->after('ingredient_id');
    $table->unique(['ingredient_id', 'key']);
});
```

- An upload's `key` is `ph_` + 8 random lowercase letters and digits, generated on upload, never changed. Commons photos keep `key` `null`. They're rebuilt on every seed and can't be managed.
- For an upload, `source` is `upload` and `path` is `ingredients/<ingredient key>/<photo key>`, without the size suffix. `license`, `license_url` and `author` are `null`.
- `position` is 1–3, and 1 is the main photo. It's renumbered after every delete and reorder. Because `(ingredient_id, position)` is unique, renumbering writes in two passes inside the transaction (first to offset values, then to the final ones).

### Enum and model

```php
enum IngredientPhotoSource: string
{
    case Commons = 'commons';
    case Upload = 'upload';
}
```

- `IngredientPhoto::url` and `thumbnail_url` for `upload` are `Storage::disk('photos')->url("{$path}-960.webp")` and `-330.webp`.
- `credit_url` is `null` for `upload`.
- The seeder rejects `upload` as a catalog photo source.

### `PhotoUploader`

`app/Modules/Nutrition/Support/PhotoUploader.php`:

- `store(UploadedFile $file, string $basePath): void`: encodes and writes both sizes.
- `deleteAfterCommit(iterable $basePaths): void`: deletes both sizes of each path once the current transaction commits, or right away outside one. A failed file delete is logged and never throws.

## Endpoints

All of them require a bearer token (401 otherwise) and live under `api/v1/nutrition`. `{key}` resolves through SPEC 18's binding, so another user's ingredient is a 404. `IngredientPolicy::update` runs first, so a catalog ingredient is a 403.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/ingredients/{key}/photos` | `201`, the ingredient detail shape | 403, 404, 422 |
| `PATCH` | `/ingredients/{key}/photos` | `200`, the ingredient detail shape | 403, 404, 422 |
| `DELETE` | `/ingredients/{key}/photos/{photo}` | `200`, the ingredient detail shape | 403, 404 |

### `POST /ingredients/{key}/photos` (`StoreIngredientPhotoRequest`)

- Multipart, with one field `photo`.

| Field | Rule |
|---|---|
| `photo` | required, file, MIME `image/jpeg`, `image/png`, `image/webp`, `image/heic` or `image/heif`, at most 10 MB, shorter side at least 330 px |
| count | the ingredient has fewer than 3 photos (422 on `photo` otherwise) |

- The new photo goes at the end of the list.
- The row is written in a transaction. The files are written before the commit, and if the transaction fails they're deleted.
- It returns the ingredient, so the client gets the new `photos` list in one call.

### `PATCH /ingredients/{key}/photos` (`ReorderIngredientPhotosRequest`)

```json
{ "order": ["ph_8k2d4x7q", "ph_3m5n7p9r"] }
```

- `order` is required and lists every current photo key exactly once. A missing, unknown or repeated key gives 422.
- Positions follow the list.

### `DELETE /ingredients/{key}/photos/{photo}`

- `{photo}` is the photo's `key`, scoped to the ingredient. An unknown key or a Commons photo is a 404.
- Removes the row, renumbers the rest, and deletes both files after commit.
- It returns `200` with the ingredient, not `204`, so the client gets the renumbered list.

### Response changes

- Every photo in `photos` gains `key` (string for uploads, `null` for Commons photos) and `source` (`commons` or `upload`).
- For an upload, `license`, `license_url`, `author` and `credit_url` are `null`.
- This is a contract change to SPEC 17's photo shape, which gets updated in place, following the CLAUDE.md rule.

### File removal

- **Deleting a photo:** row and files, as above.
- **Deleting an ingredient** (`DELETE /ingredients/{key}`): its photo rows are deleted before `CatalogRemover` runs, and their files after commit. This applies whether the ingredient ends up hard- or soft-deleted, since no response shows a deleted ingredient's photos.
- **Deleting a user:** the `User` deleting hook (SPEC 20) also collects every upload path of the user's ingredients and deletes the files after commit. The rows go with the cascade.

## Files

```
app/Modules/Nutrition/
  Enums/IngredientPhotoSource.php                (Upload)
  Models/IngredientPhoto.php                     (key, upload URLs)
  Support/PhotoUploader.php
  Http/Controllers/IngredientPhotoController.php (store, reorder, destroy)
  Http/Controllers/IngredientController.php      (destroy removes photos)
  Http/Requests/StoreIngredientPhotoRequest.php
  Http/Requests/ReorderIngredientPhotosRequest.php
  Http/Resources/IngredientPhotoResource.php     (key, source, nullable credit)
  routes.php
app/Models/User.php                              (deleting hook removes photo files)
config/filesystems.php                           (photos disk)
database/migrations/<date>_add_key_to_ingredient_photos_table.php
database/seeders/IngredientSeeder.php            (rejects `upload`)
Dockerfile                                       (ImageMagick, libheif, imagick)
composer.json                                    (intervention/image, league/flysystem-aws-s3-v3)
.env.example, .env.development                   (PHOTOS_*)
docs/docker.md                                   (storage:link)
docs/openapi/v1.json
specs/17-ingredient-photos.md                    (header note, photo shape)
specs/18-user-ingredients.md                     (header note)
CLAUDE.md
tests/Feature/Nutrition/IngredientPhotoUploadApiTest.php
tests/Unit/Nutrition/PhotoUploaderTest.php
tests/fixtures/photos/                           (a JPEG with EXIF GPS and orientation 6, a PNG, a WebP, a HEIC, a 300 px image, a corrupt file)
```

Plus additions to `UserIngredientDeleteApiTest`, `IngredientSeederTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-20-recipes`, and rebase onto `master` once it's merged.

1. **Image tooling.** Update the `Dockerfile`, add both packages, and rebuild. Manual check: `php -r 'print_r(Imagick::queryFormats("HEIC"));'` lists `HEIC`.
2. **Disk and uploader.** Add the `photos` disk, the `PHOTOS_*` variables, `storage:link` in `docs/docker.md`, and `PhotoUploader`. Tests in `PhotoUploaderTest`, on `Storage::fake('photos')`:
   - each fixture format produces two WebP files of 960 and 330 px wide;
   - the EXIF fixture comes out rotated (taller than wide) and with no EXIF or GPS data (`exif_read_data` finds none);
   - a 500 px image isn't upscaled at 960;
   - the corrupt file throws a validation exception;
   - `deleteAfterCommit` deletes nothing when the transaction rolls back, and both files after a commit.
3. **Schema and model.** Add the migration, `IngredientPhotoSource::Upload`, upload URLs and the seeder rule. Tests: `migrate` and `migrate:rollback` work, Commons photos keep their URLs, and the seeder rejects `upload`.
4. **Upload.** Add `StoreIngredientPhotoRequest`, the controller's `store`, and the route. Tests in `IngredientPhotoUploadApiTest`:
   - 401 without a token, 403 on a catalog ingredient, 404 on another user's;
   - `201` with the ingredient, a photo with a `ph_` key, `source` `upload`, URLs on the `photos` disk, and `null` credit fields;
   - a fourth photo, a 10.1 MB file, a GIF, a 300 px image and the corrupt file each give 422, with no files left behind;
   - search and detail return the uploaded photo.
5. **Reorder and delete.** Add both requests' handling and routes. Tests:
   - reorder sets positions, and a missing, unknown or repeated key gives 422;
   - delete removes the row and both files and renumbers the rest, and a Commons photo key or an unknown key gives 404.
6. **Cleanup on deletes.** Change `IngredientController::destroy` and the `User` hook. Tests:
   - deleting an ingredient with photos removes rows and files, both when it's hard-deleted and when a recipe line makes it soft-deleted;
   - deleting a user removes every photo file of theirs.
7. **Docs and records.** PHPDoc and `@example`s (multipart body for `POST`), then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest`. Update SPEC 17's photo shape in place and add to its header: `> **Note:** photos gain key and source, and uploads exist, by SPEC 23.` Add to SPEC 18's header: `> **Note:** users' ingredients can have up to three uploaded photos, by SPEC 23.` Update `CLAUDE.md`: the `photos` disk, uploads, file removal.
8. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] The Docker image decodes HEIC.
- [ ] Uploading a JPEG, PNG, WebP or HEIC to the caller's ingredient stores two WebP files of 960 and 330 px wide, and returns `201` with the photo in `photos`.
- [ ] Stored files have no EXIF or GPS data and are correctly rotated.
- [ ] A fourth photo, a file over 10 MB, an unsupported format, an image under 330 px on its shorter side, and an undecodable file give 422 and leave no files.
- [ ] Reorder sets positions from `order`, and rejects a list that isn't exactly the current keys.
- [ ] Deleting a photo, its ingredient (hard or soft) or its user removes the photo files.
- [ ] Catalog ingredients give 403 and another user's ingredient gives 404 on all three endpoints.
- [ ] Changing `PHOTOS_DISK_DRIVER` to `s3` with the `PHOTOS_S3_*` variables needs no code change (checked with a config test that builds the disk).
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 17 and SPEC 18 have their header notes, and `CLAUDE.md` and `docs/docker.md` are updated.

## Decisions

- **Yes:** ingredient uploads first, with groundwork that recipe photos reuse. Each spec stays one feature, and the pipeline is built once. **No:** one combined uploads spec. **No:** self-contained recipe uploads.
- **Yes:** a `photos` disk, local by default and S3-compatible through `.env`. Development needs no new container, and the API never builds URLs by hand. **No:** local only. **No:** MinIO in Docker from the start.
- **Yes:** JPEG, PNG, WebP and HEIC, at most 10 MB, at least 330 px. HEIC is what iPhones produce.
- **Yes:** re-encode to WebP at 960 and 330 px, auto-rotated, original discarded. Rebuilding from pixels is the reliable way to drop metadata, and the widths match what clients get for Commons photos. **No:** keeping the original, which still carries GPS data. **No:** storing uploads as sent after stripping EXIF.
- **Yes:** Imagick through `intervention/image`. GD can't decode HEIC.
- **Yes:** processing during the request. The queue has no worker in Docker, and a few seconds is normal for an upload. **No:** a queue job with `processing`, `ready` and `failed` states, which every client would handle. It can come with Redis.
- **Yes:** up to 3 photos, with upload, delete and reorder. It matches the catalog's limit. **No:** exactly one photo.
- **Yes:** keys only on uploads. Commons photos are rebuilt on every seed and can't be managed.
- **Yes:** `POST` and `DELETE` return the ingredient, so the client gets the new list and positions in one call.
- **Yes:** files deleted with their photo, ingredient or user, after commit. A user who deletes something expects the photo gone, and a rollback never leaves a row without its file. **No:** keeping photos of soft-deleted ingredients. **No:** only a scheduled prune, which can come later as a safety net.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The distro's ImageMagick lacks a HEIC delegate. | Step 1 checks `queryFormats("HEIC")` before anything else. If it fails, HEIC moves out of scope and the spec gets a header note. |
| A crafted image exploits the decoder. | ImageMagick's `policy.xml` in the image allows only JPEG, PNG, WebP and HEIC, with memory and pixel limits. The decoder only runs on files that passed the size and MIME checks. |
| A file delete fails and leaves an orphan. | It's logged. Orphans waste space but never break a response. A prune command can come later. |
| Two uploads at once push an ingredient past 3 photos. | The count check runs inside the transaction with the ingredient row locked for update. |
| Large uploads hold a PHP worker for seconds. | 10 MB cap. Queued processing is the follow-up once Redis lands. |

## What is **not** in this spec

- Recipe photos (SPEC 24).
- Queued processing.
- Photos for catalog ingredients.
- Cropping, moderation, a prune command, quotas and rate limiting.
- Frontend changes.
