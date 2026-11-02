
FROM php:8.3-fpm

# System dependencies and PHP build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
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
    $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
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
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Install dependencies first for Docker layer caching
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

# Copy Laravel source
COPY . .

# PHP configuration
COPY docker/php/custom.ini \
    /usr/local/etc/php/conf.d/custom.ini

# Laravel runtime directories and permissions
RUN mkdir -p \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/public \
        bootstrap/cache \
        /var/www/.config/psysh \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
        /var/www/.config \
    && chmod -R ug+rwX \
        storage \
        bootstrap/cache \
        /var/www/.config

# Laravel package discovery
RUN php artisan package:discover --ansi

# Create public storage symlink
RUN php artisan storage:link --force

EXPOSE 9000

CMD ["php-fpm"]
