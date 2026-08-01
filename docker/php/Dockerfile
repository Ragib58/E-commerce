# syntax=docker/dockerfile:1

###############################################################################
# Base — shared runtime for the FPM, queue worker, and scheduler containers
###############################################################################
FROM php:8.3-fpm-alpine AS base

# `libpq`, `icu`, and `libzip` are runtime shared libraries; their `-dev`
# counterparts are build-only and installed in a virtual package that is
# removed after compilation to keep the final image small.
RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-libs \
        libpq \
        libzip \
        oniguruma \
        freetype \
        libjpeg-turbo \
        libpng \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    # phpredis is the fast C client; predis remains installed via composer as
    # a pure-PHP fallback for environments without the extension.
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as a non-root user whose uid matches the host developer's, so files
# written inside the container (migrations, caches) are editable on the host.
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} -S app \
    && adduser -u ${UID} -S -G app -h /home/app -s /bin/bash app \
    && mkdir -p /home/app/.composer \
    && chown -R app:app /home/app

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

###############################################################################
# Development — bind-mounted source, Xdebug available, no baked-in code
###############################################################################
FROM base AS development

RUN apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-deps \
    && rm -rf /tmp/pear

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/95-xdebug.ini
# Xdebug is installed but off by default — leaving it enabled costs roughly a
# 2x slowdown on every request. Toggle with XDEBUG_MODE=debug in compose.
ENV XDEBUG_MODE=off

# Composer needs a writable home for its cache when running as `app`.
ENV COMPOSER_HOME=/home/app/.composer

USER app

CMD ["php-fpm"]

###############################################################################
# Vendor — production dependency resolution, cached independently of app code
###############################################################################
FROM base AS vendor

COPY backend/composer.json backend/composer.lock* ./

# Dependencies resolve without the application source present; artisan scripts
# would fail, so they are skipped here and run in the final stage.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

###############################################################################
# Production — self-contained image with code and optimised autoloader
###############################################################################
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false

# OPcache is only worth enabling when the code is immutable, which is true
# here and false for the bind-mounted development stage.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/98-opcache.ini

COPY --from=vendor /var/www/html/vendor ./vendor
COPY --chown=app:app backend/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

# Liveness only: dependency checks here would restart the container whenever
# the database is briefly slow, which does not fix the database.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(0);" || exit 1

CMD ["php-fpm"]
