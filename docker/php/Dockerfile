FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libicu-dev libxslt1-dev libzip-dev libsodium-dev \
    libxml2-dev libonig-dev libcurl4-openssl-dev \
    zlib1g-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl pdo_mysql soap sockets xsl zip \
        opcache pcntl gettext

RUN pecl install igbinary && docker-php-ext-enable igbinary

RUN a2enmod rewrite headers
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-magento.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

