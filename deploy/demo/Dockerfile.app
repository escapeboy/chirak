# Laravel control plane in read-only demo mode (CHIRAK_READONLY=1), served by FrankenPHP on :80.
FROM node:22-alpine AS assets
WORKDIR /app
COPY app/package.json app/package-lock.json ./
RUN npm ci
COPY app/ ./
RUN npm run build

FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_sqlite intl zip opcache pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY app/ ./
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
 && rm -f .env public/hot
COPY deploy/demo/entrypoint.sh /usr/local/bin/chirak-entrypoint
ENV SERVER_NAME=:80 LARAVEL_STORAGE_PATH=/data/storage DB_CONNECTION=sqlite DB_DATABASE=/data/database.sqlite
ENTRYPOINT ["chirak-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
