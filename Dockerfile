FROM php:8.4-fpm-alpine

# Install dependencies for PHP extensions
RUN apk add --no-cache linux-headers

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql sockets bcmath pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Install dependencies (production only)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions for Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

ENV APP_ENV=production
EXPOSE 8000

# Hit a real HTTP endpoint: proves the web server answers and the app
# boots (artisan --version passed even when HTTP serving was broken)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget --spider -q http://127.0.0.1:8000/login || exit 1

ENTRYPOINT ["/app/run.sh"]
