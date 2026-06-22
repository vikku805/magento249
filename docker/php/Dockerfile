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

# --- New Relic PHP agent (APM) ---
# Resolve the latest linux (glibc) agent tarball at build time so we never pin a
# stale/invalid version. newrelic-install copies newrelic.so into the active PHP
# extension dir and drops a newrelic.ini into conf.d. License key + app name are
# supplied at runtime via env vars (see docker-compose.yml), not baked here.
RUN set -eux; \
    NR_TARBALL="$(curl -fsSL https://download.newrelic.com/php_agent/release/ \
        | grep -oE 'newrelic-php5-[0-9.]+-linux\.tar\.gz' | sort -V | tail -1)"; \
    curl -fsSL "https://download.newrelic.com/php_agent/release/${NR_TARBALL}" -o /tmp/nr.tar.gz; \
    tar -C /tmp -xzf /tmp/nr.tar.gz; \
    NR_INSTALL_SILENT=1 NR_INSTALL_USE_CP_NOT_LN=1 \
        /tmp/newrelic-php5-*/newrelic-install install; \
    rm -rf /tmp/nr.tar.gz /tmp/newrelic-php5-*

RUN a2enmod rewrite headers
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-magento.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Inject New Relic license/appname from env into newrelic.ini at container start.
COPY docker/php/newrelic-entrypoint.sh /usr/local/bin/newrelic-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/newrelic-entrypoint.sh \
    && chmod +x /usr/local/bin/newrelic-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/newrelic-entrypoint.sh"]
CMD ["apache2-foreground"]

WORKDIR /var/www/html

