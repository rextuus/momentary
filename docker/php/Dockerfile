# syntax=docker/dockerfile:1
# Momentary – ein Image für Webserver und Worker (Apache + PHP, ffmpeg, Python für Szenen, Fotos und Whisper).
# Ziele: `dev` (Code wird eingebunden, compose.yaml) und `prod` (Code steckt im Image, compose.prod.yaml).
FROM php:8.5.13-apache AS base

# PHP-Erweiterungen bauen, danach nur die Laufzeit-Bibliotheken behalten (Muster der offiziellen PHP-Images)
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev librabbitmq-dev; \
    pecl install amqp; \
    docker-php-ext-enable amqp; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | awk 'sub(":$", "", $1) { print $1 }' | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /tmp/pear /var/lib/apt/lists/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg python3 python3-pip unzip \
    && rm -rf /var/lib/apt/lists/*

# Szenenerkennung (PySceneDetect), Fotos (EXIF, HEIC vom iPhone), lokale Transkription (faster-whisper auf der CPU)
COPY docker/php/requirements.txt /tmp/requirements.txt
RUN python3 -m pip install --no-cache-dir --break-system-packages -r /tmp/requirements.txt && rm /tmp/requirements.txt

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

# Apache: Symfony unter public/, große Uploads, /imgproxy/ an den imgproxy-Container (ein Port für alles)
RUN a2enmod rewrite headers proxy proxy_http
COPY docker/php/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /var/www/html

FROM base AS dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM base AS prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini
ENV APP_ENV=prod APP_DEBUG=0

# Abhängigkeiten zuerst: ändern sich seltener als der Code und bleiben so im Build-Cache
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
# .env enthält keine Secrets (Vorlage .env.example); die echten Werte setzt compose.prod.yaml als Umgebungsvariablen.
RUN sed -e 's/^APP_ENV=.*/APP_ENV=prod/' -e 's/^APP_DEBUG=.*/APP_DEBUG=0/' .env.example > .env \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && php bin/console importmap:install \
    && php bin/console sass:build \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    && mkdir -p var/log var/transcription var/whisper-models storage public/uploads \
    && chown -R www-data:www-data var storage public/uploads

COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/momentary-entrypoint
ENTRYPOINT ["momentary-entrypoint"]
CMD ["apache2-foreground"]
