#!/bin/bash
# Netvork production update script — run as root on the cPanel server.
# Pulls latest master, updates the API, rebuilds the frontend, restarts services.
set -e

APP_USER=netvork
REPO=/home/$APP_USER/mypa
DOCROOT=/home/$APP_USER/public_html
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

echo "== Pulling latest code =="
sudo -u $APP_USER git -C $REPO pull --ff-only

echo "== Backend: dependencies + migrations =="
cd $REPO/backend
sudo -u $APP_USER $PHP /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader --no-interaction
sudo -u $APP_USER $PHP artisan migrate --force
sudo -u $APP_USER $PHP artisan config:cache
sudo -u $APP_USER $PHP artisan route:cache
sudo -u $APP_USER $PHP artisan view:cache

echo "== Frontend: build =="
cd $REPO/frontend
sudo -u $APP_USER npm ci
sudo -u $APP_USER npm run build

echo "== Publishing frontend to docroot =="
sudo -u $APP_USER rsync -a --delete \
  --exclude=.htaccess --exclude=apibase \
  $REPO/frontend/dist/ $DOCROOT/

echo "== Restarting services =="
systemctl restart netvork-queue netvork-reverb

echo "== Done. =="
