#!/bin/bash
# Install the long-running pieces: queue worker, Reverb websocket server,
# the per-minute scheduler cron, and the Apache websocket proxy.
# Run as root:  bash deploy/cpanel/install-services.sh
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
LOGDIR=/home/$APP_USER/logs

mkdir -p "$LOGDIR"
chown $APP_USER:$APP_USER "$LOGDIR"

echo "== systemd: queue worker =="
cat > /etc/systemd/system/netvork-queue.service <<EOF
[Unit]
Description=Netvork queue worker (reminders, emails, push)
After=network.target mysql.service

[Service]
User=$APP_USER
Group=$APP_USER
Restart=always
RestartSec=3
WorkingDirectory=$APP_DIR/backend
ExecStart=$PHP artisan queue:work database --sleep=1 --tries=3 --timeout=300
StandardOutput=append:$LOGDIR/netvork-queue.log
StandardError=append:$LOGDIR/netvork-queue.log

[Install]
WantedBy=multi-user.target
EOF

echo "== systemd: reverb websocket server =="
# No --host/--port here on purpose. Reverb reads REVERB_SERVER_HOST/PORT and
# the TLS cert paths from backend/.env, and it serves wss:// directly on 8443.
# Passing those flags overrides the .env and drops Reverb onto plain 8080,
# which is what took production down on 2026-08-04: browsers could not reach
# it, and PHP's own publish to https://127.0.0.1:8443 was refused, so every
# meeting join, call and chat broadcast answered 500.
cat > /etc/systemd/system/netvork-reverb.service <<EOF
[Unit]
Description=Netvork Reverb websocket server
After=network.target

[Service]
User=$APP_USER
Group=$APP_USER
Restart=always
RestartSec=3
WorkingDirectory=$APP_DIR/backend
ExecStart=$PHP artisan reverb:start
StandardOutput=append:$LOGDIR/netvork-reverb.log
StandardError=append:$LOGDIR/netvork-reverb.log

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now netvork-queue netvork-reverb

echo "== cron: per-minute scheduler (existing entries preserved) =="
TMP=$(mktemp)
crontab -u $APP_USER -l > "$TMP" 2>/dev/null || true
if grep -q 'netvork/backend/artisan schedule:run' "$TMP"; then
  echo "   already present"
else
  echo "* * * * * $PHP $APP_DIR/backend/artisan schedule:run >> $LOGDIR/netvork-schedule.log 2>&1" >> "$TMP"
  crontab -u $APP_USER "$TMP"
  echo "   added"
fi
rm -f "$TMP"

echo "== cron: keep Reverb's certificate in step with AutoSSL =="
CRONLINE="17 3 * * 1 bash $APP_DIR/deploy/cpanel/refresh-ssl.sh >> $LOGDIR/netvork-ssl.log 2>&1"
( crontab -l 2>/dev/null | grep -v 'refresh-ssl.sh'; echo "$CRONLINE" ) | crontab -
echo "   weekly root cron installed"

# NOTE: no Apache websocket proxy is installed.
#
# Reverb terminates TLS itself and browsers connect straight to
# wss://$DOMAIN:8443 — the frontend build has that port baked in, and
# refresh-ssl.sh keeps the certificate in step with AutoSSL. An earlier design
# ran Reverb plain on 8080 behind a `/app` proxy; deploy/cpanel/websocket-proxy.conf
# is the leftover from it and is NOT in use. Installing both at once is what
# broke production on 2026-08-04.

echo
echo "== status =="
systemctl --no-pager --lines=3 status netvork-queue  | head -6
systemctl --no-pager --lines=3 status netvork-reverb | head -6
if ss -ltnp | grep -q ':8443'; then
  echo "   reverb listening on 8443 (wss direct)"
else
  echo "!! reverb NOT listening on 8443 — check $LOGDIR/netvork-reverb.log"
  echo "   usual causes: $APP_USER cannot read REVERB_TLS_CERT/REVERB_TLS_KEY,"
  echo "   or REVERB_SERVER_PORT in backend/.env is not 8443."
fi
echo "== done =="
