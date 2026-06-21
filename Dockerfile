FROM php:8.2-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libicu-dev libonig-dev libxml2-dev curl unzip git \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql mysqli zip gd mbstring intl opcache xml bcmath \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/wealthdash.ini

# Apache VirtualHost
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY . .

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader --no-interaction; fi

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/logs/cache \
    && chmod -R 777 /var/www/html/logs \
    && mkdir -p /var/www/html/storage/backups \
    && chmod -R 755 /var/www/html/storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s \
    CMD curl -f http://localhost/api/router.php?action=health_ping || exit 1
