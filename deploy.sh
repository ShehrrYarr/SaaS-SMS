#!/usr/bin/env bash
#
# Updates this server to the latest master. Run from anywhere, as root:
#     bash /var/www/SaaS-SMS/deploy.sh
#
# Only this app is touched: its folder, its database and its caches.
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

# Pull first, then restart with the freshly pulled copy of this script.
if [ "${1:-}" != "--pulled" ]; then
    echo "==> Pulling master"
    git pull --ff-only origin master
    exec bash "$(readlink -f "$0")" --pulled
fi

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
# No route:cache (so not `optimize`): the compiled matcher lets the {school}
# route, whose pattern uses a lookahead, capture "/" and hide the landing page.
php artisan config:cache
php artisan event:cache
php artisan view:cache
php artisan queue:restart

# The web server and the scheduler run as www-data.
chown -R www-data:www-data storage bootstrap/cache

echo "==> Done: $(git log -1 --format='%h %s')"
