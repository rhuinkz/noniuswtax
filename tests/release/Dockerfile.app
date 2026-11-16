# Isolated disposable PHP Apache container. NEVER use as a production release image.
FROM php:8.4-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install -j2 pdo_mysql mbstring zip gd \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html
EXPOSE 80
