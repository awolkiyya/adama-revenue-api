FROM php:8.3-fpm

# =========================================================
# System dependencies + PHP extensions
# =========================================================

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        calendar \
        intl \
        opcache \
    && rm -rf /var/lib/apt/lists/


# =========================================================
# Composer
# =========================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# =========================================================
# Application
# =========================================================

WORKDIR /var/www


# =========================================================
# Composer dependencies
# =========================================================
# Copy dependency files first for Docker layer caching.
# =========================================================

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts


# =========================================================
# Application source
# =========================================================

COPY . .


# =========================================================
# PHP configuration
# =========================================================

COPY docker/php/custom.ini \
    /usr/local/etc/php/conf.d/custom.ini


# =========================================================
# Laravel runtime directories
# =========================================================

RUN mkdir -p \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
        /var/www/.config/psysh


# =========================================================
# Runtime permissions
# =========================================================
# Laravel runtime directories + application ownership.
# This prevents www-data permission problems.
# =========================================================

RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 \
        storage \
        bootstrap/cache \
        /var/www/.config


# =========================================================
# Laravel package discovery
# =========================================================

RUN php artisan package:discover --ansi || true


# =========================================================
# PHP-FPM
# =========================================================

EXPOSE 9000

USER www-data

CMD ["php-fpm"]