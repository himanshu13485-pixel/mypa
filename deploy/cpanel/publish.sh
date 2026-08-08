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
[ -L "$APP_DIR/backend/public/storage" ] || sudo -u $APP_USER $PHP "$APP_DIR/backend/artisan" storage:link
chown -R $APP_USER:$APP_USER "$DOCROOT"
chown -h $APP_USER:$APP_USER "$DOCROOT/apibase"
chmod -R 775 "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"

echo "== rewrite rules =="
# The managed block is replaced wholesale each run; cPanel's own
# directives above it are never touched.
sed -i '/# === Netvork routing/,/# === end Netvork routing ===/d' "$DOCROOT/.htaccess" 2>/dev/null || true
cat >> "$DOCROOT/.htaccess" <<'HTEOF'

# === Netvork routing (managed by deploy/cpanel/publish.sh) ===
RewriteEngine On

# force https
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# API + broadcasting auth -> Laravel
RewriteRule ^(api|broadcasting)(/.*)?$ apibase/index.php [L]

# the site root serves the brand landing page (signed-in visitors are
# bounced to /dashboard by the page itself)
RewriteRule ^$ landing/index.html [L]

# a missing build asset is a 404, never the SPA
#
# The rsync above runs with --delete, so a deploy removes the previous
# build's hashed chunks. A browser still holding the old index.html then
# asks for one of them, and without this rule it fell through to the SPA
# fallback and got index.html back with a 200 and Content-Type: text/html.
# Browsers refuse to run HTML as a module, so the page died with "error
# loading dynamically imported module" - a message about the wrong thing.
# A plain 404 is the truth, and the app's own recovery keys off it.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^assets/ - [R=404,L]

# mark real build assets so the caching block below can find them
# (FilesMatch cannot see the directory, only the filename)
RewriteRule ^assets/ - [E=IMMUTABLE:1]

# everything else -> the SPA (real files served as-is)
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # index.html is the list of which hashed chunks to load, so a cached copy
    # of it is a cached copy of yesterday's app - and the deploy that fixes
    # something reaches nobody until their browser decides to ask again.
    <FilesMatch "\.html$">
        Header always set Cache-Control "no-cache, must-revalidate"
    </FilesMatch>

    # A stale service worker keeps serving a stale app and is the hardest
    # thing for someone to clear without being talked through it.
    <FilesMatch "^sw\.js$">
        Header always set Cache-Control "no-cache, must-revalidate"
    </FilesMatch>

    # Contents of /assets/ carry a content hash in the filename, so what lives
    # at a given URL can never change. Safe to cache for a year.
    Header always set Cache-Control "public, max-age=31536000, immutable" env=IMMUTABLE
</IfModule>
# === end Netvork routing ===
HTEOF
echo "   routing block written (cPanel's own directives left intact)"

echo "== caching config =="
cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP artisan config:cache
sudo -u $APP_USER $PHP artisan route:cache
sudo -u $APP_USER $PHP artisan view:cache

echo "== done =="
