FROM php:8.3-fpm

# pdo_mysql: BD · zip: exportación DwC-A · intl: normalización de nombres científicos
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/backend
