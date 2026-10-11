#!/bin/sh
set -e

# Render tells us which port to listen on
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan package:discover --ansi
php artisan config:cache
php artisan storage:link || true
php artisan migrate --force

# Creates the demo accounts (safe to repeat; it never changes existing ones)
if [ "${RUN_SEEDERS}" = "true" ]; then
    php artisan db:seed --class=StaffSeeder --force
fi

chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground