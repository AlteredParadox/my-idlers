#!/bin/sh

# Run setup only if .env file doesn't exist.
if [ ! -e .env.production ]
then
# Fail fast without a provided key: generating one at startup silently
# rotated the key on every unpersisted redeploy, invalidating sessions,
# signed URLs and any encrypted data.
if [ -z "${APP_KEY}" ]; then
    echo "ERROR: APP_KEY is required (e.g. docker run -e APP_KEY=base64:...)." >&2
    echo "Generate one once with: echo \"base64:\$(openssl rand -base64 32)\"" >&2
    exit 1
fi
cat > .env.production << EOF
APP_NAME=MyIdlers
APP_ENV=production
APP_DEBUG=false
APP_KEY=${APP_KEY}

LOG_CHANNEL=stderr

DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

APP_URL=${APP_URL}

CACHE_DRIVER=file
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=${SESSION_SECURE_COOKIE:-false}
QUEUE_CONNECTION=sync
EOF
fi

# Clear and cache config for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations if AUTO_MIGRATE is set; otherwise refuse to boot a database
# with pending migrations — the session middleware queries the sessions table
# on EVERY request, so booting anyway would serve 500s on every page
# (including /login and the healthcheck) with no hint at the cause.
# The check is retried: a database container that starts slower than the
# app (host reboot, compose ordering) must not read as "pending".
if [ "${AUTO_MIGRATE}" = "true" ]; then
    # Same retry as the guard below: a database container that starts
    # slower than the app must not leave a silently-unmigrated boot.
    tries=0
    until php artisan migrate --force; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "ERROR: migrations failed after ${tries} attempts (database unreachable or a migration error above)." >&2
            exit 1
        fi
        echo "Migration attempt failed (database not ready?), retrying (${tries}/10)..." >&2
        sleep 3
    done
else
    tries=0
    until php artisan migrate:status --pending=1 > /dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "ERROR: the database has pending migrations, is not initialized, or is unreachable." >&2
            echo "Set AUTO_MIGRATE=true (recommended), or run the migrations once with:" >&2
            echo "  docker run --rm --env-file <your env file> --entrypoint php <image> artisan migrate --force" >&2
            echo "(docker exec cannot be used here — this container refuses to start until migrated)" >&2
            exit 1
        fi
        echo "Database not ready or not migrated, retrying (${tries}/10)..." >&2
        sleep 3
    done
fi

# SQLite: this script runs as root, so a boot-time migration can leave the
# db (or its journal files) root-owned in the bind-mounted directory —
# php-fpm runs as www-data and then 500s with "readonly database" on the
# first write. Re-assert ownership every boot; harmless for MySQL setups.
chown -R www-data:www-data /app/database

# Hand off to supervisord: php-fpm workers + nginx serving public/ on :8000
# (replaces artisan serve, which is PHP's single-threaded dev server)
exec supervisord -c /etc/supervisord.conf
