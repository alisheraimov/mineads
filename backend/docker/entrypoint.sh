#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

composer install --no-interaction --prefer-dist

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --ansi
fi

php artisan migrate --force

exec "$@"
