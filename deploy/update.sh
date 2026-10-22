#!/usr/bin/env bash
#
# Deploys the latest main. Run on the server as the app user, from the app directory:
#   bash deploy/update.sh
#
# --no-restart skips reloading PHP-FPM and restarting Reverb and the bots (setup.sh does that itself).

set -euo pipefail

cd "$(dirname "$0")/.."

git pull --ff-only
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts
npm run build
php artisan migrate --force
php artisan optimize

if [[ ${1:-} != --no-restart ]]; then
    sudo systemctl reload php8.4-fpm
    sudo supervisorctl restart stack-duel-reverb stack-duel-bots
fi
