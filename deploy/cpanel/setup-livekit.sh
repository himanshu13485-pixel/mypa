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
  # A range, not udp_port — those are alternatives. Setting both said "one
  # port" and "ten thousand ports" in the same breath.
  port_range_start: $RTC_MIN
  port_range_end: $RTC_MAX
  # Discovers the public address itself, so ICE candidates point somewhere
  # reachable rather than at a private interface nobody outside can route to.
  # Also an alternative to node_ip rather than a companion; this one needs no
  # maintenance if the address ever changes.
  use_external_ip: true

keys:
  $API_KEY: $API_SECRET

# TLS is NOT terminated here. LiveKit has no tls: section at all — inventing
# one is what made it exit 0 on every start with "field tls not found in type
# config.Config", seventy-seven times before anyone read the log. It expects a
# proxy in front, and this box already has the right one: Apache holds the
# AutoSSL certificate for the subdomain.
YAML

chmod 600 "$CONFIG"

echo "== apache in front (TLS) =="
# LiveKit stays on plain HTTP on the loopback side; Apache does the certificate
# on 443 for $SFU_DOMAIN. Only signalling goes through here — the media is UDP
# straight to the box on the range above and never touches Apache.
if [ ! -d /etc/apache2/conf.d/userdata ]; then
  echo "!! no cPanel Apache userdata directory — set up a proxy for"
  echo "   https://$SFU_DOMAIN -> http://127.0.0.1:$WS_PORT yourself."
  SCHEME=wss
else
  INC=/etc/apache2/conf.d/userdata/ssl/2_4/$APP_USER/$SFU_DOMAIN
  mkdir -p "$INC"
  cat > "$INC/livekit.conf" <<APACHE
# Managed by deploy/cpanel/setup-livekit.sh
# Signalling only. Media is UDP direct to this host and does not come past here.
ProxyPreserveHost On
ProxyRequests Off
# The websocket upgrade has to be matched before the generic rule, or Apache
# proxies it as ordinary HTTP and the connection closes as soon as it opens.
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^/(.*) ws://127.0.0.1:$WS_PORT/\$1 [P,L]
ProxyPass / http://127.0.0.1:$WS_PORT/
ProxyPassReverse / http://127.0.0.1:$WS_PORT/
APACHE

  # Websockets need mod_proxy_wstunnel; without it the upgrade is refused and
  # the meeting never gets past "connecting".
  if ! httpd -M 2>/dev/null | grep -q proxy_wstunnel; then
    echo "!! mod_proxy_wstunnel is not loaded. Install it, or signalling will fail:"
    echo "   yum -y install ea-apache24-mod_proxy_wstunnel && systemctl restart httpd"
  fi

  /scripts/ensure_vhost_includes --user="$APP_USER" >/dev/null 2>&1 \
    || echo "!! ensure_vhost_includes failed — run it by hand, then restart httpd"
  systemctl restart httpd >/dev/null 2>&1 || true
  echo "   proxying https://$SFU_DOMAIN -> http://127.0.0.1:$WS_PORT"
  SCHEME=wss
fi


echo "== firewall =="
# CSF first: that is what WHM installs, and a box running it usually has
# firewalld stopped. Checking only firewalld would report success on a WHM
# server and leave the UDP range shut, which does not look like a firewall
# problem from the outside — the meeting connects, and then stays black.
if [ -x /usr/sbin/csf ]; then
  cp -n /etc/csf/csf.conf /etc/csf/csf.conf.before-livekit 2>/dev/null || true
  # Only the UDP range. 7880 is reached over the loopback by Apache, so opening
  # it publicly would expose the unencrypted signalling port for nothing.
  grep -q "$RTC_MIN:$RTC_MAX" /etc/csf/csf.conf \
    || sed -i "s/^UDP_IN = \"\(.*\)\"/UDP_IN = \"\1,$RTC_MIN:$RTC_MAX\"/" /etc/csf/csf.conf
  csf -r >/dev/null 2>&1 || true
  echo "   csf updated: $RTC_MIN-$RTC_MAX/udp (7880 stays loopback-only, behind Apache)"
  echo "   (previous config saved as /etc/csf/csf.conf.before-livekit)"
elif command -v firewall-cmd >/dev/null && firewall-cmd --state >/dev/null 2>&1; then
  firewall-cmd --permanent --add-port=$RTC_MIN-$RTC_MAX/udp >/dev/null
  firewall-cmd --reload >/dev/null
  echo "   firewalld updated: $RTC_MIN-$RTC_MAX/udp (7880 stays loopback-only, behind Apache)"
else
  echo "!! no firewall manager found — open $RTC_MIN-$RTC_MAX/udp yourself."
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

# is-active exits non-zero for anything but "active", including the perfectly
# ordinary "activating" of a service two seconds old — and under set -e that
# killed this script before it wrote any of the settings below, leaving LiveKit
# installed and the app with no idea it existed.
STATE=starting
for _ in 1 2 3 4 5 6 7 8 9 10; do
  STATE=$(systemctl is-active livekit || true)
  [ "$STATE" = active ] && break
  sleep 1
done
echo "   service: $STATE"
if [ "$STATE" != active ]; then
  echo "!! livekit is not up. The settings below are still written, so nothing is"
  echo "   left half-done — but check:  journalctl -u livekit -n 40 --no-pager"
fi

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
set_env LIVEKIT_URL "$SCHEME://$SFU_DOMAIN"
set_env LIVEKIT_API_KEY "$API_KEY"
set_env LIVEKIT_API_SECRET "$API_SECRET"
sudo -u $APP_USER $PHP "$APP_DIR/backend/artisan" config:cache >/dev/null

echo
echo "== done =="
echo "   signalling : $SCHEME://$SFU_DOMAIN  (Apache -> 127.0.0.1:$WS_PORT)"
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
