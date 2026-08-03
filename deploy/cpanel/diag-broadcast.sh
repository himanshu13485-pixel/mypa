#!/bin/bash
# Diagnose private-channel authorization end to end.
#   bash deploy/cpanel/diag-broadcast.sh
# Mints a throwaway token, calls /api/broadcasting/auth exactly as the browser
# does, and reports what the server actually answers.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
DOMAIN=${DOMAIN:-netvork.app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}

cd "$APP_DIR/backend"

# The box is behind NAT and cannot reach its own public IP - resolve locally.
LOCAL_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')

echo "== which build is published =="
grep -o 'authorizer' "$APP_DIR"/frontend/dist/assets/index-*.js >/dev/null 2>&1 \
  && echo "   frontend dist HAS the explicit authorizer" \
  || echo "   !! frontend dist does NOT have the authorizer - rebuild + publish needed"
grep -o 'authorizer' /home/$APP_USER/public_html/$DOMAIN/assets/index-*.js >/dev/null 2>&1 \
  && echo "   docroot HAS the explicit authorizer" \
  || echo "   !! docroot does NOT have it - run publish.sh"

echo
echo "== minting a throwaway token =="
CREDS=$(sudo -u $APP_USER $PHP artisan tinker --execute='
$u = App\Models\User::whereNotNull("email")->first();
echo $u->uuid, "|", $u->createToken("diag")->plainTextToken;
' | tail -1 | tr -d '\r')
UUID=${CREDS%%|*}
TOKEN=${CREDS##*|}
echo "   user: $UUID"

echo
echo "== POST /api/broadcasting/auth (as the browser does) =="
RESP=$(curl -s -w '\nHTTP %{http_code}' --resolve $DOMAIN:443:$LOCAL_IP \
  -X POST "https://$DOMAIN/api/broadcasting/auth" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"socket_id\":\"123.456\",\"channel_name\":\"private-user.$UUID\"}")
echo "$RESP"

echo
echo "Reading it:"
echo "  200 + an \"auth\" value -> channel authorization works; the browser bundle is the problem"
echo "  401                    -> the token is not being accepted (header stripped, or guard misconfigured)"
echo "  403                    -> authenticated but the channel callback denied it"
echo "  404                    -> the broadcasting route is not registered"

echo
echo "== same call WITHOUT the token (should be 401) =="
curl -s -o /dev/null -w '   HTTP %{http_code}\n' --resolve $DOMAIN:443:$LOCAL_IP \
  -X POST "https://$DOMAIN/api/broadcasting/auth" -H "Accept: application/json" \
  -d "socket_id=123.456&channel_name=private-user.$UUID"

echo
echo "== does Apache pass the Authorization header at all? =="
curl -s --resolve $DOMAIN:443:$LOCAL_IP "https://$DOMAIN/api/v1/me" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" | head -c 160
echo
echo "   (a JSON user object above means bearer auth works on ordinary routes)"

sudo -u $APP_USER $PHP artisan tinker --execute='
App\Models\PersonalAccessToken::where("name","diag")->delete();
echo "diag tokens removed";
' 2>/dev/null | tail -1
