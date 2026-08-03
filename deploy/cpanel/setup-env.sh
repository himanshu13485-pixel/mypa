#!/bin/bash
# Generate the production backend/.env for Netvork on the cPanel server.
# Run as root:  bash deploy/cpanel/setup-env.sh
# Safe to re-run: it refuses to overwrite an existing .env (rename it first).
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
DB_NAME=${DB_NAME:-grapme_netvork}
DB_USER=${DB_USER:-grapme_netvork}
DB_PASS_FILE=${DB_PASS_FILE:-/root/netvork-db-pass.txt}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
ENV_FILE=$APP_DIR/backend/.env

if [ -f "$ENV_FILE" ]; then
  echo "!! $ENV_FILE already exists - rename it first if you really want to regenerate."
  exit 1
fi

DB_PASS=$(cat "$DB_PASS_FILE")
REVERB_ID=$(shuf -i 100000000-999999999 -n 1)
REVERB_KEY=$(openssl rand -hex 10)
REVERB_SECRET=$(openssl rand -hex 10)

# VAPID keypair for web push
VAPID=$(cd "$APP_DIR/backend" && $PHP -r "require 'vendor/autoload.php'; \$k = Minishlink\WebPush\VAPID::createVapidKeys(); echo \$k['publicKey'], '|', \$k['privateKey'];")
VAPID_PUB=${VAPID%%|*}
VAPID_PRIV=${VAPID##*|}

cat > "$ENV_FILE" <<ENVEOF
APP_NAME=Netvork
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=.$DOMAIN
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=sendmail
MAIL_SENDMAIL="/usr/sbin/sendmail -bs -i"
MAIL_FROM_ADDRESS=no-reply@$DOMAIN
MAIL_FROM_NAME=Netvork

# --- Netvork ---
FRONTEND_URL=https://$DOMAIN
APP_ID_PREFIX=NV
APP_ID_START=100001

STUN_SERVER_URL=stun:stun.l.google.com:19302
TURN_SERVER_URL=
TURN_USERNAME=
TURN_CREDENTIAL=

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=$REVERB_ID
REVERB_APP_KEY=$REVERB_KEY
REVERB_APP_SECRET=$REVERB_SECRET
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

CASHFREE_ENV=sandbox
CASHFREE_APP_ID=
CASHFREE_SECRET_KEY=
CASHFREE_API_VERSION=2023-08-01

BILLING_TAX_LABEL=GST
BILLING_TAX_PERCENT_BP=1800
BILLING_SELLER_NAME=Netvork
BILLING_SELLER_ADDRESS=
BILLING_SELLER_TAX_NUMBER=

VAPID_PUBLIC_KEY=$VAPID_PUB
VAPID_PRIVATE_KEY=$VAPID_PRIV
VAPID_SUBJECT=mailto:admin@$DOMAIN
ENVEOF

chown $APP_USER:$APP_USER "$ENV_FILE"
chmod 600 "$ENV_FILE"

# Frontend build-time config (public values only)
cat > "$APP_DIR/frontend/.env.production" <<FEEOF
VITE_REVERB_APP_KEY=$REVERB_KEY
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
FEEOF
chown $APP_USER:$APP_USER "$APP_DIR/frontend/.env.production"

echo "Wrote $ENV_FILE and frontend/.env.production"
echo "Reverb app key (public, used by the browser): $REVERB_KEY"
