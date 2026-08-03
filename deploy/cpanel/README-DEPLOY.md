# Netvork — cPanel/WHM production deployment (netvork.app)

Architecture: **one origin**. The `netvork.app` docroot serves the built React
frontend; `.htaccess` hands `/api/*` and `/broadcasting/*` to Laravel (via the
`apibase` symlink to `backend/public`), and Apache proxies the `/app` WebSocket
path to the Reverb server on `127.0.0.1:8080`. No CORS, no cookie domains.

```
Browser ── https://netvork.app ─┬─ /            → public_html (React build + /landing)
                                ├─ /api, /broadcasting → apibase/index.php (Laravel)
                                └─ wss /app     → Apache proxy → Reverb :8080

systemd: netvork-queue (queue:work database), netvork-reverb (reverb:start)
cron   : * * * * * artisan schedule:run   (reminders, alarms, daily reports)
```

## Layout on the server

| Path | Purpose |
|---|---|
| `/home/netvork/mypa` | git clone of this repo |
| `/home/netvork/public_html` | frontend `dist/` + `.htaccess` + `apibase` symlink |
| `/home/netvork/logs` | queue.log, reverb.log |
| `/etc/systemd/system/netvork-*.service` | long-running workers |
| `/etc/apache2/conf.d/userdata/{ssl,std}/2_4/netvork/netvork.app/websocket.conf` | WS proxy |

## First install (summary — the assistant runs these with you stage by stage)

1. **Audit**: PHP ≥8.2 (`ea-php83`), composer, git, node ≥20, `proxy_wstunnel`
   module, server IP; point `netvork.app` DNS at the server.
2. **Account**: WHM `createacct` for user `netvork` / domain `netvork.app`;
   set the domain's PHP version to ea-php83; AutoSSL.
3. **Code**: clone the GitHub repo to `~/mypa` as user `netvork`.
4. **Database**: create `netvork_app` DB + user via `uapi Mysql`.
5. **Backend `.env`**: production values (APP_ENV=production, APP_DEBUG=false,
   APP_URL=https://netvork.app, DB, MAIL via localhost, fresh REVERB creds,
   QUEUE_CONNECTION=database, APP_ID_PREFIX=NV, VAPID keys). Then
   `composer install --no-dev`, `key:generate` (first time only!),
   `migrate --force`, `db:seed` for plans/roles, `config:cache`.
6. **Frontend**: `.env.production` (REVERB host `netvork.app`, port 443,
   scheme https, same app key), `npm ci && npm run build`, publish `dist/`
   to `public_html`, install the `.htaccess` from this folder, create the
   `apibase` symlink.
7. **Services**: install the two systemd units + the schedule cron; install
   the Apache websocket include; `/scripts/rebuildhttpdconf`; restart httpd.
8. **Verify**: register a user, OTP email arrives, chat over wss, a call
   connects, scheduler writes to laravel.log.

## Updating an already-deployed server

```bash
bash /home/netvork/mypa/deploy/cpanel/deploy.sh
```

## Notes

- Existing sites on the server are untouched: everything lives in the new
  `netvork` account; only global additions are the two systemd units and the
  per-vhost Apache include.
- Cashfree is in sandbox until real merchant keys are set in `.env`
  (`CASHFREE_*`) — payments page will show sandbox behaviour until then.
- Email deliverability: cPanel signs DKIM/SPF for netvork.app automatically;
  OTP mail sends through localhost sendmail as no-reply@netvork.app.
- Never run `key:generate` or `db:seed` again after go-live (breaks encrypted
  data / duplicates seed rows).
