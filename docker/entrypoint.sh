#!/bin/sh
set -e
cd /var/www/html
mkdir -p /state /data/rec /data/exports storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
if [ "$1" = "supervisord" ] && echo "$3" | grep -q app.conf; then
  php artisan migrate --force --no-interaction
  php artisan homecam:bootstrap
  php artisan config:cache && php artisan route:cache && php artisan view:cache
fi
exec "$@"
