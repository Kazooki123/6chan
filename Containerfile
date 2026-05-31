FROM postgres:17-alpine

RUN apk add --no-cache postgresql17-contrib

FROM php:8.5-apache

RUN apt-get update && apt-get install -y libpq-dev libmagickwand-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite
RUN chown -R www-data:www-data /var/www/html
