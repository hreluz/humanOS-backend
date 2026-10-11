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

## Common commands

```sh
docker compose up -d                          # start in the background
docker compose logs -f app                    # follow app logs
docker compose exec app php artisan <cmd>     # run artisan inside the container
docker compose exec mysql mysql -ularavel -psecret laravel   # MySQL shell
docker compose down                           # stop (keeps DB data)
docker compose down -v                        # stop and delete the DB volume
```

Tests use in-memory SQLite (`phpunit.xml`), so they don't need the MySQL container: `docker compose exec app composer test`.
