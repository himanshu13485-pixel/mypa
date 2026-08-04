#!/bin/bash
# Update the live site to the latest master.
#   bash /home/grapme/netvork/deploy/cpanel/deploy.sh
#
# Pulls, installs any new dependencies, migrates, rebuilds the frontend,
# republishes the docroot and restarts the workers. Safe to re-run.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
COMPOSER=${COMPOSER:-/usr/local/bin/composer}

echo "== pulling =="
BEFORE=$(sudo -u $APP_USER git -C "$APP_DIR" rev-parse --short HEAD)
sudo -u $APP_USER git -C "$APP_DIR" pull --ff-only
AFTER=$(sudo -u $APP_USER git -C "$APP_DIR" rev-parse --short HEAD)
if [ "$BEFORE" = "$AFTER" ]; then
  echo "   already up to date ($AFTER)"
else
  echo "   $BEFORE -> $AFTER"
  sudo -u $APP_USER git -C "$APP_DIR" log --oneline "$BEFORE..$AFTER" | head -20
fi

echo
echo "== backend =="
cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP $COMPOSER install --no-dev -o -n
sudo -u $APP_USER $PHP artisan migrate --force

echo
echo "== frontend =="
cd "$APP_DIR/frontend"
sudo -u $APP_USER npm ci
sudo -u $APP_USER npm run build

echo
echo "== publish (docroot, htaccess, caches) =="
bash "$APP_DIR/deploy/cpanel/publish.sh"

echo
echo "== restarting workers =="
systemctl restart netvork-queue netvork-reverb
sleep 2
systemctl is-active netvork-queue netvork-reverb

echo
echo "== done: now running $AFTER =="
echo "Hard-refresh the browser (Ctrl+Shift+R) to pick up the new bundle."
