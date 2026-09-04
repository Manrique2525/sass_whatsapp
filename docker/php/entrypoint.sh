#!/bin/sh
set -e

if [ "${APP_ENV:-local}" = "production" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is required in production" >&2
        exit 1
    fi
else
    if [ ! -f ".env" ]; then
        cp ".env.example" ".env"
        php artisan key:generate --force
    fi
fi

# Permisos de storage (volúmenes montados conservan el usuario host)
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

exec "$@"
