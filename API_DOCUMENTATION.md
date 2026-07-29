# My PA — API Documentation

Base URL (dev): `http://localhost:8000/api/v1`
Auth: Bearer token (Laravel Sanctum personal access tokens).
All responses are JSON: `{ "data": … }` or `{ "message": …, "errors": … }` on failure.
Rate limiting: 60 req/min authenticated, 10 req/min on auth endpoints.

## Auth

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| POST | /auth/register | name, email, mobile?, password, password_confirmation, country?, timezone?, language?, account_type?, referral_app_id? | creates user + profile + settings + App ID; returns user + token |
| POST | /auth/login | email, password, device_name? | returns token; records login history |
| POST | /auth/logout | — | revokes current token |
| POST | /auth/forgot-password | email | sends reset link |
| POST | /auth/reset-password | token, email, password, password_confirmation | |
| POST | /auth/change-password | current_password, password, password_confirmation | auth required |
| POST | /auth/email/verification-notification | — | resend verify mail |
| GET | /auth/email/verify/{id}/{hash} | — | signed URL |
| GET | /auth/sessions | — | active tokens list |
| DELETE | /auth/sessions/{tokenId} | — | revoke one session |
| GET | /auth/login-history | — | paginated |

## Me / Profile

| Method | Endpoint | Notes |
|---|---|---|
| GET | /me | current user with profile, settings, roles, app_id |
| PUT | /me/profile | update profile fields |
| PUT | /me/settings | theme, privacy, notification prefs, dashboard layout |
| POST | /me/photo | multipart profile photo |

## App ID & Connections

| Method | Endpoint | Notes |
|---|---|---|
| GET | /app-id/search?q=MYPA-100001 | respects target privacy settings |
| GET | /me/app-id/qr | QR payload (SVG) for my App ID |
| POST | /connections | { app_id, message? } send request |
| GET | /connections?status=pending | list |
| PUT | /connections/{uuid} | { action: accept\|decline } |
| DELETE | /connections/{uuid} | remove connection |
| POST | /blocks | { app_id } block user |
| DELETE | /blocks/{app_id} | unblock |
| GET | /blocks | my blocked list |

## Categories

CRUD `/categories` (+ `?tree=1` nested). Defaults are read-only system rows (user_id null).
Share: `POST /categories/{uuid}/share { app_id, permission }`.

## Tasks

| Method | Endpoint | Notes |
|---|---|---|
| GET | /tasks | filters: status, priority, category, date_from, date_to, important, overdue, assigned_to_me, assigned_by_me, q, tags; sort + pagination |
| POST | /tasks | full task payload incl. checklist[], reminders[], assignees[] |
| GET | /tasks/{uuid} | with relations |
| PUT | /tasks/{uuid} | |
| DELETE | /tasks/{uuid} | soft delete |
| POST | /tasks/{uuid}/status | { status } |
| POST | /tasks/{uuid}/progress | { progress } |
| POST | /tasks/{uuid}/duplicate | |
| POST | /tasks/{uuid}/archive · /pin · /favourite | toggles |
| POST | /tasks/{uuid}/checklist | add item; PUT/DELETE /tasks/{uuid}/checklist/{id} |
| POST | /tasks/{uuid}/comments | add comment |
| POST | /tasks/{uuid}/assign | { app_ids: [] } |
| GET | /tasks/{uuid}/activity | activity log |
| GET | /dashboard/summary | counts: today, upcoming, overdue, important, completed, pending, completion % |

## Admin (`role: admin|super_admin`, prefix /admin)

| Method | Endpoint | Notes |
|---|---|---|
| GET | /admin/stats | totals: users, active, suspended, online, tasks, storage |
| GET | /admin/users | search + filters + pagination |
| POST | /admin/users | create user (admin/subadmin/user) |
| GET/PUT | /admin/users/{uuid} | view / update |
| POST | /admin/users/{uuid}/suspend · /activate | |
| POST | /admin/users/{uuid}/roles | { roles: [] } (super admin only for admin role) |
| POST | /admin/users/{uuid}/app-id/regenerate | exceptional cases |
| GET | /admin/roles · /admin/permissions | list |
| GET | /admin/login-histories | all users |

## Reminders

| Method | Endpoint | Notes |
|---|---|---|
| GET | /reminders/upcoming | pending reminders for open tasks |
| POST | /reminders/{id}/snooze | { minutes } or { until } |
| POST | /reminders/{id}/acknowledge | dismiss permanently |

Reminder scheduling: `mypa:process-reminders` runs every minute (Laravel scheduler) and
dispatches queued `SendTaskReminder` jobs. Run `php artisan schedule:work` + `php artisan queue:work` in dev.

## Notifications

| Method | Endpoint | Notes |
|---|---|---|
| GET | /notifications?unread=1 | paginated |
| GET | /notifications/unread-count | `{ data: { count } }` |
| POST | /notifications/read-all | |
| POST | /notifications/{id}/read | |
| DELETE | /notifications/{id} | |

## Events & Calendar

| Method | Endpoint | Notes |
|---|---|---|
| GET | /calendar/feed?date_from&date_to | `{ events: [], tasks: [] }` combined |
| GET | /calendar/export.ics | ICS download (events + due tasks) |
| CRUD | /events | type, starts_at/ends_at, all_day, location, meeting_link, participants (App IDs) |
| POST | /events/{uuid}/respond | { status: accepted\|declined\|tentative } |

## Subtasks

Tasks accept `parent_uuid` (one level of nesting). Top-level task lists exclude subtasks;
use `GET /tasks?parent={uuid}` to list a task's subtasks. Task detail includes `subtasks[]`.

**Timezones:** all submitted datetimes are interpreted in the authenticated user's profile
timezone and stored/returned as UTC ISO-8601.

## Planned (later phases)
Notes, files, chat, calls, groups, habits, goals, reports,
subscription/payment endpoints (see spec §34.19), webhook `POST /api/webhooks/cashfree`.
