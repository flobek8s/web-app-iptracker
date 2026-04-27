FROM php:8.5.5-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install mysqli pdo pdo_pgsql pgsql zip gd \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY src/ .  

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]