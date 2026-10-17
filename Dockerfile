# syntax=docker/dockerfile:1
FROM php:8.3-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip libpq-dev libzip-dev libpng-dev \
        libjpeg62-turbo-dev libfreetype6-dev libonig-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip mbstring intl bcmath pcntl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/researchmate.ini
COPY docker/entrypoint.sh /usr/local/bin/researchmate-entrypoint
RUN chmod +x /usr/local/bin/researchmate-entrypoint

FROM php-base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.js ./
COPY --from=dependencies /var/www/html/vendor vendor
RUN npm run build

FROM php-base AS app
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build public/build
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["researchmate-entrypoint"]
CMD ["apache2-foreground"]

FROM app AS test
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer install --no-interaction --prefer-dist \
    && touch .env \
    && chown -R www-data:www-data bootstrap/cache
USER www-data
CMD ["composer", "ci:check"]

FROM app AS runtime
