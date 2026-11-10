---
description: Rebuild the local Docker database from scratch. Drops every table, runs all migrations and seeds the catalogs and the test user (migrate:fresh --seed). Destroys all local data.
allowed-tools: Bash(docker compose:*), Bash(HOST_UID=*), Bash(id:*), Bash(test:*)
model: haiku
---

Rebuild the **local** humanOS database from scratch. Run the steps below in order, from the repository root, and stop at the first failure. Everything runs inside the `app` container, because the `mysql` host only resolves on the compose network.

This wipes every table in the local database, including users and anything created through the API. To apply pending migrations and re-seed without losing data, use `/local-execute-migration-seeders` instead.

## Rules

- Only touch the local Docker stack. If step 2 doesn't report `local`, stop without changing anything.
- The only destructive command allowed is the `migrate:fresh --seed` in step 3. Never pass `--force`, never run `db:wipe` or `docker compose down -v`, and never point a command at another database or connection (such as `laravel_testing`).
- Don't edit any files.

## Steps

1. **Check that the stack is running.**

   ```sh
   docker compose ps --status running --services
   ```

   The output must list `app` and `mysql`. If it doesn't, and `.env` exists, start the stack with `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d`. MySQL can take a few seconds to accept connections, so if step 3 can't connect, retry it a few times. If `.env` is missing, stop and point the user to "First-time setup" in `docs/docker.md`.

2. **Check the environment.**

   ```sh
   docker compose exec -T app php artisan env
   ```

3. **Drop every table, migrate and seed.** `DatabaseSeeder` runs the catalog seeders (muscles, equipment, exercises, nutrients, ingredients, in that order) and creates the verified `test@example.com` user with the password `password`.

   ```sh
   docker compose exec -T app php artisan migrate:fresh --seed --no-interaction
   ```

4. **Verify.** The `HOME` override stops PsySH from warning about its config directory.

   ```sh
   docker compose exec -T -e HOME=/tmp app php artisan tinker --execute='$u = App\Models\User::where("email", "test@example.com")->first(); echo json_encode(["user" => (bool) $u, "verified" => $u?->hasVerifiedEmail(), "password_ok" => $u ? Illuminate\Support\Facades\Hash::check("password", $u->password) : false, "users" => App\Models\User::count(), "muscles" => App\Modules\Training\Models\Muscle::count(), "equipment" => App\Modules\Training\Models\Equipment::count(), "exercises" => App\Modules\Training\Models\Exercise::count(), "nutrients" => App\Modules\Nutrition\Models\Nutrient::count(), "ingredients" => App\Modules\Nutrition\Models\Ingredient::count()]), PHP_EOL;'
   ```

   Treat the run as failed unless `user`, `verified` and `password_ok` are `true`, `nutrients` is 29, and the muscle, equipment, exercise and ingredient counts are above zero.

## Report

Reply briefly with:

- that the database was rebuilt, and how many migrations ran
- the muscle, equipment, exercise, nutrient and ingredient counts
- the test user: `test@example.com` / `password`, and whether it is verified
- for a failure: the step, the exact error, and that later steps did not run
