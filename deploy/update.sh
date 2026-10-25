#!/usr/bin/env bash
#
# Deploys the latest main as a new release, without touching the one being served until it is ready. Run on the
# server as the app user:
#   bash /var/www/stack-duel/deploy/update.sh              deploy
#   bash /var/www/stack-duel/deploy/update.sh --rollback   go back to the previous release (migrations stay applied)
#
# --no-restart leaves PHP-FPM, Reverb and the bots alone (setup.sh restarts them itself). DEPLOY_LIVE and DEPLOY_BASE
# move the paths below, to rehearse a deploy on a copy.
#
# Layout: each release is a fresh clone in $BASE/releases, and $LIVE (what Nginx, Supervisor and cron point at) is a
# symlink to the current one. What outlives a release (.env, storage/, the SQLite database and its backups) is in
# $BASE/shared. The first run moves a plain checkout at $LIVE into this layout.

set -euo pipefail

LIVE=${DEPLOY_LIVE:-/var/www/stack-duel}
BASE=${DEPLOY_BASE:-/var/www/stack-duel-deploy}
SHARED=$BASE/shared
DATABASE=$SHARED/database/database.sqlite
KEEP_RELEASES=3
KEEP_BACKUPS=10

restart=true
rollback=false
for arg in "$@"; do
    case $arg in
        --no-restart) restart=false ;;
        --rollback) rollback=true ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

restart_services() {
    if $restart; then
        sudo systemctl reload php8.4-fpm
        sudo supervisorctl restart stack-duel-reverb stack-duel-bots
    fi
}

# Points $LIVE at a release in one rename, so no request ever sees a half-switched app. /var/www belongs to root.
switch_to() {
    sudo ln -sfn "$1" "$LIVE.next"
    sudo mv -T "$LIVE.next" "$LIVE"
}

if $rollback; then
    current=$(readlink -f "$LIVE")
    previous=$(find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d | sort | grep -B1 -xF "$current" | head -n1)
    if [[ -z $previous || $previous == "$current" ]]; then
        echo "No release before $current to roll back to." >&2
        exit 1
    fi
    echo "==> Rolling back to $previous"
    switch_to "$previous"
    restart_services
    exit 0
fi

legacy=false
if [[ -d $LIVE && ! -L $LIVE ]]; then
    legacy=true
    repo=$(git -C "$LIVE" remote get-url origin)
    echo "==> First run: moving the checkout at $LIVE into $BASE"
    sudo install -d -o "$(id -un)" -g "$(id -gn)" "$BASE"
    mkdir -p "$BASE/releases" "$SHARED/database" "$SHARED/backups"
    # A copy for building; the original moves over once the new release is ready.
    if [[ ! -f $SHARED/.env ]]; then
        install -m 600 "$LIVE/.env" "$SHARED/.env"
        echo "DB_DATABASE=$DATABASE" >> "$SHARED/.env"
    fi
else
    repo=$(git -C "$(readlink -f "$LIVE")" remote get-url origin)
fi

release=$BASE/releases/$(date -u +%Y%m%d%H%M%S)
echo "==> Building $release"
git clone --quiet --depth 1 --branch main "$repo" "$release"
cd "$release"
ln -s "$SHARED/.env" .env
rm -rf storage
ln -s "$SHARED/storage" storage
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts
npm run build

if $legacy; then
    echo "==> Moving the database and storage (the site is briefly in maintenance)"
    php "$LIVE/artisan" down --retry=5
    if $restart; then
        sudo supervisorctl stop stack-duel-reverb stack-duel-bots
    fi
    sqlite3 "$LIVE/database/database.sqlite" 'PRAGMA wal_checkpoint(TRUNCATE);'
    mv "$LIVE/database/database.sqlite" "$DATABASE"
    rm -f "$LIVE/database/database.sqlite-wal" "$LIVE/database/database.sqlite-shm"
    mv "$LIVE/storage" "$SHARED/storage"
    # The maintenance flag moved with storage, so the new release serves the maintenance page until `up` below.
    sudo mv "$LIVE" "$BASE/original-checkout"
    switch_to "$release"
fi

echo "==> Backing up the database"
sqlite3 "$DATABASE" ".backup '$SHARED/backups/database-$(basename "$release").sqlite'"
find "$SHARED/backups" -name 'database-*.sqlite' | sort | head -n -$KEEP_BACKUPS | xargs -r rm --

php artisan migrate --force
php artisan optimize

echo "==> Switching to $release"
switch_to "$release"
if $legacy; then
    php artisan up
    rm -rf "$BASE/original-checkout"
fi
restart_services

find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d | sort | head -n -$KEEP_RELEASES | xargs -r rm -rf --
echo "Deployed $(git log -1 --format='%h %s')"
