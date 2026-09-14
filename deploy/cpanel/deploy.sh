#!/bin/bash
# Update the live site to the latest master.
#   bash /home/grapme/netvork/deploy/cpanel/deploy.sh
#   FORCE_BUILD=1 bash .../deploy.sh   # rebuild the frontend even if unchanged
#
# Pulls, installs any new dependencies, migrates, rebuilds the frontend,
# republishes the docroot and restarts the workers. Safe to re-run.
#
# This box also serves grapout.com (and grapout.com/trade) from the same
# cPanel account. Everything the deploy runs as grapme shares that account's
# memory and process limits with those sites, so the heavy steps - npm ci and
# the Vite build - run only when the frontend actually changed, at the lowest
# CPU and disk priority, with a capped Node heap. At the end both sites are
# checked, and a site that stopped answering gets Apache and PHP-FPM restarted
# rather than needing the whole server rebooted.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
COMPOSER=${COMPOSER:-/usr/local/bin/composer}
# The box is behind NAT and cannot reach its own public IP, so site checks
# resolve the domains to the private address.
HOST_IP=${HOST_IP:-10.131.0.5}
CHECK_SITES=${CHECK_SITES:-"netvork.app grapout.com"}
NODE_HEAP_MB=${NODE_HEAP_MB:-1536}
LOW="nice -n 19 ionice -c3"

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

site_code() {
  local code
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 \
    --resolve "$1:443:$HOST_IP" "https://$1/" 2>/dev/null) || true
  echo "${code:-000}"
}
site_up() {
  case "$(site_code "$1")" in 2??|3??) return 0 ;; *) return 1 ;; esac
}

echo "== sites before =="
for site in $CHECK_SITES; do echo "   $site: $(site_code "$site")"; done
free -m | awk 'NR==2 {print "   memory available: " $7 " MB of " $2 " MB"}'

echo
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

changed() {
  [ "$BEFORE" != "$AFTER" ] && ! sudo -u $APP_USER git -C "$APP_DIR" diff --quiet "$BEFORE" "$AFTER" -- "$@"
}

echo
echo "== backend =="
cd "$APP_DIR/backend"
sudo -u $APP_USER $LOW $PHP $COMPOSER install --no-dev -o -n
sudo -u $APP_USER $PHP artisan migrate --force

echo
echo "== frontend =="
cd "$APP_DIR/frontend"
if [ -n "$FORCE_BUILD" ] || [ ! -d dist ] || changed frontend; then
  if [ -n "$FORCE_BUILD" ] || [ ! -d node_modules ] || changed frontend/package-lock.json frontend/package.json; then
    echo "   installing packages (low priority)"
    sudo -u $APP_USER $LOW npm ci --no-audit --no-fund
  else
    echo "   packages unchanged — skipping npm ci"
  fi
  echo "   building (low priority, Node heap capped at ${NODE_HEAP_MB} MB)"
  sudo -u $APP_USER env NODE_OPTIONS="--max-old-space-size=$NODE_HEAP_MB" $LOW npm run build
else
  echo "   frontend unchanged — skipping install and build (FORCE_BUILD=1 to rebuild)"
fi

echo
# Not a separate command to remember: publish.sh rsyncs frontend/dist into the
# docroot, rewrites the managed .htaccess block, and caches Laravel's config.
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
echo "== sites after =="
sleep 3
DOWN=""
for site in $CHECK_SITES; do
  code=$(site_code "$site")
  echo "   $site: $code"
  case "$code" in 2??|3??) ;; *) DOWN="$DOWN $site" ;; esac
done

if [ -n "$DOWN" ]; then
  echo "!! not answering:$DOWN"
  HEALTH_LOG="/home/$APP_USER/logs/server-health-$(date +%Y%m%d-%H%M%S).log"
  echo "   capturing the server's state first: $HEALTH_LOG"
  bash "$APP_DIR/deploy/cpanel/server-health.sh" > "$HEALTH_LOG" 2>&1 || true
  echo "   restarting PHP-FPM and Apache (no reboot)"
  [ -x /scripts/restartsrv_apache_php_fpm ] && /scripts/restartsrv_apache_php_fpm >/dev/null 2>&1 || true
  [ -x /scripts/restartsrv_httpd ] && /scripts/restartsrv_httpd >/dev/null 2>&1 || true
  sleep 5
  for site in $DOWN; do
    if site_up "$site"; then
      echo "   $site is back: $(site_code "$site")"
    else
      echo "!! $site still down ($(site_code "$site")) — see the server-health log above, and send it over"
    fi
  done
fi

echo
echo "== done: now running $AFTER =="
# No hard-refresh instruction any more. Open tabs pick the new build up on
# their own: a page that fails to load because its chunk was renamed clears
# the cached shell and reloads itself.
echo "Open tabs will pick up the new build by themselves."
