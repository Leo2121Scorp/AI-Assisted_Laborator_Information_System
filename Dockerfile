FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libcurl4-openssl-dev \
        python3 \
        python3-pip \
        python3-venv \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql curl \
    && a2enmod rewrite \
    && mkdir -p /var/www/html/backups \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN pip3 install --no-cache-dir --break-system-packages -r /var/www/html/ai/requirements.txt \
    && python3 /var/www/html/ai/train_model.py

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/backups

ENV PORT=80
ENV AI_PORT=5001
EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
