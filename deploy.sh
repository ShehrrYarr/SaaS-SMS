#!/usr/bin/env bash
#
# Updates this server to the latest master. Run from anywhere, as root:
#     bash /var/www/SaaS-SMS/deploy.sh
#
# Only this app is touched: its folder, its database and its caches.
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

echo "==> Pulling master"
git pull --ff-only origin master

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Front-end build"
npm ci --no-audit --no-fund
npm run build

echo "==> Database"
php artisan migrate --force
php artisan access:sync

echo "==> Caches"
php artisan optimize:clear
php artisan optimize
php artisan queue:restart

# The web server and the scheduler run as www-data.
chown -R www-data:www-data storage bootstrap/cache

echo "==> Done: $(git log -1 --format='%h %s')"
