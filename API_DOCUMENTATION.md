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

## Notes

| Method | Endpoint | Notes |
|---|---|---|
| GET | /notes?q=&group= | paginated; locked notes return no content |
| POST | /notes | title, body / checklist[], type, color, is_pinned, password?, group_uuid? |
| GET | /notes/{uuid} | 423 when locked; send `X-Note-Password` header to unlock |
| PUT | /notes/{uuid} | snapshots a version first; password change is owner-only |
| DELETE | /notes/{uuid} | owner only |
| POST | /notes/{uuid}/share | { app_id, permission: view\|edit } — locked notes can't be shared |
| GET | /notes/{uuid}/versions | last 20 versions |

## Files & Folders

| Method | Endpoint | Notes |
|---|---|---|
| GET | /files/browse?folder= | folders + files + breadcrumb + usage |
| POST | /files/upload | multipart `files[]` (≤10), optional folder_uuid; size/extension/quota checks |
| GET | /files/{uuid}/download | authenticated streamed download |
| PUT | /files/{uuid} | rename / move (folder_uuid) |
| DELETE | /files/{uuid} | → trash |
| GET | /files/trash · POST /files/{uuid}/restore · DELETE /files/{uuid}/force | trash flow |
| POST | /files/{uuid}/share | { app_id } |
| GET | /files/shared-with-me · /files/usage | |
| POST/PUT/DELETE | /folders[/{uuid}] | folder CRUD (nested via parent_uuid) |

## Groups

| Method | Endpoint | Notes |
|---|---|---|
| CRUD | /groups | member-visible; owner deletes |
| POST | /groups/{uuid}/members | { app_id, role } — owner/admin only |
| PUT | /groups/{uuid}/members/{userUuid} | { role } |
| DELETE | /groups/{uuid}/members/{userUuid} | remove, or leave (self) |
| GET | /groups/{uuid}/tasks | group task list |

Tasks and events accept `group_uuid`; group items are visible to all group members.

## Reports

| Method | Endpoint | Notes |
|---|---|---|
| GET | /reports/summary | totals, completion rate, avg completion hours, by status/priority/category |
| GET | /reports/productivity?days=30 | per-day created/completed series (7–90 days) |
| GET | /reports/export.csv | task export, UTF-8 BOM |

## Chat

| Method | Endpoint | Notes |
|---|---|---|
| GET | /conversations | ordered by last message; unread counts |
| POST | /conversations | { app_id } — privacy-checked (who_can_message) |
| GET | /groups/{uuid}/conversation | group chat, membership auto-synced |
| POST | /conversations/{uuid}/read · /mute · /archive | per-member toggles |
| GET | /conversations/{uuid}/messages?before=&q= | newest 30, keyset pagination |
| POST | /conversations/{uuid}/messages | body / attachments[] (multipart), type, reply_to, duration_seconds |
| PUT | /conversations/{uuid}/messages/{msg} | edit own text message |
| DELETE | /conversations/{uuid}/messages/{msg}?for=me\|everyone | tombstone on everyone |
| POST | /conversations/{uuid}/messages/{msg}/react | { emoji } toggles |
| GET | /conversations/{uuid}/attachments/{id} | authenticated download |

**WebSockets:** Reverb on port 8080. Channel auth `POST /api/broadcasting/auth` (Bearer).
Channels: `private-conversation.{uuid}` (`message.sent`, `message.updated`),
`private-user.{uuid}` (`call.signal`). Start with `php artisan reverb:start`.

## Calls

| Method | Endpoint | Notes |
|---|---|---|
| GET | /calls/config | ICE servers (STUN/TURN from env) |
| POST | /conversations/{uuid}/calls | { type: audio\|video } — rings the other member |
| POST | /calls/{uuid}/respond | { action: accept\|decline } |
| POST | /calls/{uuid}/end | hang up / cancel (missed while ringing) |
| POST | /calls/{uuid}/signal | { signal: offer\|answer\|ice, payload } relayed to peer |
| GET | /calls/history | direction, duration, missed flag |

## Voice Assistant

| Method | Endpoint | Notes |
|---|---|---|
| POST | /voice/interpret | { transcript, language: en\|hi } → { intent, data, speech } — nothing is saved; the client shows a review card and then calls the normal task APIs |
| POST | /voice/transcribe | 501 until a server STT provider is bound to `SpeechToTextInterface` (browser Web Speech API is the default recognizer) |

Intents: `create_task` (title, due_at in user-local time, priority, repeat_config,
category hint, reminder offsets), `complete_task` (fuzzy-matched open task), `query_tasks`
(filters for the tasks list), `unknown`.

## Habits

| Method | Endpoint | Notes |
|---|---|---|
| GET | /habits | with streaks, today status, 7-day strip |
| POST/PUT/DELETE | /habits[/{uuid}] | name, frequency (daily/weekly/monthly), target_per_period, color |
| POST | /habits/{uuid}/log | `{}` increments today; `{count}` sets (0 removes); `{date}` backfills |

## Goals

| Method | Endpoint | Notes |
|---|---|---|
| GET | /goals | own + group goals; progress derived from milestones |
| POST/PUT/DELETE | /goals[/{uuid}] | type, target_date, motivation, group_uuid, milestones[] |
| POST | /goals/{uuid}/milestones | add |
| POST | /goals/{uuid}/milestones/{id}/toggle | all-done auto-completes the goal |
| DELETE | /goals/{uuid}/milestones/{id} | |

## Bills

| Method | Endpoint | Notes |
|---|---|---|
| GET | /bills?status=unpaid | own + group bills, unpaid first |
| POST/PUT/DELETE | /bills[/{uuid}] | amount, due_on, repeat_frequency, remind_days_before |
| POST | /bills/{uuid}/pay | recurring bills spawn the next occurrence |

Daily scheduler job `mypa:send-bill-reminders` notifies within each bill's reminder window.

## Plans & Subscription

| Method | Endpoint | Notes |
|---|---|---|
| GET | /plans | public; active+public plans with limits/features |
| GET | /subscription | current plan (defaults to Free), status, usage (tasks/storage/groups) |
| GET | /admin/plans | all plans incl. private (admin) |
| PUT | /admin/plans/{slug} | edit prices/limits/features (super admin) |
| POST | /admin/users/{uuid}/plan | { plan_slug, months?, note? } manual assignment (audited) |

Plan limits are enforced server-side (tasks, storage, groups, group members);
blocked requests return 422 with a `message` and an `upgrade_plan` hint.

## Billing & Payments (Cashfree)

All amounts are calculated on the backend in integer paise; API responses show
decimal strings. Sandbox/production is selected by `CASHFREE_ENV`.

| Method | Endpoint | Notes |
|---|---|---|
| POST | /subscription/quote | { plan_slug, frequency, coupon? } → base/discount/tax/total (validates coupon) |
| POST | /subscription/checkout | creates internal + Cashfree order → { order_uuid, payment_session_id, gateway_mode }; duplicate clicks reuse the pending order; 503 when Cashfree isn't configured |
| POST | /payments/{order}/verify | server-side verification (pollable); activates the plan only on gateway-confirmed PAID with exact amount+currency match |
| GET | /payments | payment history with refund totals |
| GET | /invoices · /invoices/{uuid} | list + printable HTML invoice (browser print → PDF) |
| POST | /subscription/cancel | cancel at period end (access retained until ends_at) |
| POST | /api/webhooks/cashfree | unauthenticated; HMAC signature verified (x-webhook-signature/timestamp), stored + deduped, processed via queue |

**Admin** (`/admin/billing/*`): payments search, webhook log, coupons CRUD
(fixed paise / percent basis-points with caps, plan/frequency restrictions, usage
limits), refunds (super admin, partial/full with over-refund guard).

**Lifecycle:** `mypa:subscription-lifecycle` (daily 07:30) expires ended
subscriptions (→ Free plan + notification), sends renewal reminders at
15/7/3/1/0 days, and cleans stale checkout orders.

**Frontend:** `/pricing` (public), checkout dialog using the official Cashfree
JS SDK (`sdk.cashfree.com/js/v3`), `/payment/status?order=` verification page,
billing history + invoices in Settings.
