# Netvork — cPanel/WHM production deployment (netvork.app)

Architecture: **one origin for HTTP, Reverb on its own port.** The `netvork.app`
docroot serves the built React frontend; `.htaccess` hands `/api/*` and
`/broadcasting/*` to Laravel (via the `apibase` symlink to `backend/public`).
Reverb terminates TLS itself and browsers connect straight to
`wss://netvork.app:8443`. No CORS, no cookie domains, no Apache WS proxy.

```
Browser ── https://netvork.app ─┬─ /            → public_html (React build + /landing)
                                └─ /api, /broadcasting → apibase/index.php (Laravel)
        └─ wss://netvork.app:8443 ──────────────→ Reverb (own TLS, 0.0.0.0:8443)

systemd: netvork-queue (queue:work database), netvork-reverb (reverb:start)
cron   : * * * * * artisan schedule:run          (reminders, alarms, daily reports)
         17 3 * * 1 refresh-ssl.sh               (keeps Reverb's cert with AutoSSL)
```

The port lives in three places and they must agree: `REVERB_SERVER_PORT` and
`REVERB_INTERNAL_PORT` in `backend/.env`, and `VITE_REVERB_PORT` baked into the
frontend build. `netvork-reverb.service` must pass **no** `--host/--port`, or it
overrides the `.env`. See `websocket-proxy.conf` for the abandoned 8080-proxy
alternative and why the two must never be mixed.

## Layout on the server

| Path | Purpose |
|---|---|
| `/home/grapme/netvork` | git clone of this repo |
| `/home/grapme/public_html/netvork.app` | frontend `dist/` + `.htaccess` + `apibase` symlink |
| `/home/grapme/logs` | netvork-queue.log, netvork-reverb.log, netvork-schedule.log |
| `/home/grapme/ssl-netvork` | Reverb's TLS cert + key (refresh-ssl.sh) |
| `/etc/systemd/system/netvork-*.service` | long-running workers |

## First install (summary — the assistant runs these with you stage by stage)

1. **Audit**: PHP ≥ 8.4.1 (`ea-php84` — `composer.lock` rejects 8.3), composer,
   git, node ≥20, server IP; point `netvork.app` DNS at the server, and make
   sure port 8443 is open inbound for the WebSocket.
2. **Account**: WHM `createacct` for the hosting user / domain `netvork.app`;
   set the domain's PHP version to ea-php84; AutoSSL.
3. **Code**: clone the GitHub repo to `~/netvork` as the hosting user.
4. **Database**: create `netvork_app` DB + user via `uapi Mysql`.
5. **Backend `.env`**: production values (APP_ENV=production, APP_DEBUG=false,
   APP_URL=https://netvork.app, DB, MAIL via localhost, fresh REVERB creds,
   `REVERB_SERVER_PORT=8443` with `REVERB_TLS_CERT`/`REVERB_TLS_KEY`,
   `REVERB_INTERNAL_*` pointing at `https://127.0.0.1:8443`,
   QUEUE_CONNECTION=database, APP_ID_PREFIX=NV, VAPID keys). Then
   `composer install --no-dev`, `key:generate` (first time only!),
   `migrate --force`, `db:seed` for plans/roles, `config:cache`.
6. **Frontend**: `.env.production` (REVERB host `netvork.app`, **port 8443**,
   scheme https, same app key), `npm ci && npm run build`, publish `dist/`
   to the docroot, install the `.htaccess` from this folder, create the
   `apibase` symlink.
7. **Services**: `bash deploy/cpanel/install-services.sh` — the two systemd
   units, the per-minute scheduler cron and the weekly cert refresh.
8. **Verify**: register a user, OTP email arrives, chat over wss, a call
   connects, scheduler writes to laravel.log. `bash deploy/cpanel/diag-broadcast.sh`
   exercises the whole broadcast path end to end.

## Updating an already-deployed server

```bash
bash /home/grapme/netvork/deploy/cpanel/deploy.sh
```

It refuses to start if the paths or the PHP version are wrong, rather than
dying part-way through — a deploy that stops after `git pull` but before
`artisan migrate` leaves the database behind the code, and that surfaces later
as unrelated-looking 500s.

## Notes

- Existing sites on the server are untouched: everything lives in the app
  account; the only global additions are the two systemd units.
- **Mail must work.** An account whose email is unverified cannot use the app
  (`EnsureVerifiedEmail`), and the only way in is the emailed code. If
  `MAIL_MAILER=log`, nobody can register or recover. To rescue a locked-out
  account: `UPDATE users SET email_verified_at = NOW() WHERE email = '…';`
- **The queue worker is not optional.** Chat messages, read receipts and
  notifications broadcast through it; if it stops they silently stop arriving
  while calls and meetings keep working. Worth monitoring `netvork-queue`.
- Cashfree is in sandbox until real merchant keys are set in `.env`
  (`CASHFREE_*`) — payments page will show sandbox behaviour until then.
- Email deliverability: cPanel signs DKIM/SPF for netvork.app automatically;
  OTP mail sends through localhost sendmail as no-reply@netvork.app.
- Never run `key:generate` or `db:seed` again after go-live (breaks encrypted
  data / duplicates seed rows).
