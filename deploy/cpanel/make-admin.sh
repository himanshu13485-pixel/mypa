#!/bin/bash
# Grant a role to an existing Netvork account (and mark it verified/active).
#
#   bash deploy/cpanel/make-admin.sh <username-or-email> [role-slug]
#
# role-slug defaults to super_admin. Valid: super_admin, admin, subadmin,
# salesperson, user.
set -e

APP_USER=${APP_USER:-grapme}
APP_DIR=${APP_DIR:-/home/$APP_USER/netvork}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}

HANDLE=$1
ROLE=${2:-super_admin}

if [ -z "$HANDLE" ]; then
  echo "usage: bash make-admin.sh <username-or-email> [role-slug]"
  exit 1
fi

cd "$APP_DIR/backend"

sudo -u $APP_USER HANDLE="$HANDLE" ROLE="$ROLE" $PHP artisan tinker --execute='
$handle = getenv("HANDLE");
$roleSlug = getenv("ROLE");

$user = App\Models\User::where("username", $handle)->orWhere("email", $handle)->first();
if (! $user) { echo "No account found for: {$handle}\n"; exit(1); }

$role = App\Models\Role::where("slug", $roleSlug)->first();
if (! $role) { echo "No such role: {$roleSlug}\n"; exit(1); }

$user->roles()->sync([$role->id]);
$user->forceFill([
    "email_verified_at" => $user->email_verified_at ?? now(),
    "status" => "active",
])->save();

echo "OK  {$user->name} <{$user->email}> is now {$role->name}";
echo "  (App ID: " . optional($user->appId)->app_id . ")\n";
'
