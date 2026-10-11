# SPEC 02 — API documentation with Scramble

> **Status:** Approved
> **Depends on:** SPEC 01
> **Date:** 2026-10-11
> **Objective:** Generate a fully documented OpenAPI spec of the `/api/v1` endpoints with Scramble, viewable locally at `/docs/api` and committed as `docs/openapi/v1.json` so client apps can generate their API clients from it.
> **Note:** Envelope updated by SPEC 04.

## Why this spec exists

Every humanOS client (Nuxt, React Native, Tauri, SwiftUI) is planned to use an **OpenAPI-generated client**, so the OpenAPI document is the real contract between this backend and the apps. Scramble builds that document from the code (routes, FormRequests, API Resources, return types). We don't maintain annotations by hand, so it can't drift from what the API actually does.

This spec also sets two project-wide conventions:

- Every new endpoint is documented to the same level as the auth endpoints: a group, a summary, every response status, and examples.
- `docs/openapi/v1.json` is regenerated and committed with every API change, and a test enforces it.

## Scope

**In:**

- Install `dedoc/scramble` and publish `config/scramble.php`.
- Docs UI at `/docs/api` and JSON at `/docs/api.json`, reachable **only in the `local` environment** (Scramble's default `viewApiDocs` gate, not overridden).
- Document info: title `humanOS API`, version `1.0.0`, a short description, and an environment-independent server URL.
- Sanctum bearer security declared once on the document. The guest endpoints of SPEC 01 are explicitly marked as unauthenticated.
- Full documentation of all 11 SPEC 01 endpoints:
  - Grouped under the `Auth` tag.
  - A summary and a description for each operation.
  - Every response status the endpoint can return (success, 401, 403, 404, 422, 429 where applicable), with the body shape.
  - Examples for request bodies and for the `UserResource`, `TokenResource` and `{ data: { token, user } }` responses.
- Exported spec committed at `docs/openapi/v1.json` (`php artisan scramble:export`).
- Feature tests that:
  - fail if the committed file is out of date,
  - check the documented status codes per endpoint,
  - check that the docs are reachable in `local` and blocked in `production`.
- `CLAUDE.md` updated with the docs URL and the export command. The "document every endpoint" convention lives in `docs/code-conventions.md`, linked from `CLAUDE.md`.

**Out of scope (for future specs):**

- Access to the docs outside `local` (admin-only gate, public hosting, a static docs site).
- Generating client code (TypeScript, Swift, etc.). That belongs to each client app's repo.
- Multiple API versions (`v2`) and multiple documents.
- Documenting the test-only routes used by SPEC 01 tests (`verified` and `admin` middleware checks). They are registered only inside tests, so they never appear in the export.
- Swapping the default UI (Stoplight Elements) for another viewer.
- Postman collections or other export formats.

## Data model

This spec adds no database tables or columns. It adds the following files and structures.

### Package and config

- `composer.json`: `dedoc/scramble` in `require` (the docs routes are gated, and an export may be run anywhere).
- `config/scramble.php` (published), with these values changed from the defaults:

```php
'api_path' => 'api',                 // documents every route under /api (today: /api/v1/auth/*)
'info' => [
    'version' => '1.0.0',
    'description' => 'Backend API for humanOS: nutrition, workouts, health metrics, goals and recommendations.',
],
'ui' => [
    'title' => 'humanOS API',
],
'servers' => [
    'Local' => 'api',                // relative URL, so the export does not depend on APP_URL
],
```

### Security configuration

Added in `app/Providers/AppServiceProvider.php` (it applies across modules, so it does not belong to `AuthServiceProvider`):

```php
Scramble::configure()
    ->withDocumentTransformers(function (OpenApi $openApi) {
        $openApi->secure(SecurityScheme::http('bearer'));
    });
```

Guest endpoints (`register`, `login`, `email/verify/{id}/{hash}`, `forgot-password`, `reset-password`) carry `@unauthenticated` in their controller-method PHPDoc. The verify endpoint is documented as authenticated by its signature (`expires` and `signature` query parameters), not by a token.

### Per-endpoint documentation

Every SPEC 01 controller class has the `#[Group('Auth')]` attribute. Every controller method has a PHPDoc with:

- a one-line summary and a longer description,
- `@unauthenticated` where it applies,
- `@response` / `@throws` entries for any status Scramble does not infer by itself.

The expected documented responses (the contract the tests check):

| Operation                                    | Documented statuses       |
| -------------------------------------------- | ------------------------- |
| `POST /v1/auth/register`                     | 201, 422, 429             |
| `POST /v1/auth/login`                        | 200, 422, 429             |
| `POST /v1/auth/logout`                       | 204, 401                  |
| `GET /v1/auth/me`                            | 200, 401                  |
| `GET /v1/auth/tokens`                        | 200, 401                  |
| `DELETE /v1/auth/tokens/{id}`                | 204, 401, 404             |
| `DELETE /v1/auth/tokens`                     | 204, 401                  |
| `GET /v1/auth/email/verify/{id}/{hash}`      | 200, 403, 429             |
| `POST /v1/auth/email/verification-notification` | 200, 202, 401, 429     |
| `POST /v1/auth/forgot-password`              | 200, 422, 429             |
| `POST /v1/auth/reset-password`               | 200, 422, 429             |

Paths are relative to the `api` server, matching how Scramble writes them.

### Examples

- `UserResource` and `TokenResource`: PHPDoc examples on each field (e.g. `email` → `ana@example.com`, `name` → `iPhone 15`) so the schemas render with realistic values.
- `RegisterRequest`, `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`: `@example` on each validated field.
- Error bodies follow the existing JSON exception rendering (`{ "message": "…", "errors": { … } }` for 422, `{ "message": "…" }` for the others).

### Committed spec

```
docs/openapi/v1.json   # output of: php artisan scramble:export --path=docs/openapi/v1.json
```

The file is pretty-printed JSON and is regenerated by hand after any API change.

Client apps read the file through a **relative path**: their repos are cloned next to this one, and their generators point at `../humanOS-backend/docs/openapi/v1.json`. The file is only read at development time, when a client's API code is generated. Running apps never load it. Setting up each client's generator belongs to that client's own spec.

### Tests

```
tests/Feature/Docs/OpenApiDocumentTest.php
```

- **Drift:** export to a temporary path and assert it is identical to `docs/openapi/v1.json`. On failure, the message names the command to run.
- **Coverage:** load the generated document and assert that every operation in the table above exists, has the `Auth` tag and a non-empty summary, and documents exactly the listed statuses.
- **Security:** the guest operations have `security: []`, and every other operation inherits bearer security.
- **Access:** `GET /docs/api` and `GET /docs/api.json` return 200 when the environment is `local` and 403 when it is `production`.

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`), following SPEC 01's Docker conventions. This spec starts only once SPEC 01 is `Implemented`.

1. **Install Scramble.** `composer require dedoc/scramble`, then `php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"`. Check: the package resolves against Laravel 13, `composer test` still passes, and `http://localhost:8000/docs/api` renders with the 11 auth endpoints (inferred, unpolished).
2. **Document info and servers.** Set `api_path`, `info`, `ui.title` and the relative `servers` entry in `config/scramble.php`. Check: `/docs/api.json` has `info.title = "humanOS API"`, `info.version = "1.0.0"` and `servers[0].url = "api"`.
3. **Bearer security.** Add the `Scramble::configure()` document transformer in `AppServiceProvider`. Mark the five guest methods `@unauthenticated`. Check: the UI shows a bearer token input, and the guest endpoints show no lock.
4. **Groups and summaries.** Add `#[Group('Auth')]` to every Auth controller, and a summary plus a description PHPDoc to every method. Check: the UI shows a single "Auth" section with readable operation names.
5. **Responses.** Make each operation document exactly the statuses in the table. Rely on inference where Scramble already produces the status (validation → 422, `auth:sanctum` → 401). Add `@response` / `@throws` for the rest (204 bodies, 202 vs 200 on resend, 404 on revoking another user's token, 403 for a bad signature, 429 throttles). Check: `/docs/api.json` matches the table by hand.
6. **Examples.** Add field examples to `UserResource`, `TokenResource` and the four FormRequests. Make sure the `{ data: { token, user } }` response of register/login renders as an object with both keys. Check: every request body and success response in the UI shows realistic example values.
7. **Export.** Run `php artisan scramble:export --path=docs/openapi/v1.json` and commit the file. Check: the file exists and is valid JSON.
8. **Tests.** Add `tests/Feature/Docs/OpenApiDocumentTest.php` with the drift, coverage, security and access tests. Check: `composer test` passes. Changing a summary without re-exporting makes the drift test fail.
9. **CI check.** No workflow change: `.github/workflows/tests.yml` already runs `composer test` on every pull request and on pushes to `master`, so the drift, coverage, security and access tests run in CI. Push the branch and open the PR. Check: the `Tests` workflow passes on GitHub, including the drift test, which confirms the export is identical on the runner (relative server URL, no `APP_URL` dependency).
10. **Housekeeping.**
   - `CLAUDE.md`:
     - Add `php artisan scramble:export --path=docs/openapi/v1.json` to Commands.
     - Note that the docs live at `/docs/api` (local only).
     - Note that client repos are expected next to this one and read `../humanOS-backend/docs/openapi/v1.json`.
     - Link `docs/code-conventions.md`, which holds the convention: every new endpoint follows Scramble's documentation and gets a group, a summary, all response statuses and examples, and the export is regenerated in the same PR. (Done early: the file was created before step 9, and the existing CLAUDE.md conventions moved there.)
   - Run `vendor/bin/pint`.
11. **Docker smoke check.** With the stack up:
    1. Open `http://localhost:8000/docs/api`. It shows "humanOS API" with one "Auth" group of 11 operations.
    2. Use "Try it" on `POST /v1/auth/register` → 201 with a token.
    3. Paste the token into the bearer input, then try `GET /v1/auth/me` → 200.
    4. Set `APP_ENV=production` in `.env` and reload `/docs/api` → 403. Restore `APP_ENV=local` afterwards.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes, and `docker compose exec app vendor/bin/pint --test` reports no issues.
- [ ] `composer.json` requires `dedoc/scramble`, and `config/scramble.php` exists.
- [ ] `GET /docs/api` and `GET /docs/api.json` return 200 with `APP_ENV=local` and 403 with `APP_ENV=production`.
- [ ] `/docs/api.json` has `info.title` `"humanOS API"`, `info.version` `"1.0.0"` and a relative server URL `"api"`.
- [ ] The document declares a bearer HTTP security scheme applied globally.
- [ ] `register`, `login`, `email/verify/{id}/{hash}`, `forgot-password` and `reset-password` have `security: []`. Every other auth operation does not.
- [ ] The document contains exactly the 11 operations in the table, all tagged `Auth`, each with a non-empty summary.
- [ ] Each operation documents exactly the response statuses listed in the table.
- [ ] `UserResource`, `TokenResource` and the register/login `{ data: { token, user } }` responses show example values in the schema.
- [ ] Every field of the four auth FormRequests has an example value.
- [ ] `docs/openapi/v1.json` is committed and identical to a fresh `php artisan scramble:export`.
- [ ] Editing any endpoint's PHPDoc without re-exporting makes `composer test` fail with a message naming the export command.
- [ ] No test-only route appears in `docs/openapi/v1.json`.
- [ ] The `Tests` GitHub Actions workflow passes on the PR, drift test included.
- [ ] `CLAUDE.md` documents the docs URL and the export command, and links `docs/code-conventions.md`, which holds the "follow Scramble, document every endpoint" convention.
- [ ] The step 11 Docker smoke check completes with the expected results.

## Decisions

- **Yes:** Scramble. It infers the spec from code, which is the closest Laravel equivalent to FastAPI's `/docs`.
- **No:** L5-Swagger (swagger-php). Every endpoint would need hand-written attributes, and those drift from the code.
- **No:** Scribe. It is aimed at docs sites first, and its OpenAPI output is secondary.
- **Yes:** docs only in `local`, using Scramble's default gate. A deployed server exposes no API map.
- **No:** an admin-only gate in production. The docs are a browser route, and bearer tokens don't fit browser navigation, so it would need extra auth plumbing for little gain.
- **Yes:** commit `docs/openapi/v1.json`. Client repos get a reviewable contract without running the backend, and API changes show up in PR diffs.
- **No:** fetching the spec from a running server. Nothing would record or check changes to the contract.
- **Yes:** client apps reach the committed file through a relative path (`../humanOS-backend/docs/openapi/v1.json`), with the repos cloned side by side. It is the simplest option for a single developer, and needs no token or publishing step.
- **No:** a raw GitHub URL, a git submodule or a published package for now. They can be revisited if more developers or teams join.
- **Yes:** a drift test inside `composer test`. The existing `Tests` workflow runs `composer test` on every PR, so the same test gates both local runs and CI, with no extra workflow step.
- **Yes:** a relative server URL (`api`). The export stays identical across machines and environments, which keeps the drift test deterministic.
- **Yes:** this spec depends on SPEC 01 being finished. The first committed document then covers the full auth surface, and the coverage test can be exact.
- **Yes:** full documentation (every status plus examples). The generated clients are only as good as the document, and typed error handling needs the error statuses.
- **No:** pure inference. Scramble cannot see throttle 429s, 204 bodies or the 202/200 split, so clients would be generated with gaps.
- **Yes:** bearer security declared once globally, with explicit `@unauthenticated` on guest endpoints. There is less repetition, and forgetting to mark an endpoint errs on the safe side (it shows as protected).
- **Yes:** the Scramble configuration lives in `AppServiceProvider`. It is cross-cutting, and a future `Docs` module would be overkill for one transformer.
- **Yes:** one `Auth` tag per module. Future modules (Nutrition, Workouts…) get their own tags.
- **No:** client code generation in this repo. Each client app owns its generator and its toolchain.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Scramble may not yet support Laravel 13, or may break on a minor upgrade. | Step 1 checks compatibility before any other work. Pin the minor version in `composer.json` if needed. |
| Inference gets a shape wrong (e.g. the `{ data: { token, user } }` response, or a resource with conditional fields). | Step 5/6 checks every operation by hand, and the coverage test locks the status codes. Fix wrong shapes with explicit `@response` PHPDoc. |
| Export output is not deterministic (key order, environment-dependent values), so the drift test is flaky. | Relative server URL. Run the export in the same test environment as the comparison. If ordering still varies, compare decoded JSON instead of raw text. |
| Developers forget to re-export after an API change. | The drift test fails `composer test` with the exact command to run, locally and in the `Tests` workflow on every PR. |
| The docs gate depends on `APP_ENV`. A server misconfigured as `local` exposes the docs. | Documented in `CLAUDE.md`. Production deployments must set `APP_ENV=production`, which SPEC 01 already relies on. |
| Full documentation adds PHPDoc upkeep on every endpoint. | The coverage test for each new module catches missing statuses, and the convention in `docs/code-conventions.md` sets the expectation. |

## What is **not** in this spec

- Docs access outside `local`, public hosting or a static docs site.
- Client code generation.
- API `v2` or multiple documents.
- An alternative docs UI or Postman exports.

Each one of those, if it lands, goes in its own spec.
