#!/bin/bash
# Serve the Reverb websocket over TLS on its own port, bypassing Apache.
#
# cPanel's Apache will not reliably tunnel websockets for an addon domain,
# so Reverb terminates TLS itself on port 8443 using the domain's existing
# certificate. Run as root:  bash deploy/cpanel/setup-reverb-tls.sh
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
WSS_PORT=${WSS_PORT:-8443}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
SSLDIR=/home/$APP_USER/ssl-netvork

echo "== copying the domain certificate somewhere $APP_USER can read =="
mkdir -p "$SSLDIR"
SRC=/var/cpanel/ssl/apache_tls/$DOMAIN/combined
if [ ! -f "$SRC" ]; then
  echo "!! $SRC not found - is AutoSSL issued for $DOMAIN?"
  ls /var/cpanel/ssl/apache_tls/ | head
  exit 1
fi

# "combined" holds key + cert + chain; split what Reverb needs.
awk '/BEGIN (RSA |EC )?PRIVATE KEY/,/END (RSA |EC )?PRIVATE KEY/' "$SRC" > "$SSLDIR/privkey.pem"
awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/' "$SRC" > "$SSLDIR/fullchain.pem"

chown -R $APP_USER:$APP_USER "$SSLDIR"
chmod 700 "$SSLDIR"
chmod 600 "$SSLDIR"/*.pem
echo "   cert: $(openssl x509 -in "$SSLDIR/fullchain.pem" -noout -subject -enddate | tr '\n' ' ')"

echo "== pointing the app at it =="
ENV=$APP_DIR/backend/.env
sed -i '/^REVERB_TLS_CERT=/d;/^REVERB_TLS_KEY=/d;/^REVERB_SERVER_HOST=/d;/^REVERB_SERVER_PORT=/d;/^REVERB_PORT=/d;/^REVERB_SCHEME=/d;/^REVERB_HOST=/d' "$ENV"
cat >> "$ENV" <<EOF
REVERB_HOST=$DOMAIN
REVERB_PORT=$WSS_PORT
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=$WSS_PORT
REVERB_TLS_CERT=$SSLDIR/fullchain.pem
REVERB_TLS_KEY=$SSLDIR/privkey.pem
EOF

KEY=$(grep '^REVERB_APP_KEY=' "$ENV" | cut -d= -f2)
cat > "$APP_DIR/frontend/.env.production" <<EOF
VITE_REVERB_APP_KEY=$KEY
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=$WSS_PORT
VITE_REVERB_SCHEME=https
EOF
chown $APP_USER:$APP_USER "$APP_DIR/frontend/.env.production"

echo "== opening port $WSS_PORT =="
if [ -x /usr/sbin/csf ]; then
  grep -q "^TCP_IN.*\b$WSS_PORT\b" /etc/csf/csf.conf || \
    sed -i "s/^TCP_IN = \"\(.*\)\"/TCP_IN = \"\1,$WSS_PORT\"/" /etc/csf/csf.conf
  csf -r >/dev/null 2>&1 || true
  echo "   csf reloaded"
elif systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port=$WSS_PORT/tcp >/dev/null
  firewall-cmd --reload >/dev/null
  echo "   firewalld updated"
else
  echo "   no csf/firewalld detected - make sure $WSS_PORT/tcp is reachable"
fi

echo "== restarting reverb on $WSS_PORT (TLS) =="
sed -i "s|--host=127.0.0.1 --port=8080|--host=0.0.0.0 --port=$WSS_PORT|" /etc/systemd/system/netvork-reverb.service
systemctl daemon-reload
cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP artisan config:cache
systemctl restart netvork-reverb netvork-queue
sleep 3

echo
echo "== status =="
ss -ltnp | grep ":$WSS_PORT" || echo "!! not listening on $WSS_PORT"
tail -5 /home/$APP_USER/logs/netvork-reverb.log
echo
echo "Next: rebuild the frontend so the browser uses port $WSS_PORT:"
echo "  cd $APP_DIR/frontend && sudo -u $APP_USER npm run build"
echo "  bash $APP_DIR/deploy/cpanel/publish.sh"
