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
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=${SESSION_SECURE_COOKIE:-false}
QUEUE_CONNECTION=sync
EOF
fi

# Clear and cache config for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations if AUTO_MIGRATE is set
if [ "${AUTO_MIGRATE}" = "true" ]; then
    php artisan migrate --force
fi

# Hand off to supervisord: php-fpm workers + nginx serving public/ on :8000
# (replaces artisan serve, which is PHP's single-threaded dev server)
exec supervisord -c /etc/supervisord.conf
