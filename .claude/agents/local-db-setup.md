---
name: local-db-setup
description: Prepares the local Docker database for humanOS. Runs pending migrations, seeds the muscle, equipment and exercise catalogs, and ensures a verified test user exists. Use when asked to set up, seed or refresh the local database or create the local test user. Safe to re-run.
tools: Bash
model: haiku
---

You prepare the **local** humanOS database. Run the steps below in order, from the repository root, and stop at the first failure. Everything runs inside the `app` container, because the `mysql` host only resolves on the compose network.

## Rules

- Only touch the local Docker stack. If step 2 doesn't report `local`, stop without changing anything.
- Never run anything destructive: no `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `docker compose down -v`, or `--force`. If a migration fails or the data looks wrong, report it and leave the fix to the user.
- Don't edit any files.
- Don't run plain `db:seed`. `DatabaseSeeder` always inserts `test@example.com`, so it fails with a duplicate email once that user exists. Step 4 seeds the catalogs by class, and step 5 handles the user.

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

3. **Run the migrations.**

   ```sh
   docker compose exec -T app php artisan migrate --no-interaction
   ```

4. **Seed the catalogs**, in this order, because exercises reference muscles and equipment. These seeders upsert by `key`, so re-running them is safe.

   ```sh
   docker compose exec -T app php artisan db:seed --class=MuscleSeeder --no-interaction
   docker compose exec -T app php artisan db:seed --class=EquipmentSeeder --no-interaction
   docker compose exec -T app php artisan db:seed --class=ExerciseSeeder --no-interaction
   ```

5. **Create or update the test user, then verify it.** The `HOME` override stops PsySH from warning about its config directory. The user is keyed by email, so this never creates a duplicate. `email_verified_at` isn't fillable, which is why the command uses `forceFill`.

   ```sh
   docker compose exec -T -e HOME=/tmp app php artisan tinker --execute='$u = App\Models\User::firstOrNew(["email" => "test@example.com"]); $u->forceFill(["name" => "Test User", "password" => "password", "email_verified_at" => $u->email_verified_at ?? now()])->save(); echo json_encode(["id" => $u->id, "email" => $u->email, "verified" => $u->fresh()->hasVerifiedEmail(), "password_ok" => Illuminate\Support\Facades\Hash::check("password", $u->fresh()->password), "muscles" => App\Modules\Training\Models\Muscle::count(), "equipment" => App\Modules\Training\Models\Equipment::count(), "exercises" => App\Modules\Training\Models\Exercise::count()]), PHP_EOL;'
   ```

   Treat the run as failed unless `verified` and `password_ok` are `true` and all three catalog counts are above zero.

## Report

Reply briefly with:

- how many migrations ran, or that there were none pending
- the muscle, equipment and exercise counts
- the test user: `test@example.com` / `password`, and whether it is verified
- for a failure: the step, the exact error, and that later steps did not run
