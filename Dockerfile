# ============================================================
# Etapa 1: dependencias PHP (composer)
# ============================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader

# ============================================================
# Etapa 2: assets frontend (vite)
# ============================================================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources

RUN npm ci --no-audit --no-fund

COPY . .

RUN npm run build

# ============================================================
# Etapa 3: runtime (php-fpm)
# ============================================================
FROM php:8.3-fpm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        unzip \
        git \
        procps \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        zip \
        pcntl \
        bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-enable opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ============================================================
# Etapa 4: coverage (dev/test only — NOT for production)
# Adds PCOV extension for PHPUnit/Pest code coverage.
# Use: docker compose -f docker-compose.yml -f docker-compose.coverage.yml run --rm coverage ...
# ============================================================
FROM runtime AS coverage

RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && pecl clear-cache

RUN echo "pcov.enabled=1" > /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini
