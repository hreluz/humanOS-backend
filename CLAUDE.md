# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Backend API for **humanOS**, a fitness and health platform covering nutrition, workouts, health metrics and goals, plus a recommendation engine that answers "what should I do today?". See `README.md` for the full feature list, MVP scope and later phases.

Other clients (Nuxt web, React Native mobile, Tauri desktop, SwiftUI watch) are planned to live in separate apps and use this API through OpenAPI-generated clients. Treat this repo as **API-only**. The Blade/Vite/Tailwind files that remain are skeleton leftovers.

## Current state vs. target architecture

The repo is a Laravel 13 app (PHP ^8.3; the Docker image uses 8.4) in its early stages. Some pieces of the README's planned stack are in place, others are not yet:

- **Database:** the README targets PostgreSQL, but `docker-compose.yml` runs MySQL 8 and `.env.example` defaults to SQLite. Tests run against a separate MySQL database, `laravel_testing` (`phpunit.xml`), so they need the Docker stack and must run inside the `app` container. The testing database is created by hand once (see `docs/docker.md`, "Tests").
- **Env files:** `.env.example` is the generic template (SQLite, `MAIL_MAILER=log`). `.env.development` is the committed template for the Docker stack: the same file with `DB_*` pointing to `mysql` and `MAIL_*` pointing to `mailpit`. Keep the two in sync: any variable added to or changed in `.env.example` goes into `.env.development` too.
- **Email:** in Docker, the app sends mail to Mailpit, and the inbox is at http://localhost:8025. Tests use the `array` mailer.
- **Auth:** Laravel Sanctum is installed. Clients authenticate with bearer tokens (personal access tokens) issued by `/api/v1/auth` (see `specs/01-api-authentication.md`).
- **Routing:** API routes are versioned under `/api/v1`. `routes/api.php` exists but stays empty: each module's service provider loads its own `routes.php` with its prefix (e.g. `api/v1/auth`) and the `api` middleware. Exceptions render as JSON for `api/*` requests or requests that expect JSON.
- **Structure:** *modular monolith*. Each module lives in `app/Modules/<Name>` with its own service provider (registered in `bootstrap/providers.php`), `routes.php`, and `Http/`, `Console/`, `Enums/` folders as needed. `Auth` is the first module. Shared models such as `User` stay in `app/Models`.
- **Nutrition module:** `app/Modules/Nutrition` holds the ingredient catalog's enums, models, `Policies/IngredientPolicy` (SPEC 18), `Support/CatalogRemover` (SPEC 15) and `Support/IngredientPhotoLicense` (SPEC 17). `NutritionServiceProvider` loads its routes under `api/v1/nutrition` (SPEC 16): `GET ingredients?search=` (a required term of 3–100 characters, an optional `category[]`, every match ranked in one unpaginated list) and `GET ingredients/{key}` (adds the measured nutrients and the source). Every ingredient response has `is_mine`. Search matches English names and English aliases only, and responses are English only until the language spec adds the user's language. Accent-insensitive matching relies on MySQL's `utf8mb4_unicode_ci` collation. About 200 USDA FoodData Central ingredients live in `database/data/ingredients/<category>.json` and are loaded by `NutrientSeeder` (29 nutrients) and `IngredientSeeder`, both idempotent. Every value is per 100 g, with portions in grams, and a missing nutrient row means "not measured". `ingredients.fiber` is nullable (`null` means "no data", never `0`), but catalog entries always have it. Rules for the files are in `docs/ingredient-catalog.md`.
- **Ingredient owners and photos (SPEC 17):** `ingredients.user_id` is `null` for system ingredients, the catalog from the data files, and is deleted with its user otherwise. `IngredientSeeder` only upserts and removes system rows, and throws on a key a user's ingredient holds. Every catalog ingredient has one to three Wikimedia Commons photos in `ingredient_photos` (`source` `commons`), listed under `photos` in the data files and rebuilt on every seed. Both ingredient endpoints return `photos` with `url` (960 px) and `thumbnail_url` (330 px) built from `config('services.ingredient_photos')`, which must use Wikimedia standard widths. `php artisan ingredients:check-photos` sends a `HEAD` to every distinct photo with the configured User-Agent and exits `1` if any fails.
- **Users' ingredients (SPEC 18):** private ingredients a user types in from a label: `name`, `category`, the five macros per 100 g (`fiber` optional) and up to 10 portions. `POST ingredients` creates one with a generated `u_<ulid>` key, `source` `user` and `source_id` `null`, and each portion gets a generated `p_<8 chars>` key that never changes. `PATCH ingredients/{key}` changes the fields sent (the `protein + carbs + fat` ≤ 100 rule is checked on the merged values), and `portions`, when sent, is the full list matched by key. `DELETE ingredients/{key}` and dropped portions go through `CatalogRemover`. `GET my-ingredients` is the caller's own, paginated (SPEC 04, default 50, max 100). Visibility: search and the `{ingredient:key}` binding (`Route::bind` in `NutritionServiceProvider`, through `Ingredient::scopeVisibleTo`) see the catalog plus the caller's own; another user's ingredient is a 404 everywhere. `IngredientPolicy` (registered with `Gate::policy`, since policies under `app/Modules` aren't auto-discovered) lets only the owner update or delete, so a catalog ingredient is a 403. A user's ingredient has no nutrients, aliases, translations or photos yet; the seeder rejects the `user` source and `u_` keys.
- **Referencing the catalog (later specs):** meal logs, recipes and My foods reference `ingredients`, `ingredient_portions` or `nutrients` by id with a foreign key declared `ON DELETE RESTRICT`, and store the grams eaten at the time they're saved. The seeders remove rows that leave the files by trying a hard delete in a savepoint and soft-deleting when a `RESTRICT` key blocks it, so a reference without `RESTRICT` would lose its row.
- **API docs:** Scramble generates the OpenAPI document from the code (see `specs/02-api-docs-scramble.md`). The UI is at `/docs/api` and the JSON at `/docs/api.json`. Both are reachable **only when `APP_ENV=local`** and return 403 otherwise, so deployed servers must set `APP_ENV=production`. The exported document, `docs/openapi/v1.json`, is committed and is the contract for the client apps. Their repos are expected to be cloned next to this one, and their generators read `../humanOS-backend/docs/openapi/v1.json`. How to document endpoints is in `docs/code-conventions.md`.
- **Redis:** planned for cache and queues. It is not yet in docker-compose (the cache and queue default to the `database` driver).

## Commands

```sh
composer setup             # install deps, create .env, generate key, migrate, build assets
composer dev               # run the local dev stack (php artisan dev)
docker compose up          # app on :8000, mysql, phpmyadmin on :8083, mailpit on :8025; first-time setup in docs/docker.md

# tests use MySQL `laravel_testing`: run them inside the container (docker compose exec app ...)
composer test              # clear config, then run the full suite
php artisan test --filter=SomeTest            # single test class or method
php artisan test tests/Feature/SomeTest.php   # single file
php artisan test --testsuite=Unit             # Unit or Feature

vendor/bin/pint            # format (Laravel Pint)
vendor/bin/pint --test     # check formatting only

# regenerate the committed OpenAPI document after any API change (the drift test fails otherwise)
php artisan scramble:export --path=docs/openapi/v1.json
```

The tests use PHPUnit 12, not Pest.

## Code conventions

Follow `docs/code-conventions.md` for coding rules (Laravel and container usage, API documentation with Scramble). Read it before writing code.

## Specs

Each feature is planned in `specs/NN-<name>.md`. The `Status` in its header moves from Draft to Approved to Implemented. A spec is closed as Implemented once every acceptance criterion has been checked and ticked.

A spec is a record of what was decided and verified, not living documentation. The current state lives in `CLAUDE.md`, `docs/` and `docs/openapi/v1.json`. When a later spec changes something an older spec describes, don't rewrite the older spec's body. Add one line to its header: `> **Note:** <what changed> by SPEC NN.` The one exception is a change to the API contract (paths, request or response shapes): then also update the older spec's endpoint tables and response shapes in place, as SPEC 04 did for SPEC 01 and 02.

<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>
