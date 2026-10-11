# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Backend API for **humanOS**, a fitness and health platform covering nutrition, workouts, health metrics and goals, plus a recommendation engine that answers "what should I do today?". See `README.md` for the full feature list, MVP scope and later phases.

Other clients (Nuxt web, React Native mobile, Tauri desktop, SwiftUI watch) are planned to live in separate apps and use this API through OpenAPI-generated clients. Treat this repo as **API-only**. The Blade/Vite/Tailwind files that remain are skeleton leftovers.

## Current state vs. target architecture

The repo is still close to a fresh Laravel 13 skeleton (PHP ^8.3; the Docker image uses 8.4). Several pieces in the README's planned stack are not in place yet:

- **Database:** the README targets PostgreSQL, but `docker-compose.yml` runs MySQL 8 and `.env.example` defaults to SQLite. Tests use in-memory SQLite (`phpunit.xml`).
- **Auth:** Laravel Sanctum is planned but not installed.
- **Routing:** there is no `routes/api.php` yet, and `bootstrap/app.php` registers only the `web` and `console` routes. Exceptions already render as JSON for `api/*` requests or requests that expect JSON.
- **Structure:** the target is a *modular monolith*, but no module layout exists yet. Ask before introducing a module convention.
- **Redis:** planned for cache and queues. It is not yet in docker-compose (the cache and queue default to the `database` driver).

## Commands

```sh
composer setup             # install deps, create .env, generate key, migrate, build assets
composer dev               # run the local dev stack (php artisan dev)
docker compose up          # app on :8000 plus mysql (set DB_HOST=mysql in .env)

composer test              # clear config, then run the full suite
php artisan test --filter=SomeTest            # single test class or method
php artisan test tests/Feature/SomeTest.php   # single file
php artisan test --testsuite=Unit             # Unit or Feature

vendor/bin/pint            # format (Laravel Pint)
vendor/bin/pint --test     # check formatting only
```

The tests use PHPUnit 12, not Pest.

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
