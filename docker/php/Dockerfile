FROM php:8.5-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    python3 \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# Workaround for PHP 8.5 experimental images: install extensions one by one
# and handle opcache specifically.
RUN docker-php-ext-configure intl && docker-php-ext-install intl
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-configure zip && docker-php-ext-install zip
# Opcache might be tricky in 8.5, trying to install it separately. 
# If it's already there, this might fail, but let's try a different approach if it does.
RUN docker-php-ext-install opcache || echo "Opcache install failed, skipping..."

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Create necessary directories and set permissions
RUN mkdir -p var public/uploads/faces \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R 777 var public/uploads

# Adjust Apache document root for Symfony
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow overrides in Apache
RUN sed -i '/<Directory ${APACHE_DOCUMENT_ROOT}>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf || \
    echo "<Directory \${APACHE_DOCUMENT_ROOT}>\n\tAllowOverride All\n</Directory>" >> /etc/apache2/apache2.conf

# Increase PHP upload limits for video files
RUN echo "upload_max_filesize = 10G" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 10G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "output_buffering = 4096" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80
