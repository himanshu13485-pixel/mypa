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
ExecStart=$PHP artisan reverb:start --host=127.0.0.1 --port=8080
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

echo "== apache: websocket proxy for wss://$DOMAIN/app =="
# An addon domain's vhost is named after its subdomain form
# (netvork.app.grapme.com), so write the include for every vhost of this
# account whose name mentions the domain - including the plain one.
VHOSTS=$(ls /var/cpanel/userdata/$APP_USER 2>/dev/null \
         | grep -F "$DOMAIN" | sed 's/_SSL$//' | sort -u)
[ -n "$VHOSTS" ] || VHOSTS=$DOMAIN
echo "   vhosts: $(echo $VHOSTS | tr '\n' ' ')"

for VHOST in $VHOSTS; do
  for MODE in std ssl; do
    DIR=/etc/apache2/conf.d/userdata/$MODE/2_4/$APP_USER/$VHOST
    mkdir -p "$DIR"
    cat > "$DIR/websocket.conf" <<EOF
# Netvork: tunnel the Reverb websocket (managed by install-services.sh)
ProxyPreserveHost On
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^/?app/(.*) ws://127.0.0.1:8080/app/\$1 [P,L]
ProxyPass        /app ws://127.0.0.1:8080/app
ProxyPassReverse /app ws://127.0.0.1:8080/app
EOF
  done
done

/scripts/ensure_vhost_includes --user=$APP_USER || true
/scripts/rebuildhttpdconf
systemctl restart httpd || /scripts/restartsrv_httpd

echo "== proxy present in compiled config? =="
grep -c '127.0.0.1:8080' /etc/apache2/conf/httpd.conf || echo "!! NOT PRESENT"

echo
echo "== status =="
systemctl --no-pager --lines=3 status netvork-queue  | head -6
systemctl --no-pager --lines=3 status netvork-reverb | head -6
ss -ltnp | grep 8080 || echo "!! reverb not listening on 8080 - check $LOGDIR/netvork-reverb.log"
echo "== done =="
