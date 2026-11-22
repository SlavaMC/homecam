FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.4-fpm-alpine
RUN apk add --no-cache ffmpeg nginx supervisor tzdata sqlite \
 && docker-php-ext-install pcntl \
 && rm -rf /var/cache/apk/*
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/ /etc/supervisor/
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-homecam.ini
COPY docker/entrypoint.sh /usr/local/bin/homecam-entrypoint
RUN chmod +x /usr/local/bin/homecam-entrypoint \
 && sed -i 's/^user = www-data/user = root/; s/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
ENTRYPOINT ["homecam-entrypoint"]
