FROM php:8.1-apache

WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo_mysql zip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create and set permissions for required files
RUN touch users.json error.log && \
    chmod 666 users.json && \
    chmod 666 error.log

# Copy only necessary files for composer first
COPY composer.json composer.lock ./

# Install dependencies (with no-dev for production)
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Copy the rest of the application
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
