#!/bin/sh
set -e

if [ ! -f ".env" ]; then
    cp ".env.example" ".env"
    php artisan key:generate --force
fi

if [ -n "${APP_KEY}" ] && [ "${APP_KEY}" = "" ]; then
    php artisan key:generate --force
fi

# Permisos de storage (volúmenes montados conservan el usuario host)
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

exec "$@"
