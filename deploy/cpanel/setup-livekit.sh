#!/bin/bash
# Install and configure LiveKit, the SFU that lets a meeting grow past the
# handful of people a peer-to-peer mesh can carry.
# Run as root:  bash deploy/cpanel/setup-livekit.sh
#
# Why an SFU at all: in a mesh everybody sends their own picture to everybody
# else, so a participant's upload grows with the room — six people need about
# 7.5 Mbps each and ten need 15, which no phone and few homes have. With an
# SFU each person sends one stream here and this server copies it out. Their
# upload stops growing; ours starts.
#
# What that costs, so it is not a surprise: a twenty-person meeting is roughly
# 30 Mbps in and 60 Mbps out, sustained, which is about 40 GB per hour. Check
# that against the host's transfer allowance before turning it on for everyone.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
SFU_DOMAIN=${SFU_DOMAIN:-sfu.$DOMAIN}
SSLDIR=/home/$APP_USER/ssl-netvork
# The signalling port the browser connects to over wss://.
WS_PORT=${WS_PORT:-7880}
# UDP range for media. Wide on purpose: one port per participant connection.
RTC_MIN=${RTC_MIN:-50000}
RTC_MAX=${RTC_MAX:-60000}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}

command -v curl >/dev/null || { echo "!! curl is required"; exit 1; }

echo "== detecting addresses =="
PUB_IP=$(curl -s --max-time 10 https://ipinfo.io/ip)
[ -n "$PUB_IP" ] || { echo "!! could not determine the public address"; exit 1; }
echo "   public: $PUB_IP"

echo "== installing livekit-server =="
if ! command -v livekit-server >/dev/null; then
  curl -sSL https://get.livekit.io | bash
fi
echo "   $(livekit-server --version 2>&1 | head -1)"

echo "== keys =="
# Generated once and kept. Re-running must not invalidate the tokens the app
# is already handing out, so an existing config is left alone.
CONFIG=/etc/livekit/livekit.yaml
mkdir -p /etc/livekit
if [ -f "$CONFIG" ] && grep -q 'keys:' "$CONFIG"; then
  API_KEY=$(awk '/^keys:/{getline; print $1}' "$CONFIG" | tr -d ':')
  API_SECRET=$(awk '/^keys:/{getline; print $2}' "$CONFIG")
  echo "   reusing the existing key ($API_KEY)"
else
  API_KEY="API$(head -c 16 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 12)"
  API_SECRET=$(head -c 48 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 48)
  echo "   generated a new key ($API_KEY)"
fi

echo "== writing $CONFIG =="
cat > "$CONFIG" <<YAML
# Managed by deploy/cpanel/setup-livekit.sh — re-running rewrites this file.
port: $WS_PORT
rtc:
  udp_port: $RTC_MIN
  port_range_start: $RTC_MIN
  port_range_end: $RTC_MAX
  use_external_ip: true
  # The public address, so ICE candidates point somewhere reachable rather
  # than at a private interface nobody outside the box can route to.
  node_ip: $PUB_IP

keys:
  $API_KEY: $API_SECRET

# TLS is terminated here rather than behind Apache: LiveKit's signalling is a
# websocket carrying media negotiation, and proxying it through cPanel's vhost
# config is one more thing to get wrong for no benefit. Reverb does the same.
YAML

if [ -f "$SSLDIR/cert.pem" ] && [ -f "$SSLDIR/privkey.pem" ]; then
  cat >> "$CONFIG" <<YAML
tls:
  cert_file: $SSLDIR/cert.pem
  key_file: $SSLDIR/privkey.pem
YAML
  SCHEME=wss
  echo "   TLS on, using $SSLDIR"
else
  SCHEME=ws
  echo "!! no certificate at $SSLDIR — running without TLS."
  echo "   Browsers refuse insecure websockets from an https:// page, so this"
  echo "   is only good for a local test. Run refresh-ssl.sh, then re-run this."
fi

chmod 600 "$CONFIG"

echo "== firewall =="
# CSF first: that is what WHM installs, and a box running it usually has
# firewalld stopped. Checking only firewalld would report success on a WHM
# server and leave the UDP range shut, which does not look like a firewall
# problem from the outside — the meeting connects, and then stays black.
if [ -x /usr/sbin/csf ]; then
  cp -n /etc/csf/csf.conf /etc/csf/csf.conf.before-livekit 2>/dev/null || true
  grep -q "\b$WS_PORT\b" /etc/csf/csf.conf \
    || sed -i "s/^TCP_IN = \"\(.*\)\"/TCP_IN = \"\1,$WS_PORT\"/" /etc/csf/csf.conf
  grep -q "$RTC_MIN:$RTC_MAX" /etc/csf/csf.conf \
    || sed -i "s/^UDP_IN = \"\(.*\)\"/UDP_IN = \"\1,$RTC_MIN:$RTC_MAX\"/" /etc/csf/csf.conf
  csf -r >/dev/null 2>&1 || true
  echo "   csf updated: $WS_PORT/tcp and $RTC_MIN-$RTC_MAX/udp"
  echo "   (previous config saved as /etc/csf/csf.conf.before-livekit)"
elif command -v firewall-cmd >/dev/null && firewall-cmd --state >/dev/null 2>&1; then
  firewall-cmd --permanent --add-port=$WS_PORT/tcp >/dev/null
  firewall-cmd --permanent --add-port=$RTC_MIN-$RTC_MAX/udp >/dev/null
  firewall-cmd --reload >/dev/null
  echo "   firewalld updated: $WS_PORT/tcp and $RTC_MIN-$RTC_MAX/udp"
else
  echo "!! no firewall manager found — open $WS_PORT/tcp and $RTC_MIN-$RTC_MAX/udp yourself."
  echo "   Media is UDP; without that range the meeting connects and then stays black."
fi

echo "== service =="
cat > /etc/systemd/system/livekit.service <<UNIT
# /etc/systemd/system/livekit.service — managed by setup-livekit.sh
[Unit]
Description=LiveKit SFU for Netvork meetings
After=network.target

[Service]
User=root
Restart=always
RestartSec=3
ExecStart=/usr/local/bin/livekit-server --config $CONFIG
StandardOutput=append:/home/$APP_USER/logs/livekit.log
StandardError=append:/home/$APP_USER/logs/livekit.log

[Install]
WantedBy=multi-user.target
UNIT

mkdir -p /home/$APP_USER/logs
chown $APP_USER:$APP_USER /home/$APP_USER/logs
systemctl daemon-reload
systemctl enable --now livekit >/dev/null 2>&1 || systemctl restart livekit
sleep 2
systemctl is-active livekit

echo "== pointing the app at it =="
ENV_FILE="$APP_DIR/backend/.env"
set_env () {
  # Replace in place if present, append if not — so re-running does not
  # accumulate duplicate keys that the last one silently wins.
  if grep -q "^$1=" "$ENV_FILE"; then
    sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
  else
    echo "$1=$2" >> "$ENV_FILE"
  fi
}
set_env LIVEKIT_ENABLED true
set_env LIVEKIT_URL "$SCHEME://$SFU_DOMAIN:$WS_PORT"
set_env LIVEKIT_API_KEY "$API_KEY"
set_env LIVEKIT_API_SECRET "$API_SECRET"
sudo -u $APP_USER $PHP "$APP_DIR/backend/artisan" config:cache >/dev/null

echo
echo "== done =="
echo "   signalling : $SCHEME://$SFU_DOMAIN:$WS_PORT"
echo "   media      : UDP $RTC_MIN-$RTC_MAX"
echo "   log        : /home/$APP_USER/logs/livekit.log"
echo
echo "Before this works end to end — none of which WHM does for you:"
echo "  1. $SFU_DOMAIN must resolve to $PUB_IP. Add it as a subdomain in"
echo "     cPanel, which creates the DNS record and brings it into AutoSSL."
echo "  2. The certificate at $SSLDIR must then cover $SFU_DOMAIN, or the"
echo "     browser refuses the websocket without saying much about why."
echo "     refresh-ssl.sh copies the AutoSSL cert into place."
echo "  3. Check the host's transfer allowance. A twenty-person meeting is"
echo "     about 40 GB an hour through this box; that is the number that"
echo "     decides whether this is affordable, not CPU."
echo "  4. Meetings stay on the mesh until LIVEKIT_MESH_UP_TO is set, or"
echo "     left empty — empty means every meeting uses the SFU."
