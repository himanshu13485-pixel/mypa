#!/bin/bash
# Install and configure coturn so WebRTC media can be relayed when the two
# browsers cannot reach each other directly (NAT / restrictive networks).
# Run as root:  bash deploy/cpanel/setup-turn.sh
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
SSLDIR=/home/$APP_USER/ssl-netvork
MIN_PORT=${MIN_PORT:-49160}
MAX_PORT=${MAX_PORT:-49260}

echo "== detecting addresses =="
PRIV_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
PUB_IP=$(curl -s --max-time 10 https://ipinfo.io/ip)
echo "   private: $PRIV_IP   public: $PUB_IP"
[ -n "$PRIV_IP" ] && [ -n "$PUB_IP" ] || { echo "!! could not determine addresses"; exit 1; }

echo "== installing coturn =="
rpm -q epel-release >/dev/null 2>&1 || dnf -y install epel-release >/dev/null
rpm -q coturn >/dev/null 2>&1 || dnf -y install coturn >/dev/null
echo "   $(turnserver -o --help 2>/dev/null | head -1; rpm -q coturn)"

echo "== certificate for turns:// =="
# The package's service account differs by distro build.
for U in coturn turnserver; do id -u "$U" >/dev/null 2>&1 && SVC_USER=$U && break; done
SVC_USER=${SVC_USER:-root}
echo "   coturn runs as: $SVC_USER"

mkdir -p /etc/coturn/certs
if [ -f "$SSLDIR/fullchain.pem" ]; then
  cp -f "$SSLDIR/fullchain.pem" "$SSLDIR/privkey.pem" /etc/coturn/certs/
  chown -R "$SVC_USER":"$SVC_USER" /etc/coturn/certs
  chmod 600 /etc/coturn/certs/*.pem
  TLS_LINES="cert=/etc/coturn/certs/fullchain.pem
pkey=/etc/coturn/certs/privkey.pem
tls-listening-port=5349"
  echo "   copied from $SSLDIR"
else
  TLS_LINES="# no certificate found - turns:// disabled"
  echo "   !! $SSLDIR/fullchain.pem missing - run setup-reverb-tls.sh first for TLS relay"
fi

TURN_USER=netvork
TURN_PASS=$(openssl rand -hex 16)

echo "== writing /etc/coturn/turnserver.conf =="
cat > /etc/coturn/turnserver.conf <<EOF
# Netvork TURN relay (managed by deploy/cpanel/setup-turn.sh)
listening-port=3478
listening-ip=$PRIV_IP
# The box is behind NAT: advertise the public address, map it to the private one.
external-ip=$PUB_IP/$PRIV_IP
relay-ip=$PRIV_IP

realm=$DOMAIN
server-name=$DOMAIN
lt-cred-mech
user=$TURN_USER:$TURN_PASS

min-port=$MIN_PORT
max-port=$MAX_PORT

$TLS_LINES

fingerprint
no-multicast-peers
no-cli
# Do not relay to private ranges - a TURN server open to the LAN is a hazard.
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
allowed-peer-ip=$PRIV_IP

simple-log
log-file=/var/log/turnserver.log
EOF
chmod 640 /etc/coturn/turnserver.conf

echo "== firewall =="
PORTS="3478/tcp 3478/udp 5349/tcp 5349/udp $MIN_PORT-$MAX_PORT/udp"
if [ -x /usr/sbin/csf ]; then
  for P in 3478 5349; do
    grep -q "\b$P\b" /etc/csf/csf.conf || sed -i "s/^TCP_IN = \"\(.*\)\"/TCP_IN = \"\1,$P\"/;s/^UDP_IN = \"\(.*\)\"/UDP_IN = \"\1,$P\"/" /etc/csf/csf.conf
  done
  grep -q "$MIN_PORT:$MAX_PORT" /etc/csf/csf.conf || sed -i "s/^UDP_IN = \"\(.*\)\"/UDP_IN = \"\1,$MIN_PORT:$MAX_PORT\"/" /etc/csf/csf.conf
  csf -r >/dev/null 2>&1 || true
  echo "   csf updated"
elif systemctl is-active --quiet firewalld; then
  for P in $PORTS; do firewall-cmd --permanent --add-port=$P >/dev/null; done
  firewall-cmd --reload >/dev/null
  echo "   firewalld updated: $PORTS"
else
  echo "   !! no firewall manager found - open $PORTS manually"
fi

echo "== starting coturn =="
systemctl enable --now coturn
systemctl restart coturn
sleep 2

echo "== pointing the app at the relay =="
ENV=$APP_DIR/backend/.env
sed -i '/^TURN_SERVER_URL=/d;/^TURN_USERNAME=/d;/^TURN_CREDENTIAL=/d;/^STUN_SERVER_URL=/d' "$ENV"
cat >> "$ENV" <<EOF
STUN_SERVER_URL=stun:$DOMAIN:3478
TURN_SERVER_URL=turn:$DOMAIN:3478?transport=udp,turn:$DOMAIN:3478?transport=tcp,turns:$DOMAIN:5349?transport=tcp
TURN_USERNAME=$TURN_USER
TURN_CREDENTIAL=$TURN_PASS
EOF
cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP artisan config:cache

echo
echo "== status =="
systemctl --no-pager --lines=3 status coturn | head -5
ss -lnup | grep 3478 || echo "!! nothing listening on udp/3478"
echo
echo "TURN credentials stored in $ENV (TURN_USERNAME / TURN_CREDENTIAL)."
echo "Test the relay at https://icetest.info or webrtc.github.io/samples/src/content/peerconnection/trickle-ice/"
echo "   turn:$DOMAIN:3478   user: $TURN_USER"
