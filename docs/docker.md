# Running with Docker

`docker-compose.yml` defines two services:

- **app**: PHP 8.4 CLI (see `Dockerfile`) running `php artisan serve` on port 8000. The project directory is mounted at `/app`, so dependencies are not baked into the image.
- **mysql**: MySQL 8.0 with database `laravel`, user `laravel`, password `secret` (root password `root`). Data persists in the `mysql_data` volume.
- **phpmyadmin**: web UI for the `mysql` service at http://localhost:8083. Log in with `laravel` / `secret` (or `root` / `root`).

## First-time setup

1. Create `.env` and point it at the `mysql` service. `.env.example` defaults to SQLite, so changing `DB_HOST` alone is not enough:

   ```sh
   cp .env.example .env
   ```

   ```
   DB_CONNECTION=mysql
   DB_HOST=mysql
   DB_PORT=3306
   DB_DATABASE=laravel
   DB_USERNAME=laravel
   DB_PASSWORD=secret
   ```

2. Install dependencies and generate the app key (inside the container, or on the host if PHP and Composer are installed locally):

   ```sh
   docker compose run --rm app composer install
   docker compose run --rm app php artisan key:generate
   ```

3. Start the stack and run migrations:

   ```sh
   docker compose up -d
   docker compose exec app php artisan migrate
   ```

The API is then at http://localhost:8000.

## Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_PORT` | `8000` | Host port mapped to the app container |
| `PHPMYADMIN_PORT` | `8083` | Host port mapped to phpMyAdmin |
| `HOST_UID` / `HOST_GID` | `1000` | User the app container runs as. On macOS your UID is usually 501, so set these to avoid file-permission issues: `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up` |

These variables are read by the app from `.env`, not by compose:

| Variable | Default | Purpose |
| --- | --- | --- |
| `FRONTEND_URL` | `http://localhost:3000` | Client app that verification and password-reset email links point to |
| `SANCTUM_EXPIRATION` | `43200` | API token lifetime in minutes (30 days) |

## Reading emails

`.env.example` uses `MAIL_MAILER=log`, so verification and password-reset emails are written to `storage/logs/laravel.log` instead of being sent:

```sh
docker compose exec app tail -n 200 storage/logs/laravel.log
```

The log mailer writes the body already decoded, so links can be used as they are. Each email appears twice: in the plain-text part (use this one) and in the HTML part, where `&` is escaped as `&amp;`. Lines end in CRLF, so strip `\r` when extracting a link in a script:

```sh
docker compose exec app sh -c "grep -o 'http://localhost:3000/verify-email?[^ )\"]*' storage/logs/laravel.log | head -1 | tr -d '\r'"
```

## Scheduler

The stack has no scheduler container, so scheduled tasks (such as the daily `sanctum:prune-expired`) don't run on their own. Trigger them by hand:

```sh
docker compose exec app php artisan schedule:run            # run tasks that are due
docker compose exec app php artisan sanctum:prune-expired --hours=24
```

## Common commands

```sh
docker compose up -d                          # start in the background
docker compose logs -f app                    # follow app logs
docker compose exec app php artisan <cmd>     # run artisan inside the container
docker compose exec mysql mysql -ularavel -psecret laravel   # MySQL shell
docker compose down                           # stop (keeps DB data)
docker compose down -v                        # stop and delete the DB volume
```

## Tests

PHPUnit runs against a separate MySQL database, `laravel_testing` (see `phpunit.xml`), so your dev data in `laravel` is never touched. The `laravel` user only has rights on `laravel`, so create the testing database once with the root account:

```sh
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS laravel_testing; GRANT ALL PRIVILEGES ON laravel_testing.* TO 'laravel'@'%';"
```

Then run the suite inside the container (the `mysql` host only resolves on the compose network, so tests don't run from the host):

```sh
docker compose exec app composer test
```

The database lives in the `mysql_data` volume. After `docker compose down -v`, create it again.
