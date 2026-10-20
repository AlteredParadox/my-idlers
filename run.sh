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
# Real queue, not sync: password-reset mail is dispatched to it so the SMTP
# conversation leaves the public request (see docker/supervisord.conf). The
# worker is supervised alongside php-fpm and nginx.
QUEUE_CONNECTION=database
EOF
fi

# Clear and cache config for production. The app cache is cleared too:
# it holds only rebuildable query snapshots, and a stale entry cached by
# an older release (e.g. a pre-fix settings model) must not survive an
# image upgrade on setups that persist the storage directory.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan cache:clear > /dev/null 2>&1 || true

# SQLite ownership, re-asserted every boot (harmless for MySQL setups):
# /app/database stays root-owned, group-writable and sticky so www-data can
# create journal files but cannot replace the root-owned migrations, seeders
# or factories under it; the top-level files (the database and its journals)
# belong to www-data, which is what php-fpm runs as.
#
# Deliberately NOT -R over the whole tree, for the reason above.
chown root:www-data /app/database
chmod 1770 /app/database
find /app/database -maxdepth 1 -type f -exec chown www-data:www-data {} +

# Artisan commands that write to the database run as www-data, never as
# root. In a sticky, group-writable directory the kernel's
# fs.protected_regular guard (2 on current Debian/Ubuntu hosts; it is a host
# sysctl, so it applies inside the container) refuses an O_CREAT open of a
# file by anyone but the file's owner — root included. SQLite always opens
# its database with O_CREAT, silently falls back to read-only on that EACCES,
# and the first write then fails with "attempt to write a readonly database".
# That is exactly what the ap.18 boot-time migration hit on every SQLite
# install (the database file is www-data's, the migration ran as root). A
# container started with --user is already unprivileged and runs as is.
as_www_data() {
    if [ "$(id -u)" = "0" ]; then
        su-exec www-data "$@"
    else
        "$@"
    fi
}

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
    until as_www_data php artisan migrate --force; do
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
    until as_www_data php artisan migrate:status --pending=1 > /dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "ERROR: the database has pending migrations, is not initialized, or is unreachable." >&2
            echo "Set AUTO_MIGRATE=true (recommended), or run the migrations once with:" >&2
            echo "  docker run --rm --user 82 --env-file <your env file> <your volume flags> --entrypoint php <image> artisan migrate --force" >&2
            echo "(docker exec cannot be used here — this container refuses to start until migrated)" >&2
            exit 1
        fi
        echo "Database not ready or not migrated, retrying (${tries}/10)..." >&2
        sleep 3
    done
fi

# Hand off to supervisord: php-fpm workers + nginx serving public/ on :8000
# (replaces artisan serve, which is PHP's single-threaded dev server)
exec supervisord -c /etc/supervisord.conf
