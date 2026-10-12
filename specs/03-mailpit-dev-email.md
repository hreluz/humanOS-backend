# SPEC 03 — Mailpit for local email

> **Status:** Implemented
> **Depends on:** SPEC 01
> **Date:** 2026-10-11
> **Objective:** Add a Mailpit service to the Docker stack so verification and password-reset emails can be viewed rendered in a local web inbox at http://localhost:8025.

## Why this spec exists

SPEC 01 sends verification and password-reset emails. Today the `log` mailer writes them to `storage/logs/laravel.log`, so you can only see them as raw text and have to grep for links. Mailpit catches every email over SMTP and shows it rendered, with clickable links.

This spec also adds `.env.development`, a committed `.env` template for the Docker stack. First-time Docker setup becomes a single `cp` instead of hand-editing `DB_*`.

## Scope

**In:**

- A `mailpit` service in `docker-compose.yml`:
  - It publishes the web UI (8025) and SMTP (1025) ports. Both can be overridden.
  - Messages persist in a named volume.
  - `app` gets `depends_on: mailpit`.
- A committed `.env.development` file. It is `.env.example` with the Docker values filled in: `DB_*` point to `mysql` and `MAIL_*` point to `mailpit`.
- `.env.example` keeps `MAIL_MAILER=log`. Host-run setups without Mailpit keep working.
- `docs/docker.md` updates:
  - the services list,
  - the first-time setup (now `cp .env.development .env`),
  - the port variables,
  - the "Reading emails" section, rewritten with Mailpit first and the log mailer as a fallback.
- `CLAUDE.md` update: the `docker compose up` comment lists Mailpit on :8025. A note says `.env.development` must stay in sync with `.env.example`.

**Out of scope (for future specs):**

- Real email delivery in staging or production (SES, Postmark, etc.).
- Custom or branded email templates.
- Automated tests against Mailpit's API. The test suite keeps `MAIL_MAILER=array` and `Notification::fake()`.
- Auto-loading `.env.development` through `APP_ENV=development`.
- Redis, queued mail, or a queue worker container.

## Data model

This spec adds no database tables or PHP structures. It adds the following config.

### `docker-compose.yml` (additions)

```yaml
  mailpit:
    image: axllent/mailpit:latest
    restart: unless-stopped
    environment:
      MP_DATABASE: /data/mailpit.db
    ports:
      - "${MAILPIT_UI_PORT:-8025}:8025"
      - "${MAILPIT_SMTP_PORT:-1025}:1025"
    volumes:
      - mailpit_data:/data

volumes:
  mysql_data:
  mailpit_data:
```

`app.depends_on` becomes `[mysql, mailpit]`.

### `.env.development` (new, committed)

It is a full copy of `.env.example` with these values changed:

```
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

`APP_KEY` stays empty, and `php artisan key:generate` fills it after copying. `.gitignore` does not ignore `.env.development`, so the file is committed as-is.

## Implementation plan

1. **Mailpit service.** Add the `mailpit` service, the `mailpit_data` volume and `app.depends_on: mailpit` to `docker-compose.yml`. Check: `docker compose config` is valid, `docker compose up -d mailpit` starts the service, and http://localhost:8025 shows an empty inbox.
2. **`.env.development`.** Create it from `.env.example` with the `DB_*` and `MAIL_*` values above. Check: `diff .env.example .env.development` shows only those lines.
3. **Docs.** Update `docs/docker.md`:
   - Add `mailpit` to the services list.
   - Step 1 of first-time setup becomes `cp .env.development .env`. The hand-written `DB_*` block goes away.
   - Add `MAILPIT_UI_PORT` / `MAILPIT_SMTP_PORT` to the compose variables table.
   - Rewrite "Reading emails":
     - The Mailpit inbox comes first.
     - Add a one-line note for host-run apps: `MAIL_HOST=127.0.0.1`.
     - Keep the log mailer and its grep one-liner as a fallback.
   - Mention that `docker compose down -v` also wipes the captured emails.
4. **CLAUDE.md.** Add "mailpit on :8025" to the `docker compose up` comment. Note that `.env.development` must stay in sync with `.env.example`.
5. **Smoke check.** On a stack using a `.env` copied from `.env.development`:
   1. `POST /api/v1/auth/register`. The verification email appears at http://localhost:8025, rendered, with a link to `FRONTEND_URL`.
   2. `POST /api/v1/auth/forgot-password`. The reset email appears.
   3. `docker compose restart mailpit`, then `docker compose down` followed by `up -d`. The emails are still there.

## Acceptance criteria

- [ ] `docker compose config` succeeds, and `docker compose up -d` starts `app`, `mysql`, `phpmyadmin` and `mailpit`.
- [ ] `docker compose up -d app` also starts `mailpit`.
- [ ] http://localhost:8025 serves the Mailpit UI, and SMTP accepts connections on `localhost:1025`.
- [ ] `MAILPIT_UI_PORT` and `MAILPIT_SMTP_PORT` change the published ports.
- [ ] With `.env` copied from `.env.development`, registering a user puts the verification email in Mailpit.
- [ ] With the same `.env`, `forgot-password` puts the reset email in Mailpit.
- [ ] Captured emails survive `docker compose restart mailpit` and `docker compose down` + `up`.
- [ ] Captured emails are gone after `docker compose down -v`.
- [ ] `.env.development` is tracked by git and differs from `.env.example` only in the `DB_*` and `MAIL_*` lines listed above.
- [ ] `.env.example` still has `MAIL_MAILER=log`.
- [ ] `docs/docker.md` uses `cp .env.development .env` for setup and documents the Mailpit inbox first, with the log mailer as a fallback.
- [ ] `CLAUDE.md` lists Mailpit on :8025.
- [ ] `docker compose exec app composer test` still passes.

## Decisions

- **Yes:** Mailpit. It is actively maintained, Laravel Sail ships it by default, and it is a single small image with a UI and an API.
- **No:** MailHog. It is unmaintained, and Mailpit is its de facto successor.
- **No:** Mailtrap or other hosted inboxes. They need an account and send dev emails off the machine.
- **No:** Laravel's mailable preview routes. They only cover Mailables, while SPEC 01 uses notifications, and they don't show what was actually sent.
- **Yes:** `.env.example` keeps `MAIL_MAILER=log`. A host-run `composer dev` without Mailpit would otherwise 500 on register.
- **Yes:** a committed `.env.development` as a full Docker template. Setup is a single `cp`. It is not auto-loaded, so `APP_ENV` stays `local` and SPEC 02's local-only docs gate keeps working.
- **No:** auto-loading `.env.development` through `APP_ENV=development`. It would change `APP_ENV` and break the `local` checks.
- **Yes:** publish both the UI and SMTP ports, and let both be overridden. A host-run app can then send to `127.0.0.1:1025` too.
- **Yes:** a named volume (`mailpit_data`). Emails survive restarts. `down -v` clears them, the same way it clears `mysql_data`.
- **Yes:** `app` depends on `mailpit`. This matches `mysql` and avoids SMTP errors when only `app` is started.
- **No:** automated tests against Mailpit. Tests use the `array` mailer and fakes, and adding a network dependency to the suite isn't worth it for dev tooling.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| `.env.development` drifts from `.env.example` when new variables are added. | The note in `CLAUDE.md` says to update both. Acceptance checks the diff. |
| `axllent/mailpit:latest` ships a breaking change. | It is dev-only tooling. Pin a tag if it ever breaks. |
| Port 1025 or 8025 is already taken on the host. | Use `MAILPIT_SMTP_PORT` / `MAILPIT_UI_PORT`. |
| Someone copies `.env.development` to a server. | It holds only dev values (`secret` passwords, `mailpit` host). Production must use its own `.env`, as it already does. |

## What is **not** in this spec

- Real email delivery outside local development.
- Email template design.
- Automated tests against Mailpit.
- Auto-loading env files per `APP_ENV`.
- Queued mail or Redis.

Each one of those, if it lands, goes in its own spec.
