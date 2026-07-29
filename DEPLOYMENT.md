# My PA — Production Deployment Guide

## Architecture in production

```
┌─ Nginx (TLS) ─────────────────────────────────────┐
│  /            → frontend/dist (static SPA + PWA)  │
│  /api, /up    → PHP-FPM (Laravel backend/public)  │
│  /app (ws)    → Reverb :8080 (WebSockets)         │
└───────────────────────────────────────────────────┘
   MySQL/MariaDB · queue worker · scheduler (cron) · Reverb (daemonized)
```

## 1. Server requirements

- PHP 8.3+ (FPM) with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `intl`, `pcntl` (Linux)
- MySQL 8 / MariaDB 10.6+
- Node 20+ (build only — not needed at runtime)
- Nginx (or Apache) with TLS — **HTTPS is required** (Sanctum tokens, WebRTC `getUserMedia`, PWA)
- Supervisor/systemd for the queue worker and Reverb

## 2. Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env — see checklist below
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=DefaultCategorySeeder --force
php artisan db:seed --class=PlanSeeder --force
# do NOT run DemoSeeder in production
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

### .env hardening checklist

| Key | Production value |
|---|---|
| APP_ENV / APP_DEBUG | `production` / `false` |
| APP_URL / FRONTEND_URL | your real HTTPS origins (drives CORS) |
| DB_* | dedicated DB user, strong password (never root) |
| MAIL_* | real SMTP provider |
| REVERB_APP_ID/KEY/SECRET | fresh random values (`php artisan reverb:key` or random hex) |
| REVERB_HOST / REVERB_SCHEME | public hostname / `https` |
| STUN_SERVER_URL / TURN_* | your TURN server (required for calls across NATs) |
| MYPA_MAX_UPLOAD_KB / MYPA_STORAGE_LIMIT_BYTES | as desired (plan limits override storage) |

Also: `SESSION_SECURE_COOKIE=true`, keep `.env` outside web root (default), and never
commit it. Rotate `APP_KEY` only with a re-encryption plan.

### Services

**Queue worker** (Supervisor):

```ini
[program:mypa-queue]
command=php /srv/mypa/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
```

**Reverb** (Supervisor):

```ini
[program:mypa-reverb]
command=php /srv/mypa/backend/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
```

**Scheduler** (cron):

```
* * * * * cd /srv/mypa/backend && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler drives: reminder dispatch (every minute), recurring tasks (hourly),
bill reminders (daily 08:00), notification pruning (daily).

## 3. Frontend

```bash
cd frontend
# .env: point websockets at the public host
#   VITE_REVERB_APP_KEY=<same as backend REVERB_APP_KEY>
#   VITE_REVERB_HOST=your-domain.com  VITE_REVERB_PORT=443  VITE_REVERB_SCHEME=https
npm ci && npm run build   # → dist/
```

Serve `dist/` as static files with an SPA fallback to `index.html`.

## 4. Nginx sketch

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /srv/mypa/frontend/dist;

    location /api { try_files $uri /index-api.php$is_args$args; }   # or fastcgi_pass to backend/public
    location /app {                                                  # Reverb websocket
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
    location / { try_files $uri $uri/ /index.html; }                 # SPA + PWA
}
```

(Adapt `/api` to your PHP-FPM setup — `alias` to `backend/public` with fastcgi, or a
subdomain `api.your-domain.com` with `FRONTEND_URL` set accordingly.)

## 5. Post-deploy checks

- `GET https://…/up` → 200 (health)
- `GET https://…/api/v1/plans` → plan list with security headers
- Register a user → App ID assigned, verification email delivered
- Log in as your (renamed) admin → **change the password immediately if you seeded demo data**
- Reminder smoke test: create a task due in 2 minutes with a reminder → notification arrives
- WebSocket: browser devtools → `wss://…/app/<key>` connects; chat updates live
- Calls: two browsers, audio call connects (verify TURN if peers are on different networks)

## 6. Backups & operations

- **Database**: nightly `mysqldump` (all tables — includes plans/subscriptions/audit logs)
- **Files**: back up `backend/storage/app/private` (user files, chat attachments)
- **Logs**: `backend/storage/logs/`; audit trail in the `audit_logs` table (admin → Audit logs API)
- Renew TLS automatically (certbot); monitor queue depth (`jobs` table) and failed jobs (`failed_jobs`)

## 7. Known production notes

- Redis is optional. For multi-server scaling later: set `CACHE_STORE=redis`,
  `QUEUE_CONNECTION=redis`, and configure Reverb scaling (Redis ≥ 6.2).
- Server-side speech-to-text is off by default (browser Web Speech API is used);
  bind a Whisper/Google/Azure implementation to `SpeechToTextInterface` to enable.
## 8. Enabling Cashfree payments

Payments stay disabled (checkout returns 503) until credentials are configured.

1. Create a Cashfree merchant account (cashfree.com) and complete KYC.
2. In the Cashfree dashboard, copy the **sandbox** App ID + Secret Key first.
3. Set in `.env`: `CASHFREE_ENV=sandbox`, `CASHFREE_APP_ID`, `CASHFREE_SECRET_KEY`
   (never commit these; they are backend-only and never reach the frontend).
4. Configure the webhook in the Cashfree dashboard:
   `https://your-domain.com/api/webhooks/cashfree` (signature is verified with
   your secret key; invalid signatures are rejected with 401).
5. Test the full sandbox flow: /pricing → checkout → Cashfree test payment →
   redirect to /payment/status → plan active + invoice issued. Cashfree provides
   test cards/UPI in sandbox mode.
6. Only after the sandbox flow works end-to-end, switch `CASHFREE_ENV=production`
   with your production credentials (spec §34.27).
7. Tax: `BILLING_TAX_PERCENT_BP` (1800 = 18% GST), `BILLING_TAX_LABEL`,
   seller details via `BILLING_SELLER_*` (shown on invoices).

Refunds are issued from the admin API by a super admin and are executed at the
gateway; refunds never auto-reactivate or auto-cancel subscriptions.
