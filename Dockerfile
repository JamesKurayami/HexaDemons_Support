# Use official PHP image with Apache
FROM php:8.1-apache

# Install required extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install zip

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application files
COPY . /var/www/html/

# Set permissions for data files
RUN mkdir -p /var/www/html/data && \
    touch /var/www/html/data/users.json && \
    touch /var/www/html/data/error.log && \
    chown -R www-data:www-data /var/www/html/data

# Set working directory
WORKDIR /var/www/html

# Expose port 80
EXPOSE 80
