#!/bin/sh
set -e

# Fresh clone: install dependencies and create .env so the server can boot.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

# PHP built-in server directly, not "artisan serve": artisan serve drops
# environment variables that also appear in .env, which would discard the
# DB_HOST=postgres override set in docker-compose.yml.
cd public
exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
