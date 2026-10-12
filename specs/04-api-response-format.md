# SPEC 04 — API response format

> **Status:** Implemented
> **Depends on:** SPEC 01, SPEC 02
> **Date:** 2026-10-11
> **Objective:** Define one snake_case response envelope for the whole API, write it down in `docs/code-conventions.md`, enforce it with a test on the OpenAPI document, and align the `Auth` login and register responses with it.

## Why this spec exists

`Auth` is the only module, and its endpoints already return four shapes. `/me` and `/tokens` wrap their body in `data`. `/login` and `/register` return `{ token, user }` with no wrapper. A client reads the same user object from two different places.

Every new module (Nutrition, Workouts…) would copy whichever shape it finds first. Fixing it now costs one module and no released clients. Later it costs a breaking change in every client app.

## Scope

**In:**

- The response convention, written in a new "API responses" section of `docs/code-conventions.md`:
  - `snake_case` keys in responses **and** request bodies.
  - The envelope table and the rules below (see Data model).
- A new `AuthTokenResource` for the login and register bodies, so they return `{ "data": { "token": "...", "user": { ... } } }`.
- `LoginController` and `RegisterController` return it (200 and 201).
- `tests/Feature/Auth/LoginTest.php` and `tests/Feature/Auth/RegisterTest.php` read `data.token` / `data.user.*`.
- A new test in `tests/Feature/Docs/OpenApiDocumentTest.php`: every 2xx JSON response has a top-level `data` or `message` key.
- `docs/openapi/v1.json` re-exported.
- SPEC 01 and SPEC 02 updated in place where they describe `{ token, user }`, each with a one-line note pointing to SPEC 04.

**Out of scope (for future specs):**

- camelCase output or any key-casing middleware.
- A machine-readable error `code` field, or any change to Laravel's error shape.
- Pagination. The convention names Laravel's paginator shape, but no endpoint paginates yet.
- A `success` / `status` field in bodies.
- Changes to `/me`, `/tokens`, logout, email verification or password reset. They already follow the convention.
- Client code generation or client-side key renaming.

## Data model

This spec adds no database tables or columns.

### The convention (goes into `docs/code-conventions.md`)

| Case | Status | Body |
|---|---|---|
| Single object | 200/201 | `{ "data": { ... } }` |
| List | 200 | `{ "data": [ ... ] }` |
| Paginated list (future) | 200 | `{ "data": [...], "links": {...}, "meta": {...} }` via `Resource::collection($paginator)` |
| Composite (login/register) | 200/201 | `{ "data": { "token": "...", "user": { ... } } }` |
| Action with no resource | 200/202 | `{ "message": "..." }` |
| Delete / logout | 204 | no body |
| Error | 4xx/5xx | Laravel default: `{ "message": "..." }`, plus `"errors": { "field": [...] }` on 422 |

Rules:

- Keys are `snake_case`, in responses and in request bodies.
- The HTTP status carries success or failure. No `success`, `status` or `code` fields.
- Timestamps are ISO-8601 UTC with a `Z` suffix (`toIso8601ZuluString()`), or `null`.
- Enums are sent as their raw value.
- Bodies are built with API Resources, so they get the `data` wrapper and Scramble infers them. `{ "message": ... }` bodies are the only exception.

### `AuthTokenResource` (new)

`app/Modules/Auth/Http/Resources/AuthTokenResource.php`, a `JsonResource` wrapping the `User`, built with the plain-text token:

```php
new AuthTokenResource($user, $plainTextToken);

// toArray():
[
    /** @example 1|xR8nQ2vLk9Tz4WmP7bYc3HdF6gJs1NaE5uVo0iKq6b79c89d */
    'token' => $this->token,
    'user'  => new UserResource($this->resource),
]
```

- `LoginController::login()` returns it (200).
- `RegisterController::__invoke()` returns it with `->response()->setStatusCode(201)`.
- The `@example` moves from the controllers into the resource.

### Envelope test

`OpenApiDocumentTest::test_success_responses_use_the_envelope`:

- Loads `docs/openapi/v1.json`.
- For each operation and each 2xx response with an `application/json` body, resolves the schema (following `$ref` into `components`).
- Asserts the top-level properties include `data` or `message`.
- 204 responses have no body and are skipped.

## Implementation plan

All commands run inside the `app` container (`docker compose exec app …`). Work on a branch from `master`, not `spec-03-mailpit-dev-email`.

1. **Envelope test first.** Add `test_success_responses_use_the_envelope` to `OpenApiDocumentTest`. Check: it fails, naming `POST /v1/auth/register` and `POST /v1/auth/login`, and every other test passes.
2. **`AuthTokenResource`.** Create the resource and switch `LoginController` and `RegisterController` to it. Update `LoginTest` and `RegisterTest` to `data.token` / `data.user.*` (including `->json('data.token')` in `RegisterTest`). Check: the Auth tests pass.
3. **Re-export.** `php artisan scramble:export --path=docs/openapi/v1.json`. Check: login 200 and register 201 show `data.token` (with the example) and `data.user`. `composer test` passes, envelope and drift tests included.
4. **Convention doc.** Add the "API responses" section to `docs/code-conventions.md` with the table and the rules.
5. **Old specs.**
   - SPEC 01: endpoint table rows for `/register` and `/login` become `{ data: { token, user } }`. The "register / login" block under Response shapes becomes `{ "data": { "token": ..., "user": ... } }`. Add a note: "Envelope updated by SPEC 04."
   - SPEC 02: replace `{ token, user }` with `{ data: { token, user } }` (scope, step 6, acceptance criteria, risks) and add the same note.
6. **Housekeeping.** `vendor/bin/pint`. Check: `vendor/bin/pint --test` is clean.
7. **Smoke check.** With the stack up, `curl -X POST localhost:8000/api/v1/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":...,"password":...}'` returns `{"data":{"token":"...","user":{...}}}`.

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes, and `docker compose exec app vendor/bin/pint --test` reports no issues.
- [ ] `POST /api/v1/auth/register` returns 201 with `data.token` and `data.user`, and no top-level `token` or `user`.
- [ ] `POST /api/v1/auth/login` returns 200 with `data.token` and `data.user`, and no top-level `token` or `user`.
- [ ] `app/Modules/Auth/Http/Resources/AuthTokenResource.php` exists, and both controllers return it.
- [ ] `docs/openapi/v1.json` is identical to a fresh export, and its login/register success schemas have `data.token` (with an example) and `data.user`.
- [ ] `test_success_responses_use_the_envelope` exists and passes.
- [ ] Reverting either controller to a top-level `{ token, user }` body and re-exporting makes that test fail.
- [ ] `docs/code-conventions.md` has an "API responses" section with the envelope table, snake_case for requests and responses, and the no-`success`-field, timestamp and enum rules.
- [ ] `specs/01-api-authentication.md` and `specs/02-api-docs-scramble.md` no longer describe an unwrapped `{ token, user }`, and both point to SPEC 04.
- [ ] `/me`, `/tokens`, logout, email verification and password reset responses are unchanged.

## Decisions

- **Yes:** `snake_case`. It's Laravel's native casing (Eloquent columns, FormRequest rules, validation `errors` keys). The contract and the tests already use it. Stripe, GitHub and Slack use it too.
- **No:** camelCase. Every request and response would need a mapping, or validation errors (`errors.device_name`) would disagree with the payload (`deviceName`).
- **Yes:** client generators handle casing (swift-openapi-generator emits camelCase with `CodingKeys`). The API doesn't change for client cosmetics.
- **Yes:** Laravel's resource `data` wrapper everywhere, including composites. One place to read the payload, and Scramble infers it.
- **Yes:** `{ "message": ... }` stays unwrapped for actions with no resource. There is no resource to wrap, and it matches Laravel's error shape.
- **Yes:** a dedicated `AuthTokenResource`. The composite gets a named schema in the OpenAPI document and its `data` wrapper comes from the resource, like every other body.
- **No:** inline `response()->json(['data' => ...])`. Less code, but the composite would be the one body built by hand.
- **Yes:** enforce the envelope with a test on the exported document. Future modules can't drift silently, and the check runs in CI through `composer test`.
- **No:** convention in docs only. It relies on reviewers remembering.
- **Yes:** keep Laravel's error shape. Scramble already documents it, and clients get one predictable form.
- **No:** a machine-readable error `code` for now. Deferred until a client needs it.
- **No:** `success` / `status` fields. The HTTP status already says it.
- **Yes:** update SPEC 01 and SPEC 02 in place. They are the endpoint reference, so they should match the code.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Scramble doesn't infer the 201 from `->response()->setStatusCode(201)`, or misses the `token` example. | Step 3 checks the export by hand. Fall back to a `@status 201` / `@response` PHPDoc on the controller if needed. The existing coverage test locks 201. |
| Scramble emits the resource schema as a `$ref`, so the envelope test can't see `data`. | The test resolves `$ref` into `components.schemas` before checking keys. |
| A client already built against `{ token, user }` breaks. | No client is released yet. This is the reason to do it now. |

## What is **not** in this spec

- camelCase or key-casing middleware.
- An error `code` field or a custom error shape.
- Pagination.
- Changes to endpoints that already follow the convention.
- Client code generation.

Each one of those, if it lands, goes in its own spec.
