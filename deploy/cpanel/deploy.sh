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

# Check the ground before moving. A deploy that dies part-way — after `git
# pull` but before `artisan migrate` — leaves the database behind the code,
# and that surfaces later as unrelated-looking 500s rather than as a failed
# deploy. composer.lock needs PHP >= 8.4.1, so ea-php83 fails the platform
# check here rather than confusingly inside composer.
for path in "$APP_DIR/backend/artisan" "$PHP" "$COMPOSER"; do
  [ -e "$path" ] || { echo "!! not found: $path — check APP_USER / APP_DIR / PHP / COMPOSER"; exit 1; }
done
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' \
  || { echo "!! $PHP is $("$PHP" -r 'echo PHP_VERSION;') — composer.lock needs >= 8.4.1"; exit 1; }

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
# Reverb serves wss:// on 8443 straight from backend/.env. If it is not
# listening, browsers get no realtime and PHP's own publish is refused.
ss -ltnp | grep -q ':8443' \
  && echo "   reverb listening on 8443" \
  || echo "!! reverb NOT listening on 8443 — check /home/$APP_USER/logs/netvork-reverb.log"

echo
echo "== done: now running $AFTER =="
echo "Hard-refresh the browser (Ctrl+Shift+R) to pick up the new bundle."
