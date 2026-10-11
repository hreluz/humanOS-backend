# SPEC 01 — API authentication

> **Status:** Approved
> **Depends on:** none
> **Date:** 2026-10-11
> **Objective:** Let any humanOS client register, log in and manage its session through Sanctum bearer tokens under `/api/v1/auth`, including email verification, password reset, per-device token revocation and a basic user/admin role.
> **Note:** Envelope updated by SPEC 04.

## Why this spec exists

Every later feature (profile, nutrition, workouts, dashboard) needs an authenticated user. This spec also introduces two project-wide conventions:

- The first module of the modular monolith (`app/Modules/Auth`).
- The `/api/v1` route prefix.

Later specs should follow both.

## Scope

**In:**

- Install Laravel Sanctum and create `routes/api.php` (via `php artisan install:api`).
- Module convention `app/Modules/<Name>` with a service provider per module, starting with `app/Modules/Auth`.
- Endpoints under `/api/v1/auth`:
  - register, login, logout, me.
  - list tokens, revoke one token, revoke all tokens.
  - email verification (verify, resend).
  - password reset (forgot, reset).
- Configurable token expiry (`SANCTUM_EXPIRATION`, default 30 days) and a scheduled prune of expired tokens.
- `users.role` column (`user` | `admin`), an `admin` route middleware, and the `php artisan users:promote {email}` command.
- Email links that point to the frontend (`FRONTEND_URL`).
- Feature tests for every endpoint, the middleware and the command.

**Out of scope (for future specs):**

- SPA cookie (stateful) authentication.
- Refresh tokens.
- Change-password endpoint for logged-in users.
- Social login (Apple, Google) and two-factor authentication.
- Permissions beyond the `user` / `admin` role, and any admin endpoints.
- Profile data (age, weight, height, goals), which belongs to the Profile & Goals spec.
- Account deletion.
- OpenAPI document generation.
- CORS tuning for production domains.
- Switching to PostgreSQL, or adding Redis.
- Adding a scheduler or queue container to `docker-compose.yml`.

## Data model

### `users` table (existing, one new column)

New migration `database/migrations/2026_10_11_000000_add_role_to_users_table.php`:

```php
$table->string('role', 20)->default('user')->after('password');
```

A plain `string` column keeps the migration portable across SQLite (`.env.example` default), MySQL (docker and tests) and PostgreSQL (target).

### `personal_access_tokens` table

Created by `php artisan install:api` (standard Sanctum migration, unchanged). The token `name` column stores the device name.

### Enum

```php
// app/Modules/Auth/Enums/UserRole.php
enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';
}
```

### `App\Models\User` changes

- Uses `Laravel\Sanctum\HasApiTokens`.
- Implements `Illuminate\Contracts\Auth\MustVerifyEmail`.
- Adds `'role' => UserRole::class` to `casts()`.
- `role` is **not** added to `#[Fillable]`, so it cannot be mass-assigned from a request.
- Adds an `isAdmin(): bool` helper.
- `UserFactory` gets an `admin()` state.

The model stays in `app/Models` because every module uses it.

### Module layout

```
app/Modules/Auth/
├── AuthServiceProvider.php        # loads routes.php under /api/v1/auth, registers commands, middleware alias, URL callbacks
├── routes.php
├── Enums/UserRole.php
├── Console/PromoteUserCommand.php
├── Http/
│   ├── Controllers/
│   │   ├── RegisterController.php
│   │   ├── LoginController.php          # login + logout
│   │   ├── MeController.php
│   │   ├── TokenController.php          # index, destroy, destroyAll
│   │   ├── EmailVerificationController.php  # verify, resend
│   │   └── PasswordResetController.php  # forgot, reset
│   ├── Requests/
│   │   ├── RegisterRequest.php
│   │   ├── LoginRequest.php
│   │   ├── ForgotPasswordRequest.php
│   │   └── ResetPasswordRequest.php
│   ├── Resources/
│   │   ├── UserResource.php
│   │   └── TokenResource.php
│   └── Middleware/EnsureUserIsAdmin.php
```

`AuthServiceProvider` is registered in `bootstrap/providers.php`.

### Endpoints (all prefixed `/api/v1/auth`)

| Method | Path                         | Auth                    | Throttle                 | Success response |
| ------ | ---------------------------- | ----------------------- | ------------------------ | ---------------- |
| POST   | `/register`                  | guest                   | 6/min per IP             | 201 `{ data: { token, user } }` + sends verification email |
| POST   | `/login`                     | guest                   | 5/min per email+IP       | 200 `{ data: { token, user } }` |
| POST   | `/logout`                    | `auth:sanctum`          | —                        | 204, revokes the current token |
| GET    | `/me`                        | `auth:sanctum`          | —                        | 200 `{ data: user }` |
| GET    | `/tokens`                    | `auth:sanctum`          | —                        | 200 `{ data: token[] }` |
| DELETE | `/tokens/{id}`               | `auth:sanctum`          | —                        | 204. Returns 404 if the token is not the user's. |
| DELETE | `/tokens`                    | `auth:sanctum`          | —                        | 204, revokes all of the user's tokens, including the current one |
| GET    | `/email/verify/{id}/{hash}`  | `signed` (no token)     | 6/min                    | 200 `{ message }`. Idempotent if already verified. |
| POST   | `/email/verification-notification` | `auth:sanctum`   | 6/min                    | 202 `{ message }`. Returns 200 if already verified. |
| POST   | `/forgot-password`           | guest                   | 6/min per IP             | 200 generic `{ message }` whether the email exists or not |
| POST   | `/reset-password`            | guest                   | 6/min per IP             | 200 `{ message }` + revokes all of the user's tokens |

### Request payloads

- **register:** `name` (required, string, max 255), `email` (required, email, unique), `password` (required, confirmed, `Password::defaults()`), `device_name` (optional, string, max 255).
- **login:** `email`, `password`, `device_name` (optional).
- **forgot-password:** `email`.
- **reset-password:** `token`, `email`, `password` (confirmed, `Password::defaults()`).

**Device name fallback:** use `device_name` if sent. Otherwise use the `User-Agent` header, truncated to 255 characters. If neither is present, use `"Unknown device"`.

### Response shapes

```jsonc
// UserResource
{ "id": 1, "name": "Ana", "email": "ana@example.com", "role": "user",
  "email_verified_at": null, "created_at": "2026-10-11T12:00:00Z" }

// TokenResource
{ "id": 7, "name": "iPhone 15", "last_used_at": "…", "created_at": "…",
  "expires_at": "…", "is_current": true }

// register / login
{ "data": { "token": "7|plaintext…", "user": { /* UserResource */ } } }
```

Errors use the existing JSON exception rendering:

- 422 for validation errors and bad credentials. A bad login returns `errors.email = ["These credentials do not match our records."]`.
- 401 when unauthenticated.
- 403 from the `verified` and `admin` middleware.
- 429 when throttled.

### Email links

- **Password reset:** `ResetPassword::createUrlUsing` builds `{FRONTEND_URL}/reset-password?token={token}&email={email}`.
- **Verification:** `VerifyEmail::createUrlUsing` takes the temporary signed API URL for `/api/v1/auth/email/verify/{id}/{hash}` (default 60 min expiry). It turns that URL into `{FRONTEND_URL}/verify-email?id=…&hash=…&expires=…&signature=…`. The frontend then calls the API route with the same parameters.

### Configuration

- `.env.example` gains `FRONTEND_URL=http://localhost:3000` and `SANCTUM_EXPIRATION=43200` (minutes).
- `config/app.php` gains `'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')`.
- `config/sanctum.php` reads `'expiration' => env('SANCTUM_EXPIRATION', 43200)`.
- `routes/console.php` schedules `sanctum:prune-expired --hours=24` daily.

### Running the project (Docker)

Docker is the reference environment for manual checks: the `app` container (PHP 8.4, `php artisan serve` on `:8000`) talks to the `mysql` service. First-time setup follows `docs/docker.md`:

1. `cp .env.example .env` and set `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_DATABASE=laravel`, `DB_USERNAME=laravel`, `DB_PASSWORD=secret`.
2. `docker compose run --rm app composer install` and `docker compose run --rm app php artisan key:generate`.
3. `docker compose up -d`, then `docker compose exec app php artisan migrate`.

On macOS, export `HOST_UID=$(id -u) HOST_GID=$(id -g)` before running compose to avoid file-permission issues.

Every `php artisan`, `composer` and `vendor/bin/pint` command in this spec runs inside the container: prefix it with `docker compose exec app`. Tests run against a separate MySQL database, `laravel_testing` (`phpunit.xml`), so dev data in `laravel` is untouched. The `laravel` user only has rights on `laravel`, so the testing database is created once by hand with root:

```sh
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS laravel_testing; GRANT ALL PRIVILEGES ON laravel_testing.* TO 'laravel'@'%';"
```

### Middleware

- `verified`: Laravel's built-in `EnsureEmailIsVerified`, which already returns 403 JSON for API requests. This spec does not apply it to any route. Future feature specs apply it.
- `admin`: an alias for `EnsureUserIsAdmin`. It returns 403 unless `$request->user()?->isAdmin()`. This spec does not apply it to any route.

### Command

`php artisan users:promote {email} {--demote}`:

- Sets `role` to `admin`, or to `user` with `--demote`.
- Exits non-zero with an error message if the email does not exist.

## Implementation plan

All commands below run inside the `app` container (see "Running the project (Docker)").

1. **Install Sanctum.** Run `docker compose exec app php artisan install:api`. This adds `laravel/sanctum`, creates `routes/api.php`, publishes `config/sanctum.php` and the `personal_access_tokens` migration, and registers the api routes in `bootstrap/app.php`. Add `HasApiTokens` to `User`. Wire `SANCTUM_EXPIRATION` into `config/sanctum.php` and `.env.example`. Check: `docker compose exec app php artisan migrate:fresh` succeeds against MySQL and `docker compose exec app composer test` passes.
2. **Module skeleton.** Create `app/Modules/Auth/AuthServiceProvider.php` and an empty `app/Modules/Auth/routes.php`. Register the provider in `bootstrap/providers.php`. The provider loads the routes file with `Route::prefix('api/v1/auth')->middleware('api')`. Remove the default `/user` route that `install:api` adds to `routes/api.php`. Check: `php artisan route:list` runs without errors.
3. **Role.** Add the role migration, the `UserRole` enum, the `role` cast, `isAdmin()`, the factory `admin()` state and `UserResource`. Check: `docker compose exec app php artisan migrate:fresh` succeeds against MySQL, and a unit test confirms that a factory-built user is `UserRole::User` by default.
4. **Register and login.** Add `RegisterRequest`, `LoginRequest`, `RegisterController` and `LoginController@login` with the device-name fallback and throttles. Add the `FRONTEND_URL` config. Tests cover success, validation errors, duplicate email, wrong password (422), throttling (429) and the device-name fallback.
5. **Me and logout.** Add `MeController` and `LoginController@logout`. Tests: `/me` returns the user with a valid token and 401 without one. Logout deletes only the current token, and that token then gets 401.
6. **Token management.** Add `TokenController` and `TokenResource` (with `is_current`). Tests cover the list, `is_current` being correct, revoking one token, 404 when revoking another user's token, and revoking all. Also test that expired tokens get 401: set the expiration config and travel in time.
7. **Email verification.** `User` implements `MustVerifyEmail`. Add the `VerifyEmail::createUrlUsing` callback in the provider, plus `EmailVerificationController` (verify and resend). Tests (`Notification::fake`):
   - Register sends `VerifyEmail` with a frontend URL.
   - A valid signature verifies the user.
   - An invalid or expired signature returns 403.
   - Resend works, and returns 200 if the user is already verified.
   - A test-only route behind `verified` returns 403 for unverified users and 200 for verified ones.
8. **Password reset.** Add the `ResetPassword::createUrlUsing` callback, the two requests and `PasswordResetController`. Tests:
   - Forgot-password sends `ResetPassword` with a frontend URL for an existing email.
   - It returns the same 200 for an unknown email.
   - Reset with a valid token changes the password and deletes all of the user's tokens.
   - An invalid token returns 422.
9. **Admin middleware and command.** Add `EnsureUserIsAdmin`, the `admin` alias and `PromoteUserCommand`, all registered in the provider. Tests:
   - A test-only route behind `admin` returns 403 for a user and 200 for an admin.
   - The command promotes and demotes users.
   - The command fails for an unknown email.
10. **Housekeeping.**
    - Schedule `sanctum:prune-expired` in `routes/console.php`.
    - Add `FRONTEND_URL` to `.env.example`.
    - Update `CLAUDE.md`'s "Current state" section: Sanctum is installed, `routes/api.php` exists, the module convention is `app/Modules/<Name>` with its own service provider, and routes are versioned under `/api/v1`.
    - Update `docs/docker.md`:
      - Add `FRONTEND_URL` and `SANCTUM_EXPIRATION` to the environment-variables table.
      - Add a "Reading emails" note: with `MAIL_MAILER=log`, verification and reset emails land in `storage/logs/laravel.log` (`docker compose exec app tail -n 200 storage/logs/laravel.log`). The log mailer writes the body already decoded, so links work as they are. Use the plain-text copy (the HTML copy escapes `&` as `&amp;`) and strip the trailing `\r` (CRLF lines) when extracting it in a script.
      - Add a "Scheduler" note: the stack has no scheduler container, so `sanctum:prune-expired` only runs when triggered by hand with `docker compose exec app php artisan schedule:run` (or `sanctum:prune-expired` directly).
    - Run `docker compose exec app vendor/bin/pint`.
11. **Docker smoke test.** Run against the `laravel_testing` database, so dev data is untouched. Reset it and start a second server on port 8001 inside the container (`php artisan serve` drops overridden env vars, so use `php -S` directly; `APP_URL` must match the port for the signed link to validate):

    ```sh
    docker compose exec app sh -c 'DB_DATABASE=laravel_testing php artisan migrate:fresh --force'
    docker compose exec -d app sh -c 'echo $$ > /tmp/smoke.pid; cd public && exec env DB_DATABASE=laravel_testing APP_URL=http://localhost:8001 php -S 0.0.0.0:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
    ```

    Then, with `curl` inside the container against `http://localhost:8001` (all requests with `Accept: application/json`):
    1. `POST /api/v1/auth/register` with name, email, password, `password_confirmation` and `device_name` → 201 with a token.
    2. Find the verification link in `storage/logs/laravel.log`. It starts with `http://localhost:3000/verify-email?`. Call `GET /api/v1/auth/email/verify/{id}/{hash}?expires=…&signature=…` with the same values → 200.
    3. In phpMyAdmin (http://localhost:8083) or the MySQL shell, on `laravel_testing`, check that the user has `email_verified_at` set and `role = 'user'`.
    4. `POST /api/v1/auth/login` → 200 with a second token. `GET /api/v1/auth/me` with it → 200. `GET /api/v1/auth/tokens` → two tokens, one `is_current: true`.
    5. `POST /api/v1/auth/logout` → 204. `GET /me` with the same token → 401.
    6. `docker compose exec app sh -c 'DB_DATABASE=laravel_testing php artisan users:promote <email>'` → the `role` column shows `admin`.
    7. Stop the server: `docker compose exec app sh -c 'kill $(cat /tmp/smoke.pid)'`.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes against the `laravel_testing` MySQL database, and `docker compose exec app vendor/bin/pint --test` reports no issues.
- [ ] `docker compose exec app php artisan migrate:fresh` succeeds against the `mysql` service.
- [ ] `docker compose exec app php artisan route:list --path=api/v1/auth` lists exactly the 11 routes in the endpoints table.
- [ ] `POST /api/v1/auth/register` with valid data returns 201 with a `data.token` and a `data.user` whose `role` is `"user"`.
- [ ] `POST /api/v1/auth/register` with `"role": "admin"` in the body still creates a user with `role` `"user"`.
- [ ] Register and login without `device_name` store the `User-Agent` value as the token name.
- [ ] `POST /api/v1/auth/login` with a wrong password returns 422 with an `errors.email` key.
- [ ] The 6th failed login within one minute for the same email and IP returns 429.
- [ ] `GET /api/v1/auth/me` returns 401 without a token and 200 with a valid one.
- [ ] After `POST /logout`, the same token gets 401 on `/me`, and the user's other tokens still work.
- [ ] A token older than `SANCTUM_EXPIRATION` minutes gets 401.
- [ ] `GET /tokens` marks exactly one token as `is_current: true`.
- [ ] `DELETE /tokens/{id}` for another user's token returns 404 and deletes nothing.
- [ ] `DELETE /tokens` leaves the user with zero tokens.
- [ ] The verification email link starts with `FRONTEND_URL` + `/verify-email?`.
- [ ] Calling the verify endpoint with the link's parameters sets `email_verified_at`.
- [ ] Calling the verify endpoint with a tampered signature returns 403.
- [ ] A route with the `verified` middleware returns 403 for an unverified user.
- [ ] `POST /forgot-password` returns the same status and body for an existing and a non-existing email.
- [ ] The reset email link starts with `FRONTEND_URL` + `/reset-password?token=`.
- [ ] A successful `POST /reset-password` lets the user log in with the new password and deletes all previous tokens.
- [ ] A route with the `admin` middleware returns 403 for a `user` and 200 for an `admin`.
- [ ] `docker compose exec app php artisan users:promote ana@example.com` sets the role to `admin`. Adding `--demote` sets it back to `user`. An unknown email exits with a non-zero code.
- [ ] `routes/console.php` schedules `sanctum:prune-expired`.
- [ ] `CLAUDE.md` no longer says Sanctum is not installed or that `routes/api.php` is missing.
- [ ] `docs/docker.md` documents `FRONTEND_URL`, `SANCTUM_EXPIRATION`, how to read emails from the log, and that the scheduler does not run in the stack.
- [ ] The step 11 Docker smoke test completes with the expected status codes at every step.

## Decisions

- **Yes:** Sanctum personal access tokens (bearer) for every client. One mechanism for web, mobile, desktop and watch.
- **No:** SPA cookie auth. It adds a second flow (CSRF, stateful domains) for a modest security gain. It can be added in its own spec.
- **Yes:** fixed, configurable token expiry (default 30 days).
- **No:** refresh tokens. Sanctum has no native support, and a re-login every 30 days is acceptable.
- **Yes:** `app/Modules/<Name>` with a service provider per module. This starts the modular-monolith convention.
- **No:** the standard `app/Http/Controllers/Api` layout, because it would need to be migrated later.
- **Yes:** `User` stays in `app/Models`. It is shared by every module, and moving it breaks factory and auth-config defaults for no gain.
- **Yes:** `/api/v1` prefix. OpenAPI-generated clients pin to a version, so breaking changes can go to `v2`.
- **Yes:** a simple `role` string column with a PHP enum, an `admin` middleware and a promote command.
- **No:** a permissions package (e.g. spatie/laravel-permission). There is no use case beyond admin yet.
- **No:** a native DB enum column. It behaves differently across SQLite, MySQL and PostgreSQL.
- **Yes:** `role` is not mass-assignable. This prevents privilege escalation through the register payload.
- **Yes:** unverified users can log in. A `verified` middleware is provided for feature routes.
- **No:** blocking login until verification. It adds too much friction on mobile.
- **Yes:** email links point to `FRONTEND_URL`. This works for the Nuxt web app now and for mobile deep links later.
- **Yes:** the verification route is authenticated by its signature, not by a token. The user may open the link on a device where they are not logged in.
- **Yes:** optional `device_name` that falls back to `User-Agent`. Clients that forget to send it still get a readable device list.
- **Yes:** a password reset revokes all tokens. A stolen device gets logged out.
- **No:** a change-password endpoint in this spec. It is deferred.
- **Yes:** bad credentials return 422 with `errors.email`, following the Laravel/Breeze API convention. Clients handle it like any validation error.
- **No:** 401 for bad credentials. 401 is reserved for a missing or invalid token, so clients can use it as a "log out locally" signal.
- **Yes:** forgot-password always returns the same response. This prevents account enumeration.
- **Yes:** login throttled at 5/min per email+IP, and the other guest endpoints at 6/min. These are standard Laravel defaults.
- **Yes:** name, email and password at registration. Profile data belongs to the Profile & Goals spec.
- **Yes:** Docker (`app` + `mysql`) is the reference environment for manual checks, and every command in this spec runs through `docker compose exec app`. Running `migrate:fresh` there is what proves the migrations work on MySQL.
- **Yes:** PHPUnit runs against a separate MySQL database, `laravel_testing`. Tests use the same engine as Docker dev, and dev data is never touched. *(Changed during implementation; the first version kept in-memory SQLite.)*
- **No:** in-memory SQLite for tests. It is faster and needs no container, but hides MySQL differences (collation, datetime precision, DDL).
- **Yes:** the testing database is created by hand once. No init script in `docker-compose.yml`.
- **Yes:** a manual curl smoke test in Docker. It covers the real email-link round trip that `Notification::fake` skips.
- **No:** a scheduler or queue container in `docker-compose.yml`. It belongs with the Redis/queues spec. Until then, prune runs by hand.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| The signed verification URL is computed over the API URL. A frontend that rebuilds the call differently (other host, reordered parameters) fails the signature check. | The frontend forwards `id`, `hash`, `expires` and `signature` unchanged to `/api/v1/auth/email/verify/{id}/{hash}`. A test covers the round trip. `APP_URL` must match the host that clients call. |
| `install:api` changes `bootstrap/app.php` and adds a default `/api/user` route. | Step 2 removes the default route. Acceptance checks the exact route list. |
| Mail is not configured in dev or docker. | `.env.example` keeps `MAIL_MAILER=log`, so links appear in `storage/logs/laravel.log` (mounted from the host, also readable inside the container). Tests use `Notification::fake`. |
| The log holds each email twice (plain text and HTML with `&amp;`), with CRLF line endings. A link taken from the HTML part or with a trailing `\r` fails. | `docs/docker.md` shows how to extract the plain-text link and strip `\r`. Do not run `quoted_printable_decode` on it: the body is already decoded, and decoding again corrupts `=XX` sequences. |
| Tests need the Docker stack running. `composer test` on the host fails, because the `mysql` host only resolves on the compose network. | Every command in this spec already runs through `docker compose exec app`. |
| `laravel_testing` is created by hand, so it disappears after `docker compose down -v`. | The create-and-grant command is in `docs/docker.md` ("Tests") and in this spec. |
| `APP_URL` drifts from the Docker host port. If `APP_PORT` changes, signed verification URLs no longer match the host clients call. | `APP_URL` must be `http://localhost:${APP_PORT}`. The default (`8000`) already matches `.env.example`. |
| The compose stack has no scheduler, so `sanctum:prune-expired` never runs in Docker. | Documented in `docs/docker.md`, with a manual `schedule:run`. A scheduler container comes with the Redis/queues spec. |
| Bearer tokens in browser storage are exposed to XSS in the Nuxt app. | This is a known trade-off of the chosen mechanism. Expiry and per-device revocation limit the damage. SPA cookie auth stays available as a future spec. |
| Expired tokens pile up in `personal_access_tokens`. | A daily `sanctum:prune-expired` is scheduled. |

## What is **not** in this spec

- SPA cookie auth and refresh tokens.
- Change-password endpoint.
- Social login and 2FA.
- Permissions beyond user/admin, and admin endpoints.
- Profile data and account deletion.
- OpenAPI generation, CORS tuning, PostgreSQL, Redis.
- A scheduler or queue container in `docker-compose.yml`.

Each one of those, if it lands, goes in its own spec.
