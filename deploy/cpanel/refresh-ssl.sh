#!/bin/bash
# Keep Reverb's (and coturn's) certificate in step with AutoSSL.
#
# cPanel renews the domain certificate roughly every 60 days, but Reverb reads
# its own copy - without this the websocket would start serving an expired
# certificate and every browser would refuse to connect. Installed as a weekly
# root cron by install-services.sh; safe to run by hand any time.
set -e

APP_USER=${APP_USER:-grapme}
DOMAIN=${DOMAIN:-netvork.app}
SSLDIR=/home/$APP_USER/ssl-netvork

SRC=/var/cpanel/ssl/apache_tls/$DOMAIN/combined
[ -f "$SRC" ] || SRC="$(ls -d /var/cpanel/ssl/apache_tls/*"$DOMAIN"* 2>/dev/null | head -1)/combined"
[ -f "$SRC" ] || { echo "no certificate found for $DOMAIN"; exit 1; }

# Nothing to do when the live copy already matches.
NEW_HASH=$(awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/' "$SRC" | sha256sum | cut -d' ' -f1)
OLD_HASH=$(sha256sum "$SSLDIR/fullchain.pem" 2>/dev/null | cut -d' ' -f1)
[ "$NEW_HASH" = "$OLD_HASH" ] && exit 0

mkdir -p "$SSLDIR"
awk '/BEGIN (RSA |EC )?PRIVATE KEY/,/END (RSA |EC )?PRIVATE KEY/' "$SRC" > "$SSLDIR/privkey.pem"
awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/' "$SRC" > "$SSLDIR/fullchain.pem"
chown -R $APP_USER:$APP_USER "$SSLDIR"
chmod 700 "$SSLDIR"; chmod 600 "$SSLDIR"/*.pem

systemctl restart netvork-reverb

if [ -d /etc/coturn/certs ]; then
  for U in coturn turnserver; do id -u "$U" >/dev/null 2>&1 && SVC=$U && break; done
  cp -f "$SSLDIR/fullchain.pem" "$SSLDIR/privkey.pem" /etc/coturn/certs/
  chown -R "${SVC:-root}":"${SVC:-root}" /etc/coturn/certs
  chmod 600 /etc/coturn/certs/*.pem
  systemctl restart coturn 2>/dev/null || true
fi

echo "$(date): certificate refreshed for $DOMAIN, reverb restarted"
