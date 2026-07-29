# My PA — Project Plan

**My PA** is a production-grade personal assistance platform: tasks, reminders, notes, files,
family/team groups, real-time chat, audio/video calls, habits, goals, reports, and a
Cashfree-powered subscription system.

- **Location:** `D:\SOFTWARE-LOCAL\mypa`
- **Backend:** Laravel 12 (PHP 8.4), MariaDB 11.4 (MySQL-compatible), Redis, Sanctum, Queues, Scheduler — `backend/`
- **Frontend:** React 19 + TypeScript + Vite + Tailwind CSS 4, React Router, TanStack Query, Zustand, Axios (PWA-ready) — `frontend/`
- **Real-time:** Laravel Reverb (WebSockets) + WebRTC (STUN/TURN via env)

## Architecture

```
mypa/
├── backend/               Laravel 12 REST API (api.mypa.local / localhost:8000)
│   ├── app/
│   │   ├── Http/Controllers/Api/V1/     versioned API controllers
│   │   ├── Http/Middleware/             role / subscription / entitlement middleware
│   │   ├── Models/
│   │   ├── Services/                    business logic (AppIdService, TaskService, …)
│   │   ├── Policies/                    authorization
│   │   └── Jobs/                        queued work (reminders, notifications, …)
│   ├── database/migrations|seeders|factories
│   └── routes/api.php                   /api/v1/*
└── frontend/              React SPA (localhost:5173) → talks to backend via Axios
    └── src/
        ├── api/           axios client + endpoint modules
        ├── components/    reusable UI
        ├── pages/         route-level pages (auth, dashboard, tasks, admin, …)
        ├── stores/        zustand stores (auth, ui)
        └── lib/           helpers
```

## Development Phases (from spec §31 + §34.27)

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Setup, auth, roles/permissions, profile, App ID, admin foundation, DB structure | **In progress** |
| 2 | Categories, tasks, subtasks, checklists, assignments, recurring, reminders, notifications, calendar | Pending |
| 3 | Notes, files, family/team groups, reports | Pending |
| 4 | Real-time chat, voice messages, audio/video calls (WebRTC) | Pending |
| 5 | Voice task creation, STT/TTS, Hindi + English | Pending |
| 6 | Habits, goals, bill reminders, analytics, subscription architecture | Pending |
| 7 | Testing, security review, performance, docs, production readiness | Pending |
| 8 (§34) | Subscriptions, plans, Cashfree payments (7 sub-steps: plans DB → entitlements → sandbox checkout → webhooks/verification → invoices → upgrades/coupons/taxes → recurring/reconciliation) | Pending |

## Key design decisions

1. **API-first**: every feature is an authenticated `/api/v1/*` endpoint; the SPA is a pure client. Mobile apps (React Native/Capacitor) can reuse the API unchanged.
2. **UUIDs external, bigint internal**: models expose `uuid` in API resources; DB relations use numeric FKs.
3. **App ID**: `MYPA-100001` sequence stored in `app_ids`, generated in a DB transaction on registration; searchable subject to privacy settings.
4. **Roles**: `super_admin`, `admin`, `subadmin`, `user` via a lightweight role/permission schema (roles, permissions, role_user, permission_role + per-user overrides).
5. **Entitlements**: plan limits enforced server-side by `SubscriptionEntitlementService` + middleware (`EnsurePlanFeature`, `EnsurePlanLimit`) — never frontend-only.
6. **Payments**: `PaymentGatewayInterface` abstraction; `CashfreePaymentGateway` implementation; webhook + server-side verification is the source of truth.
7. **Reminders**: `task_reminders` rows materialized into queued jobs by the scheduler each minute; user timezone respected.
8. **No secrets in code**: all credentials via `.env`; `.env.example` ships without real values.
