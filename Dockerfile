# Version tag AND digest: the tag is what Dependabot's docker ecosystem bumps
# (digest alongside), the digest is what makes two builds of one commit the
# same image. The only unpinned links in an otherwise SHA-pinned supply chain.
FROM php:8.5.12-fpm-alpine@sha256:4943e6c8a08008605b470f6171d611a89384bac97188e4b0a7aefd3f793cd040

# Build deps for PHP extensions + the production web stack:
# nginx fronts php-fpm (the base image's actual runtime), supervisor runs both
# su-exec: run.sh drops to www-data for the artisan commands that write to
# the database (see the fs.protected_regular note there).
RUN apk add --no-cache linux-headers nginx supervisor su-exec

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql sockets bcmath pcntl

COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Install dependencies (production only)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions for Laravel. SQLite needs group write on /app/database to
# create journal/WAL files, but root must remain its owner: together with the
# sticky bit this prevents www-data from replacing the root-owned migrations,
# seeders, or factories that the root entrypoint may later execute.
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chown root:www-data /app/database \
    && chmod 1770 /app/database \
    && find /app/database -maxdepth 1 -type f -exec chown www-data:www-data {} +
# public: favicon uploads create files in the webroot, which needs only
# DIRECTORY write — deliberately non-recursive so the shipped files
# (index.php and friends) stay root-owned; nginx additionally executes
# exactly /index.php and no other .php.
RUN chown www-data:www-data /app/public

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
