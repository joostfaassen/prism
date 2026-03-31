FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    nodejs \
    npm \
    ripgrep \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mbstring exif pcntl bcmath gd zip intl opcache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions imap

RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /app/public\n\
    <Directory /app/public>\n\
        AllowOverride All\n\
        Require all granted\n\
        FallbackResource /index.php\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

EXPOSE 80

ENV APP_ENV=prod

WORKDIR /app
RUN mkdir -p /var/www/.composer /var/www/.npm && chown -R www-data:www-data /var/www
ENV COMPOSER_HOME=/var/www/.composer
ENV npm_config_cache=/var/www/.npm

COPY --chown=www-data:www-data . /app

RUN chown www-data:www-data /app

USER www-data
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-scripts --optimize-autoloader
RUN npm install && node_modules/.bin/encore production

USER root
RUN mkdir -p -m 0775 var/cache var/log var/sessions \
    && chown -R www-data:www-data var

VOLUME ["/app/var/cache", "/app/var/log"]

USER www-data

CMD ["apache2-foreground"]
