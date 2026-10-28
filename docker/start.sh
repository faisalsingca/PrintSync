#!/bin/sh
set -e

# Render tells us which port to listen on
PORT="${PORT:-10000}"
sed -i "s|^Listen 80$|Listen 0.0.0.0:${PORT}|" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan package:discover --ansi
php artisan config:cache
php artisan storage:link || true
php artisan migrate --force
php artisan db:seed --class=SuperAdminSeeder --force

# Creates the demo accounts (safe to repeat; it never changes existing ones)
if [ "${RUN_SEEDERS}" = "true" ]; then
    php artisan db:seed --class=StaffSeeder --force
    php artisan db:seed --class=InventorySeeder --force
fi

echo "Setting writable directory ownership..."
chown -R www-data:www-data storage bootstrap/cache

echo "Checking Apache configuration for port ${PORT}..."
apache2ctl configtest
echo "Starting Apache on port ${PORT}..."
exec apache2-foreground