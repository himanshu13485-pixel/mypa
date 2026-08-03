#!/bin/bash
# Point Netvork at any TURN service (hosted or self-run).
#
#   bash deploy/cpanel/set-turn.sh "<turn-urls>" "<username>" "<credential>"
#
# turn-urls may be comma-separated, e.g.
#   "turn:relay.example.com:3478?transport=udp,turns:relay.example.com:5349?transport=tcp"
#
# No rebuild is needed: the browser fetches ICE servers from the API at call
# time, so a reload is enough.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
ENV=$APP_DIR/backend/.env

URLS=$1
USERNAME=$2
CREDENTIAL=$3

if [ -z "$URLS" ] || [ -z "$USERNAME" ] || [ -z "$CREDENTIAL" ]; then
  echo 'usage: bash set-turn.sh "<turn-urls>" "<username>" "<credential>"'
  echo 'current settings:'
  grep -E '^(STUN|TURN)_' "$ENV" | sed 's/^/   /'
  exit 1
fi

cp "$ENV" "$ENV.bak.$(date +%s 2>/dev/null || echo backup)" 2>/dev/null || true
sed -i '/^TURN_SERVER_URL=/d;/^TURN_USERNAME=/d;/^TURN_CREDENTIAL=/d' "$ENV"
cat >> "$ENV" <<EOF
TURN_SERVER_URL=$URLS
TURN_USERNAME=$USERNAME
TURN_CREDENTIAL=$CREDENTIAL
EOF

cd "$APP_DIR/backend"
sudo -u $APP_USER $PHP artisan config:cache >/dev/null
echo "TURN updated:"
grep -E '^(STUN|TURN)_' "$ENV" | sed 's/^TURN_CREDENTIAL=.*/TURN_CREDENTIAL=(set)/' | sed 's/^/   /'
echo
echo "Reload the app in both browsers and rejoin - no rebuild required."
