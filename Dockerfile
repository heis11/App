
FROM php:8.2-apache

# Install system dependencies for PHP extensions.
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libonig-dev \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        mbstring \
        curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module.
RUN a2enmod rewrite

# Copy HEISVERSE project into Apache.
COPY . /var/www/html/

# Set Apache permissions.
RUN chown -R www-data:www-data /var/www/html

# Configure Apache for Render's port.
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

# Remove Apache server-name warning.
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 10000

CMD ["apache2-foreground"]
