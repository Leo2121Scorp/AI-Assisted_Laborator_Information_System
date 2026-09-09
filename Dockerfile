FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && a2enmod rewrite \
    && mkdir -p /var/www/html/backups \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/backups

ENV PORT=80
EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
