#!/usr/bin/env bash
#
# One-time provisioning for a fresh Ubuntu 24.04 server, such as an Oracle Cloud Always Free Arm VM.
# Installs Nginx, PHP 8.4, Node 22, Supervisor and Certbot, then deploys the app with SQLite.
#
# Usage, on the server:
#   sudo apt-get update && sudo apt-get install -y git
#   git clone https://github.com/GodfreysDragon/stack-duel.git /tmp/stack-duel
#   sudo bash /tmp/stack-duel/deploy/setup.sh <domain> <email for Let's Encrypt>
#
# Running it again is safe: it keeps an existing checkout and .env.

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: sudo bash deploy/setup.sh <domain> <email>" >&2
    exit 1
fi

DOMAIN=$1
EMAIL=$2
APP_DIR=/var/www/stack-duel
APP_USER=${SUDO_USER:-ubuntu}
REPO=https://github.com/GodfreysDragon/stack-duel.git
DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"

as_app() {
    sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $1"
}

echo "==> Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common curl git unzip sqlite3 nginx supervisor certbot python3-certbot-nginx iptables-persistent
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath

if ! command -v node >/dev/null || [[ $(node -v) != v22.* ]]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi

if ! command -v composer >/dev/null; then
    curl -fsSLo /tmp/composer.phar https://getcomposer.org/download/latest-stable/composer.phar
    curl -fsSLo /tmp/composer.phar.sha256sum https://getcomposer.org/download/latest-stable/composer.phar.sha256sum
    (cd /tmp && sha256sum -c composer.phar.sha256sum)
    install -m 755 /tmp/composer.phar /usr/local/bin/composer
fi

echo "==> Firewall"
# Oracle's Ubuntu images reject everything except SSH in iptables, on top of the VCN security list.
for port in 80 443; do
    if ! iptables -C INPUT -p tcp --dport "$port" -m conntrack --ctstate NEW -j ACCEPT 2>/dev/null; then
        iptables -I INPUT 1 -p tcp --dport "$port" -m conntrack --ctstate NEW -j ACCEPT
    fi
done
netfilter-persistent save

echo "==> PHP-FPM runs as $APP_USER, so the web server, Reverb and the bots share file ownership"
sed -i -e "s/^user = .*/user = $APP_USER/" -e "s/^group = .*/group = $APP_USER/" /etc/php/8.4/fpm/pool.d/www.conf
systemctl restart php8.4-fpm

echo "==> App"
if [[ ! -d "$APP_DIR/.git" ]]; then
    install -d -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
    sudo -u "$APP_USER" -H git clone "$REPO" "$APP_DIR"
fi

if [[ ! -f "$APP_DIR/.env" ]]; then
    REVERB_KEY=$(openssl rand -hex 10)
    REVERB_SECRET=$(openssl rand -hex 32)
    as_app "cp .env.example .env"
    set_env() {
        if grep -q "^$1=" "$APP_DIR/.env"; then
            sed -i "s|^$1=.*|$1=$2|" "$APP_DIR/.env"
        else
            echo "$1=$2" >> "$APP_DIR/.env"
        fi
    }
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env LOG_STACK daily
    set_env LOG_LEVEL warning
    set_env SESSION_SECURE_COOKIE true
    set_env REVERB_APP_KEY "$REVERB_KEY"
    set_env REVERB_APP_SECRET "$REVERB_SECRET"
    # Laravel publishes to Reverb on localhost; browsers reach it through Nginx over wss://.
    set_env REVERB_HOST 127.0.0.1
    set_env REVERB_PORT 8085
    set_env REVERB_SCHEME http
    set_env REVERB_SERVER_HOST 127.0.0.1
    set_env REVERB_SERVER_PORT 8085
    set_env VITE_REVERB_APP_KEY "$REVERB_KEY"
    set_env VITE_REVERB_HOST "$DOMAIN"
    set_env VITE_REVERB_PORT 443
    set_env VITE_REVERB_SCHEME https
    chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
    chmod 600 "$APP_DIR/.env"
    as_app "touch database/database.sqlite"
    as_app "composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader"
    as_app "php artisan key:generate --force"
fi

# The house bots are created on first use. Don't run db:seed here: it adds demo accounts with a known password.
as_app "bash deploy/update.sh --no-restart"

echo "==> Nginx"
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" "$DEPLOY_DIR/nginx.conf" > /etc/nginx/sites-available/stack-duel
ln -sf /etc/nginx/sites-available/stack-duel /etc/nginx/sites-enabled/stack-duel
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> Supervisor (Reverb and the bot daemon)"
sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__APP_USER__|$APP_USER|g" "$DEPLOY_DIR/supervisor.conf" > /etc/supervisor/conf.d/stack-duel.conf
supervisorctl reread
supervisorctl update
supervisorctl restart stack-duel-reverb stack-duel-bots

echo "==> Scheduler"
echo "* * * * * $APP_USER cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/stack-duel

echo "==> HTTPS"
if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect -n; then
    echo "Done: https://$DOMAIN"
else
    echo "Certbot failed. Check that $DOMAIN points at this server and that ports 80 and 443 are open in the" >&2
    echo "VCN security list, then run: sudo certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect" >&2
    exit 1
fi
