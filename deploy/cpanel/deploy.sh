#!/bin/bash
# Netvork production update — run as root on the cPanel server.
# Pulls master, updates the API, rebuilds the frontend, restarts services.
#
#   bash /home/grapme/netvork/deploy/cpanel/deploy.sh
#
# Override any of these from the environment if the layout ever moves:
#   APP_USER=grapme APP_DIR=/home/grapme/netvork bash deploy.sh
set -euo pipefail

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOCROOT=${DOCROOT:-/home/$APP_USER/public_html/netvork.app}
# composer.lock requires PHP >= 8.4.1 — ea-php83 fails the platform check.
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
COMPOSER=${COMPOSER:-/opt/cpanel/composer/bin/composer}

# Fail loudly and immediately rather than part-way through. A deploy that dies
# after `git pull` but before `artisan migrate` leaves the database behind the
# code, which surfaces later as unrelated-looking 500s.
for path in "$APP_DIR/backend/artisan" "$DOCROOT" "$PHP"; do
  [ -e "$path" ] || { echo "!! not found: $path — check APP_USER/APP_DIR/DOCROOT"; exit 1; }
done
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' \
  || { echo "!! $PHP is $("$PHP" -r 'echo PHP_VERSION;') — composer.lock needs >= 8.4.1"; exit 1; }

echo "== Pulling latest code =="
sudo -u $APP_USER git -C $APP_DIR pull --ff-only

echo "== Backend: dependencies + migrations =="
cd $APP_DIR/backend
sudo -u $APP_USER $PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction
sudo -u $APP_USER $PHP artisan migrate --force
# Rebuild the caches: new routes and middleware aliases are invisible until
# route:cache is refreshed, which looks like a 404 on endpoints that exist.
sudo -u $APP_USER $PHP artisan config:cache
sudo -u $APP_USER $PHP artisan route:cache
sudo -u $APP_USER $PHP artisan view:cache

echo "== Frontend: build =="
cd $APP_DIR/frontend
sudo -u $APP_USER npm ci
sudo -u $APP_USER npm run build

echo "== Publishing frontend to docroot =="
# --delete removes anything in the docroot that is not in the build, so keep
# the exclude list current for whatever else lives there.
sudo -u $APP_USER rsync -a --delete \
  --exclude=.htaccess --exclude=apibase --exclude=landing \
  $APP_DIR/frontend/dist/ $DOCROOT/

echo "== Restarting services =="
systemctl restart netvork-queue netvork-reverb
sleep 2
systemctl --no-pager --lines=0 status netvork-queue  | head -3
systemctl --no-pager --lines=0 status netvork-reverb | head -3
ss -ltnp | grep -q ':8443' \
  && echo "   reverb listening on 8443" \
  || echo "!! reverb NOT listening on 8443 — check /home/$APP_USER/logs/netvork-reverb.log"

echo "== Done. =="
