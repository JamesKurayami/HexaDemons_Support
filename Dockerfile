FROM php:8.1-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo_mysql zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . .

RUN chmod 777 users.json
RUN chmod 777 error.log

RUN composer install

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80