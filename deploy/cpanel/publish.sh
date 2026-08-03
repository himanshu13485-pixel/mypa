#!/bin/bash
# Publish the built frontend into the docroot and wire Laravel behind it.
# Run as root:  bash deploy/cpanel/publish.sh
# Idempotent - safe to re-run after every build.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOCROOT=${DOCROOT:-/home/$APP_USER/public_html/netvork.app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}

[ -d "$APP_DIR/frontend/dist" ] || { echo "!! no frontend/dist - run npm run build first"; exit 1; }

echo "== publishing SPA to $DOCROOT =="
rsync -a --delete \
  --exclude=.htaccess --exclude=apibase --exclude=cgi-bin \
  --exclude=.well-known --exclude=php.ini --exclude=.user.ini \
  "$APP_DIR/frontend/dist/" "$DOCROOT/"

echo "== linking Laravel public dir as apibase =="
ln -sfn "$APP_DIR/backend/public" "$DOCROOT/apibase"

echo "== storage symlink + permissions =="
sudo -u $APP_USER $PHP "$APP_DIR/backend/artisan" storage:link || true
chown -R $APP_USER:$APP_USER "$DOCROOT"
chown -h $APP_USER:$APP_USER "$DOCROOT/apibase"
chmod -R 775 "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"

echo "== rewrite rules =="
if grep -q 'Netvork routing' "$DOCROOT/.htaccess" 2>/dev/null; then
  echo "   already present, left untouched"
else
  cat >> "$DOCROOT/.htaccess" <<'HTEOF'

# === Netvork routing (managed by deploy/cpanel/publish.sh) ===
RewriteEngine On

# force https
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# API + broadcasting auth -> Laravel
RewriteRule ^(api|broadcasting)(/.*)?$ apibase/index.php [L]

# everything else -> the SPA (real files served as-is)
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
# === end Netvork routing ===
HTEOF
  echo "   appended (cPanel's own directives left intact)"
fi

echo "== caching config =="
cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP artisan config:cache
sudo -u $APP_USER $PHP artisan route:cache
sudo -u $APP_USER $PHP artisan view:cache

echo "== done =="
