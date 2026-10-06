#!/bin/sh
# Prepares the app at start-up, then hands over to the web server.
set -e
cd /app

# Without a key, make a throwaway one. Sessions then end with each restart.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
fi

# Render tells the app its public address. Use it unless APP_URL was set by hand.
if [ -z "$APP_URL" ]; then
    APP_URL="${RENDER_EXTERNAL_URL:-http://localhost:${PORT}}"
    export APP_URL
fi

# The SQLite file lives on the container's disk, so it starts empty after a restart.
touch "$DB_DATABASE"
php artisan migrate --force --no-interaction

# A demo fills itself: the account, a project with weeks of fictional usage, and its public key.
if [ "$DEMO_SEED" = "true" ]; then
    php artisan db:seed --force --no-interaction
    if [ -n "$DEMO_API_KEY" ]; then
        php artisan apm:simulate --fresh --user="$DEMO_USER_EMAIL" --api-key="$DEMO_API_KEY" --no-interaction
    else
        php artisan apm:simulate --fresh --user="$DEMO_USER_EMAIL" --no-interaction
    fi
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec frankenphp php-server --root /app/public --listen ":${PORT}"
