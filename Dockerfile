
FROM php:8.2-apache

# Install PHP extensions required for MySQL and API requests.
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite rules.
RUN a2enmod rewrite

# Copy HEISVERSE into the Apache web directory.
COPY . /var/www/html/

# Configure Apache permissions.
RUN chown -R www-data:www-data /var/www/html

# Render web services use port 10000 by default.
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
