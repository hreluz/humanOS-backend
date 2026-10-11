# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Backend API for **humanOS**, a fitness and health platform covering nutrition, workouts, health metrics and goals, plus a recommendation engine that answers "what should I do today?". See `README.md` for the full feature list, MVP scope and later phases.

Other clients (Nuxt web, React Native mobile, Tauri desktop, SwiftUI watch) are planned to live in separate apps and use this API through OpenAPI-generated clients. Treat this repo as **API-only**. The Blade/Vite/Tailwind files that remain are skeleton leftovers.

## Current state vs. target architecture

The repo is a Laravel 13 app (PHP ^8.3; the Docker image uses 8.4) in its early stages. Some pieces of the README's planned stack are in place, others are not yet:

- **Database:** the README targets PostgreSQL, but `docker-compose.yml` runs MySQL 8 and `.env.example` defaults to SQLite. Tests run against a separate MySQL database, `laravel_testing` (`phpunit.xml`), so they need the Docker stack and must run inside the `app` container. The testing database is created by hand once (see `docs/docker.md`, "Tests").
- **Auth:** Laravel Sanctum is installed. Clients authenticate with bearer tokens (personal access tokens) issued by `/api/v1/auth` (see `specs/01-api-authentication.md`).
- **Routing:** API routes are versioned under `/api/v1`. `routes/api.php` exists but stays empty: each module's service provider loads its own `routes.php` with its prefix (e.g. `api/v1/auth`) and the `api` middleware. Exceptions render as JSON for `api/*` requests or requests that expect JSON.
- **Structure:** *modular monolith*. Each module lives in `app/Modules/<Name>` with its own service provider (registered in `bootstrap/providers.php`), `routes.php`, and `Http/`, `Console/`, `Enums/` folders as needed. `Auth` is the first module. Shared models such as `User` stay in `app/Models`.
- **Redis:** planned for cache and queues. It is not yet in docker-compose (the cache and queue default to the `database` driver).

## Commands

```sh
composer setup             # install deps, create .env, generate key, migrate, build assets
composer dev               # run the local dev stack (php artisan dev)
docker compose up          # app on :8000, mysql, phpmyadmin on :8083; first-time setup in docs/docker.md

# tests use MySQL `laravel_testing`: run them inside the container (docker compose exec app ...)
composer test              # clear config, then run the full suite
php artisan test --filter=SomeTest            # single test class or method
php artisan test tests/Feature/SomeTest.php   # single file
php artisan test --testsuite=Unit             # Unit or Feature

vendor/bin/pint            # format (Laravel Pint)
vendor/bin/pint --test     # check formatting only
```

The tests use PHPUnit 12, not Pest.

## Code conventions

Follow `docs/code-conventions.md` for coding rules (Laravel and container usage, API documentation with Scramble). Read it before writing code.

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
