# My PA — Personal Assistance App & Website

A scalable personal assistance platform: tasks, reminders, categories, connections (unique
My PA App IDs), family/team features, and an admin panel — with chat, calls, files, habits,
goals, and Cashfree subscriptions coming in later phases.

- **Backend:** Laravel 12 · PHP 8.3+ · MariaDB/MySQL · Sanctum — [`backend/`](backend/)
- **Frontend:** React 19 · TypeScript · Vite · Tailwind CSS 4 · TanStack Query · Zustand — [`frontend/`](frontend/)
- Planning docs: [PROJECT_PLAN.md](PROJECT_PLAN.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [API_DOCUMENTATION.md](API_DOCUMENTATION.md) · [PROGRESS.md](PROGRESS.md) · [CHANGELOG.md](CHANGELOG.md)

## System requirements (Windows)

| Requirement | Tested with |
|---|---|
| PHP | 8.4 (XAMPP: `C:\xampp\apps\php`) |
| Composer | 2.10 |
| MariaDB / MySQL | MariaDB 11.4 (XAMPP: `C:\xampp\apps\mysql`) |
| Node.js + npm | Node 24 |
| Redis (later phases: queues/websockets) | ≥ 6.2 recommended |

## Installation

### 1. Database

Start MariaDB (XAMPP control panel, or):

```bash
C:\xampp\apps\mysql\bin\mysqld.exe --datadir="C:/xampp/apps/mysql/data" --port=3306
```

Create the database (done automatically on first setup):

```bash
C:\xampp\apps\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS mypa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Backend

```bash
cd D:\SOFTWARE-LOCAL\mypa\backend
composer install
copy .env.example .env        # then set DB_* values (defaults match XAMPP root/no-password)
php artisan key:generate
php artisan migrate --seed
php artisan serve             # http://localhost:8000
```

### 3. Frontend

```bash
cd D:\SOFTWARE-LOCAL\mypa\frontend
npm install
npm run dev                   # http://localhost:5173 (proxies /api → :8000)
```

### 4. Queues & scheduler (needed for reminders in Phase 2+)

```bash
php artisan queue:work
php artisan schedule:work
```

## Demo accounts

Seeded by `php artisan db:seed`. **Password for all: `MyPa@Demo123`**

| Role | Email | App ID |
|---|---|---|
| Super Admin | superadmin@mypa.local | MYPA-100001 |
| Admin | admin@mypa.local | MYPA-100002 |
| Subadmin | subadmin1@mypa.local / subadmin2@mypa.local | MYPA-100003/4 |
| Users | rahul@ / priya@ / amit@ / sneha@ / vikram@mypa.local | MYPA-100005…9 |

> ⚠️ **Change the Super Admin password immediately after first login.** These are demo
> credentials for local development only.

## Testing

```bash
cd backend && php artisan test     # 31 tests, in-memory SQLite
cd frontend && npm run build       # type-check + production build
```

## Troubleshooting

- **`could not find driver`** — enable `extension=pdo_mysql` in `php.ini`.
- **Migrations fail to connect** — confirm MariaDB is on port 3306 and `.env` `DB_*` values match.
- **401s from the SPA** — the token is stored in `localStorage` (`mypa-auth`); log in again.
- **CORS errors** — dev uses the Vite proxy, so the browser only ever talks to `:5173`.
- **Mail links** — dev uses the `log` mailer; reset/verify links land in `storage/logs/laravel.log`.

## Project status

Phase 1 complete (auth, roles/permissions, App IDs, connections, categories, tasks core,
dashboards, admin foundation). See [PROGRESS.md](PROGRESS.md) for the live checklist and
[PROJECT_PLAN.md](PROJECT_PLAN.md) for the phase roadmap.
