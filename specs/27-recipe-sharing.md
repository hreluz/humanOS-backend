# SPEC 27 — Recipe sharing

> **Status:** Draft
> **Depends on:** SPEC 20, SPEC 21, SPEC 22, SPEC 23, SPEC 24
> **Date:** 2026-11-05
> **Objective:** Let a user share one of their recipes through a secret link that any signed-in user can open read-only and save as an independent copy in their own account.

## Why this spec exists

Recipes are private (SPEC 20). People want to send a recipe to a friend ("try my bolognese"). A share link covers that without a user directory, friend lists or a public catalog. Saving makes a full copy, so the friend's recipe never changes or breaks when the owner edits, deletes or stops sharing theirs.

## Scope

**In:**

- A share token per recipe: turned on and off by the owner. Turning it off makes the link stop working.
- `GET /shared-recipes/{token}`: a read-only view for any signed-in user, with no data about the owner.
- `POST /shared-recipes/{token}/save`: an independent copy in the caller's account, with the owner's private ingredients, sub-recipes, steps, notes, times and recipe photos copied.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- Sharing with specific users, and recipes linked to an original.
- Public recipes and a shared recipe search.
- Viewing a link without signing in, and link previews (Open Graph).
- A public display name and "Shared by".
- Link expiry dates and view counts.
- Copying ingredient photos or prices.
- Rate limiting on the view and save endpoints.
- Frontend changes.

## Data model

### Migration

```php
// database/migrations/<date>_add_share_token_to_recipes_table.php
Schema::table('recipes', function (Blueprint $table) {
    $table->string('share_token', 43)->nullable()->unique();
    $table->timestamp('shared_at')->nullable();
});
```

- `share_token` is 32 random bytes, base64url without padding (43 characters), from a cryptographically secure source. `null` means not shared.
- Turning sharing on when it's already on returns the current token. Turning it off sets both columns to `null`, and turning it on again generates a new token, so an old link never comes back.
- A soft-deleted recipe's link is a 404. Deleting a recipe through `DELETE /recipes/{key}` also sets `share_token` to `null`.

### Copying

`app/Modules/Nutrition/Support/RecipeCopier.php` copies a recipe into another user's account, in one transaction:

- **Recipe:** `name`, `servings`, `steps`, `notes`, `prep_minutes`, `cook_minutes`, with a new `r_` key, no share token and new timestamps.
- **Catalog ingredient lines:** referenced as they are, including soft-deleted catalog ingredients (shown as `deleted: true`, as in SPEC 20).
- **The owner's private ingredients:** each is copied once, even when the parent and a sub-recipe both use it, as a new private ingredient of the caller with `name`, `category`, the five macros and every portion the recipe uses plus its current portions. It gets a new `u_` key and new `p_` keys, `source` `user`, and no photos or price. Soft-deleted ones are copied as live ingredients. Lines point to the copies and their portions.
- **Sub-recipe lines** (SPEC 21): the sub-recipe is copied as a new recipe of the caller with the same rules, and the line points to the copy.
- **Recipe photos** (SPEC 24): each file is copied on the `photos` disk to the new recipe's folder, under a new `ph_` key, in the same order. If the transaction fails, files copied so far are deleted.
- **When the caller is the owner:** their own ingredients are referenced, not copied. Sub-recipes are still copied, so the new recipe is independent.
- Line `quantity` and `grams` are copied as stored.

## Endpoints

All of them require a bearer token (401 otherwise) and live under `api/v1/nutrition`.

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/recipes/{key}/share` | `200`, `{ "data": { "token": "...", "shared_at": "..." } }` | 404 |
| `DELETE` | `/recipes/{key}/share` | `204`, no body | 404 |
| `GET` | `/shared-recipes/{token}` | `200`, the shared view shape | 404 |
| `POST` | `/shared-recipes/{token}/save` | `201`, the recipe detail shape (SPEC 20), owned by the caller | 404 |

- `{key}` resolves through SPEC 20's binding, so only the owner can share or stop sharing.
- `{token}` resolves to a recipe with that token that isn't soft-deleted, or a 404. An unknown, revoked or deleted link look the same.
- The client builds the link it shows (e.g. `https://app.humanos…/r/<token>`). The API returns only the token.
- `DELETE` on a recipe that isn't shared is a `204` that changes nothing.

### Shared view shape

```json
{
  "data": {
    "name": "Spaghetti bolognese",
    "servings": 4,
    "totals": { "kcal": 2480.0, "protein": 152.3, "carbs": 268.1, "fat": 81.4, "fiber": 22.0 },
    "per_serving": { "kcal": 620.0, "protein": 38.1, "carbs": 67.0, "fat": 20.4, "fiber": 5.5 },
    "ingredients": [
      {
        "type": "ingredient",
        "ingredient": { "key": "spaghetti_dry", "name": "Spaghetti, dry", "category": "grains", "is_mine": false, "deleted": false },
        "portion": null,
        "quantity": 280,
        "grams": 280,
        "macros": { "kcal": 1036.0, "protein": 36.4, "carbs": 210.0, "fat": 4.2, "fiber": 8.4 }
      },
      {
        "type": "recipe",
        "recipe": { "name": "Homemade tomato sauce", "servings": 4, "ingredients": [ ] },
        "quantity": 1.5,
        "macros": { "kcal": 180.0, "protein": 4.1, "carbs": 22.5, "fat": 8.3, "fiber": 4.8 }
      }
    ],
    "steps": ["Boil the spaghetti…"],
    "notes": null,
    "prep_minutes": 15,
    "cook_minutes": 30,
    "photos": [{ "url": "…-960.webp", "thumbnail_url": "…-330.webp" }],
    "shared_at": "2026-11-05T10:00:00Z"
  }
}
```

- Macros are live, from the owner's current recipe, as in SPEC 20.
- No recipe `key`, no owner data, no `cost`, and no `created_at` or `updated_at`.
- An ingredient that the viewer can't see (the owner's private one) has `key: null`, `is_mine: false`, and its name, category and macros.
- A sub-recipe line includes the sub-recipe's own lines, since the viewer can't open the sub-recipe. That's one level, as SPEC 21 allows. The sub-recipe has no `key`.
- Photo items have no `key`.
- The view runs a fixed number of queries, whatever the number of lines.

## Files

```
app/Modules/Nutrition/
  Support/RecipeCopier.php
  Http/Controllers/RecipeShareController.php     (store, destroy)
  Http/Controllers/SharedRecipeController.php    (show, save)
  Http/Resources/SharedRecipeResource.php
  Http/Resources/SharedRecipeLineResource.php
  Http/Controllers/RecipeController.php          (destroy clears the token)
  NutritionServiceProvider.php                   (route binding for {token})
  routes.php
database/migrations/<date>_add_share_token_to_recipes_table.php
docs/openapi/v1.json
specs/20-recipes.md                              (header note)
CLAUDE.md
tests/Feature/Nutrition/RecipeShareApiTest.php
tests/Feature/Nutrition/SharedRecipeApiTest.php
tests/Feature/Nutrition/SharedRecipeSaveApiTest.php
```

Plus additions to `RecipeDeleteApiTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-24-recipe-photos`, and rebase onto `master` once it's merged.

1. **Schema and share toggle.** Add the migration, `RecipeShareController` and the routes. Tests in `RecipeShareApiTest`:
   - 401 without a token, and 404 on another user's recipe;
   - `POST` returns a 43-character token, and a second `POST` returns the same one;
   - `DELETE` clears it, and a new `POST` returns a different token;
   - deleting the recipe clears its token.
2. **Shared view.** Add the `{token}` binding, `SharedRecipeController::show` and both resources. Tests in `SharedRecipeApiTest`:
   - another user sees the shape, with no recipe key, owner data or cost;
   - the owner's private ingredient has `key: null` and its name and macros;
   - a sub-recipe line includes its lines, with no keys;
   - steps, notes, times and photos are included;
   - the owner's later edit shows on the next view;
   - an unknown, revoked or soft-deleted recipe's token gives 404;
   - the view runs the same number of queries for 2 and 20 lines.
3. **Copier.** Add `RecipeCopier`. Tests in `SharedRecipeSaveApiTest`, on `Storage::fake('photos')`:
   - `save` returns `201` with a new recipe owned by the caller, with a new key and no share token;
   - catalog lines reference the same ingredients;
   - each of the owner's private ingredients becomes one new private ingredient of the caller, with portions and lines pointing to the copies, and no photos or price;
   - an ingredient used by both the parent and a sub-recipe is copied once;
   - the sub-recipe is copied as the caller's recipe, and the parent's line points to it;
   - steps, notes and times are copied, and photo files are copied under the new recipe with new keys;
   - after the owner deletes the original, its ingredients and its photos, the copy and its files are unchanged;
   - the owner saving their own link references their own ingredients and copies the sub-recipe;
   - a failure inside the transaction leaves no rows and no copied files.
4. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest`. Add to SPEC 20's header: `> **Note:** recipes can be shared by link and saved as copies, by SPEC 27.` Update `CLAUDE.md`: share tokens, the shared view, and copying.
5. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] The owner can turn sharing on (same token while on) and off (link stops working, and turning it on again gives a new token).
- [ ] Any signed-in user can open a link and see the recipe read-only, with no recipe key, owner data or cost.
- [ ] Unknown, revoked and deleted links give the same 404.
- [ ] Saving creates an independent recipe for the caller, with the owner's private ingredients and sub-recipes copied, catalog ingredients referenced, and recipe photos copied.
- [ ] Edits and deletes by the owner after saving don't change the copy.
- [ ] A failed save leaves no rows and no files.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 20 has its header note, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** a secret link with a read-only view and a copy on save. It covers sending a recipe to a friend. **No:** sharing with named users, which needs a user lookup and linked recipes. **No:** public recipes, which need a public catalog and moderation.
- **Yes:** turning sharing off revokes the link, and turning it on again makes a new token. An old link never comes back.
- **Yes:** the owner's private ingredients copied into the saver's account, once each, without photos or prices. The copy is complete and nothing stays linked to the owner. **No:** a 422 on recipes with private ingredients. **No:** dropping those lines.
- **Yes:** no owner data in the view. A link can be forwarded beyond the people the owner meant. **No:** "Shared by", which needs a public display name.
- **Yes:** recipe photos in the view and copied on save. They're what the owner attached to the recipe. **No:** ingredient photos, which may show the owner's home.
- **Yes:** cost left out of the view and the copy. Prices are personal and in the owner's currency.
- **Yes:** sub-recipe lines expanded in the view, since the viewer can't open the sub-recipe, and the private ingredient keys hidden, since they'd be a 404 for the viewer.
- **Yes:** signed-in users only. Every endpoint stays behind Sanctum. A public preview for people without accounts is its own spec.
- **Yes:** the owner saving their own link references their own ingredients, which avoids duplicating them.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| A link leaks further than intended. | The view holds no owner data. The owner can revoke it, and turning it on again makes a new token. |
| Guessing tokens. | 256 random bits. Rate limiting is a separate spec. |
| Repeated saves fill a user's account with copied ingredients. | Each save is an explicit action by that user, and the copies are ordinary private ingredients they can delete. |
| Copying photo files is slow or fails halfway. | At most 3 photos per recipe (and per sub-recipe), copied with `Storage::copy` without re-encoding. Files copied so far are deleted if the transaction fails. |

## What is **not** in this spec

- Sharing with named users, or linked recipes.
- Public recipes and recipe search.
- Viewing without signing in, and link previews.
- Owner names, expiry and view counts.
- Copying ingredient photos or prices.
- Rate limiting.
- Frontend changes.
