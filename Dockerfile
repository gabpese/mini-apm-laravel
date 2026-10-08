# One image that builds the front end and serves the app with FrankenPHP.
# Build: docker build -t mini-apm .
# Run:   docker run --rm -p 8080:8080 -e DEMO_SEED=true -e DEMO_USER_EMAIL=demo@example.com \
#          -e DEMO_USER_PASSWORD=change-me mini-apm
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_sqlite intl bcmath zip opcache

# Node is only needed to build the assets. Vite's Wayfinder plugin also runs PHP while building.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates git unzip libcap2-bin \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so they stay cached until a lock file changes.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && cp .env.example .env \
    && php artisan key:generate \
    && npm run build \
    && rm -rf node_modules .env \
    && composer install --no-interaction --no-dev --optimize-autoloader --prefer-dist

RUN mkdir -p storage/database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

ENV APP_NAME=mini-apm \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    PORT=8080

# The image gives frankenphp a file capability to bind ports below 1024. Hosts that block
# capabilities (Render among them) then refuse to run it. The app listens on 8080, so drop it.
RUN setcap -r /usr/local/bin/frankenphp

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD curl -fs "http://localhost:${PORT}/up" || exit 1

ENTRYPOINT ["entrypoint.sh"]
