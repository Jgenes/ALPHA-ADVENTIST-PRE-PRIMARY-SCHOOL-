FROM php:8.3-apache
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libsodium-dev libcurl4-openssl-dev libsqlite3-dev; \
    docker-php-ext-configure gd --with-jpeg --with-freetype; \
    extensions=""; \
    for ext in mbstring gd curl pdo_mysql pdo_sqlite sodium exif; do \
      php -r "exit(extension_loaded('$ext') ? 0 : 1);" || extensions="$extensions $ext"; \
    done; \
    if [ -n "$extensions" ]; then docker-php-ext-install -j$(nproc) $extensions; fi; \
    a2enmod rewrite headers; \
    rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public DATA_DIR=/var/data
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/alpha-school.conf \
    && a2enconf alpha-school \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\npost_max_size=8M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/alpha-school.ini \
    && mkdir -p /var/data && chown www-data:www-data /var/data
WORKDIR /var/www/html
COPY . .
RUN chmod +x scripts/container-start.sh
EXPOSE 80
CMD ["sh", "scripts/container-start.sh"]
