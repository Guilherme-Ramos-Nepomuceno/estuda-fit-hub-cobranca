# syntax=docker/dockerfile:1

# Runtime comum: FrankenPHP (PHP 8.5) + extensões usadas pelo serviço.
FROM dunglas/frankenphp:1-php8.5-alpine AS base
RUN install-php-extensions pdo_mysql pcntl
WORKDIR /app
EXPOSE 8081
ENTRYPOINT ["sh", "docker/entrypoint.sh"]

# Desenvolvimento e testes: Composer e dependências de dev.
FROM base AS dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize && cp .env.example .env && php artisan package:discover --ansi

# Build de produção: só dependências de produção e autoload fechado.
FROM dev AS build
RUN --mount=type=cache,target=/tmp/composer-cache \
    rm -rf vendor tests .env bootstrap/cache/*.php \
 && composer install --no-dev --no-interaction --prefer-dist --no-scripts --classmap-authoritative \
 && php artisan package:discover --ansi

# Produção: sem Composer, php.ini de produção, OPcache sem checar arquivos, usuário sem privilégios.
FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'opcache.validate_timestamps=0\nopcache.memory_consumption=128\n' > "$PHP_INI_DIR/conf.d/zz-opcache.ini" \
 && adduser -D -u 1000 app \
 && chown -R app:app /data/caddy /config/caddy
COPY --from=build --chown=app:app /app /app
ENV MIGRAR_NA_SUBIDA=0
USER app
