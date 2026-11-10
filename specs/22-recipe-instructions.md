# SPEC 22 — Recipe instructions

> **Status:** Draft
> **Depends on:** SPEC 20
> **Date:** 2026-11-05
> **Objective:** Let a user add ordered steps, free-text notes, and prep and cook times to their recipes.

## Why this spec exists

SPEC 20 recipes say what goes in, not how to make it. A saved recipe the user can't cook from has to be remembered or kept somewhere else. Steps give a client what it needs for a "step 2 of 5" view, notes hold what doesn't fit a step ("works with turkey too"), and the times are what a recipe card shows.

## Scope

**In:**

- `steps`: an ordered list of plain-text steps on a recipe.
- `notes`: one optional plain-text field.
- `prep_minutes` and `cook_minutes`: optional integers.
- All four accepted by `POST /recipes` and `PATCH /recipes/{key}`, and returned by the detail.
- `prep_minutes` and `cook_minutes` also returned by the list.
- Feature tests, Scramble docs, and `docs/openapi/v1.json` re-exported.

**Out of scope (for future specs):**

- A time or timer on each step.
- Markdown or any formatting in steps or notes.
- Photos on steps (the recipe photos spec covers the recipe only).
- Linking a step to the ingredient lines it uses.
- Translating steps or notes.
- Frontend changes.

## Data model

### Migration

```php
// database/migrations/<date>_add_instructions_to_recipes_table.php
Schema::table('recipes', function (Blueprint $table) {
    $table->json('steps')->nullable()->after('servings');
    $table->text('notes')->nullable()->after('steps');
    $table->unsignedSmallInteger('prep_minutes')->nullable()->after('notes');
    $table->unsignedSmallInteger('cook_minutes')->nullable()->after('prep_minutes');
});
```

- `steps` is a JSON array of strings, cast to `array` on `Recipe`. `null` and `[]` both mean "no steps", and the API always returns `[]`.
- `notes` `null` means no notes. An empty string after trimming is stored as `null`.
- `prep_minutes` and `cook_minutes` `null` mean "not given", never `0`. `0` is allowed and means none (e.g. a no-cook salad).
- Existing recipes get `null` in all four columns.

## Endpoints

No new endpoints.

### Request fields (`StoreRecipeRequest`, `UpdateRecipeRequest`)

```json
{
  "steps": [
    "Boil the spaghetti in salted water for 9 minutes.",
    "Brown the beef with the onion and carrot.",
    "Add the tomatoes and simmer for 20 minutes."
  ],
  "notes": "Works with ground turkey too.",
  "prep_minutes": 15,
  "cook_minutes": 30
}
```

| Field | Rule |
|---|---|
| `steps` | optional, list of at most 30 items |
| `steps.*` | required, string, 1–500 characters after trimming |
| `notes` | optional, nullable, string, at most 2000 characters after trimming |
| `prep_minutes`, `cook_minutes` | optional, nullable, integer, 0–1440 |

- On create, an absent field is stored as `null`.
- On `PATCH`, an absent field keeps its stored value. `steps` is all or nothing: present means the full new list, and `[]` clears it. `notes: null` and `prep_minutes: null` clear those values.
- Steps are stored trimmed, in the order sent.
- Plain text only: no field is parsed or sanitized as markup, and clients show it as text.

### Response fields

- The detail gains `steps` (array of strings, `[]` when none), `notes` (string or `null`), `prep_minutes` and `cook_minutes` (integer or `null`).
- The list gains `prep_minutes` and `cook_minutes`. It doesn't return `steps` or `notes`.
- This is a contract change to SPEC 20's response shapes, which get updated in place, following the CLAUDE.md rule.

## Files

```
app/Modules/Nutrition/
  Models/Recipe.php                              (casts)
  Http/Requests/StoreRecipeRequest.php
  Http/Requests/UpdateRecipeRequest.php
  Http/Resources/RecipeResource.php
  Http/Controllers/RecipeController.php          (store and update write the new fields)
database/migrations/<date>_add_instructions_to_recipes_table.php
docs/openapi/v1.json
specs/20-recipes.md                              (header note, response shapes)
CLAUDE.md
tests/Feature/Nutrition/RecipeInstructionsApiTest.php
```

Plus additions to `RecipeListApiTest` and `OpenApiDocumentTest`.

## Implementation plan

All commands run inside the `app` container. Branch from `spec-20-recipes`, and rebase onto `master` once SPEC 20 is merged.

1. **Schema.** Add the migration and the `steps` cast. Tests: `migrate` and `migrate:rollback` work, and an existing recipe returns `steps: []` and `null` for the other three.
2. **Create and detail.** Extend `StoreRecipeRequest`, `store` and `RecipeResource`. Tests in `RecipeInstructionsApiTest`:
   - the four fields are stored and returned, with steps trimmed and in order;
   - absent fields give `steps: []` and `null` for the others;
   - a `notes` of only spaces is stored as `null`;
   - `prep_minutes: 0` is stored as `0`, not `null`;
   - each rule gives 422: 31 steps, an empty step after trimming, a step of 501 characters, notes of 2001 characters, minutes of -1, 1441 or 1.5.
3. **Update.** Extend `UpdateRecipeRequest` and `update`. Tests:
   - absent fields keep their values;
   - `steps` replaces the list, and `steps: []` clears it;
   - `notes: null`, `prep_minutes: null` and `cook_minutes: null` clear them;
   - `steps: null` gives 422.
4. **List.** Extend the list shape. Tests in `RecipeListApiTest`: items have `prep_minutes` and `cook_minutes`, and no `steps` or `notes`.
5. **Docs and records.** PHPDoc and `@example`s, then `php artisan scramble:export --path=docs/openapi/v1.json`. Extend `OpenApiDocumentTest` for the nullable fields. Update SPEC 20's response shapes in place and add to its header: `> **Note:** recipes gain steps, notes, prep_minutes and cook_minutes, by SPEC 22.` Update `CLAUDE.md`.
6. **Housekeeping.** `vendor/bin/pint`, then `vendor/bin/pint --test` reports no issues.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] `POST` and `PATCH` accept `steps` (at most 30, each 1–500 characters), `notes` (at most 2000 characters) and `prep_minutes` and `cook_minutes` (0–1440), and reject values outside those limits with 422.
- [ ] `PATCH` keeps absent fields, replaces `steps` as a whole, and clears a field sent as `null` (or `steps` sent as `[]`).
- [ ] The detail returns all four fields, with `steps: []` when there are none.
- [ ] The list returns `prep_minutes` and `cook_minutes`, and not `steps` or `notes`.
- [ ] `docs/openapi/v1.json` is re-exported, SPEC 20 has its header note and updated shapes, and `CLAUDE.md` is updated.

## Decisions

- **Yes:** ordered steps plus a notes field. Steps let a client show a step-by-step view, and notes hold tips and substitutions. **No:** one free-text field, which clients can't split into steps. **No:** steps only, which leaves nowhere for tips.
- **Yes:** at most 30 steps of 500 characters, and notes of 2000. A detailed recipe needs about a dozen steps.
- **Yes:** plain text. Every client shows it the same way, with no renderer and nothing to sanitize. **No:** Markdown.
- **Yes:** `prep_minutes` and `cook_minutes`, nullable, 0–1440. They're what a recipe card shows, and `null` keeps "not given" apart from `0`. **No:** a time per step, which only makes sense with a cooking-mode UI.
- **Yes:** steps in a JSON column. They're always read and written whole and never queried, so they need no table. **No:** a `recipe_steps` table with positions and keys, since nothing references a single step.
- **Yes:** steps and notes left out of the list, which stays small.

## What is **not** in this spec

- Per-step times or timers.
- Formatting in steps or notes.
- Step photos and links between steps and ingredients.
- Translations.
- Frontend changes.
