FROM php:8.4-fpm-alpine

# Build deps for PHP extensions + the production web stack:
# nginx fronts php-fpm (the base image's actual runtime), supervisor runs both
RUN apk add --no-cache linux-headers nginx supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql sockets bcmath pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Install dependencies (production only)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions for Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-myidlers.conf

ENV APP_ENV=production
EXPOSE 8000

# Hit a real HTTP endpoint: proves the web server answers and the app
# boots (artisan --version passed even when HTTP serving was broken).
# The Host header must be APP_URL's domain — TrustHosts rejects requests
# for any other host (a bare 127.0.0.1 probe 400s and flips the container
# unhealthy while the app works fine through the proxy).
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD H="${APP_URL#*://}"; H="${H%%/*}"; wget --spider -q --header="Host: ${H:-localhost}" http://127.0.0.1:8000/login || exit 1

ENTRYPOINT ["/app/run.sh"]
