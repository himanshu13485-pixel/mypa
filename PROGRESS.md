# My PA — Progress Log

> Updated after each completed module. Newest first.

## 2026-07-30 — Passwordless signup & OTP login ✅ (user-requested)

- [x] Password removed from signup: an account is created with just name + mobile +
      username and secured entirely by the mobile OTP verified inline at signup
- [x] "Login with code" mode on the sign-in page: enter mobile/username/email →
      a one-time code goes to the account's app inbox (visible on signed-in devices
      and to admins) → exchange for a session; successful OTP login also marks the
      mobile verified
- [x] Password becomes optional: Settings shows "Set a password (optional)" for
      passwordless accounts (no current-password needed the first time); subsequent
      changes require the current password as before
- [x] Password login on a passwordless account returns clear guidance and the login
      page auto-switches to the code mode
- [x] Fix: change-password no longer breaks under Sanctum transient tokens

**Verified:** 122 backend tests green (533 assertions); live run
(register without password → request login code → OTP login).

## 2026-07-30 — Signup refinements ✅ (user-requested)

- [x] **Fixed:** registration failed with "The timezone field must be a valid timezone"
      on Windows browsers (Asia/Calcutta is a backward-compat zone) — validation now
      uses `timezone:all_with_bc`
- [x] Email removed from the signup form — added later from Settings → Login identity;
      after admin approval a **6-digit OTP is emailed to the new address** and the email
      activates only when the code is entered (replaces the old verification link)
- [x] Signup is now two steps: account details → **verify mobile OTP inline** (the
      app-inbox message is rendered right on the page with resend/refresh) — the user
      proceeds into the app only after verification
- [x] **Username auto-suggestion**: typing the full name fills a unique handle derived
      from it (e.g. `himanshusachdeva`); collisions get a numeric suffix
      (`himanshusachdeva1`); manual edits get live availability checks
      (✓ available / ✗ taken) via `GET /auth/suggest-username`

**Verified:** 120 backend tests green (520 assertions); live checks for the suggestion
endpoint and Asia/Calcutta registration.

## 2026-07-30 — Identity overhaul + sidebar badges ✅ (user-requested)

**Mobile-first registration with app-to-app OTP**
- [x] Registration now requires ISD country code + mobile number and a username;
      email is optional (add later from Settings)
- [x] OTP is delivered inside the app (in-app notification), not over the SMS
      network; verify from the banner; admins can view/resend any user's active
      code from the Admin Panel (key icon)
- [x] Changing mobile: new number takes effect only after OTP verification

**Usernames & multi-mode login**
- [x] Custom usernames: 4–20 alphanumeric characters, no specials, unique
      (case-insensitive); users are searchable/connectable by username, mobile,
      or App ID (App ID remains the permanent internal identity)
- [x] Login accepts mobile (+ISD), username, or email in one field
      (legacy email-only clients still work)

**Admin-approved identity changes**
- [x] change_requests: mobile/email changes requestable anytime; username changes
      gated by an admin-configurable cooldown (default 30 days, `app_settings`)
- [x] Admin AND Subadmin can approve/reject (with a note shown to the user);
      approvals apply the change and force re-verification (OTP for mobile,
      email link for email); everything audit-logged
- [x] Settings page "Login identity" card: request changes + track request status
- [x] Admin Panel: Approvals queue, cooldown setting, per-user OTP tools

**Sidebar unattended badges**
- [x] `GET /badges`: unread messages, unseen missed calls, pending connection
      requests (+ unread notifications); nav shows counts on Messages / Calls /
      Connections and they clear when attended (conversation read, Calls page
      opened, request answered); 30s polling

**Verified:** 117 backend tests green (497 assertions, 9 new); live end-to-end
run (register → in-app OTP → verify → login by username and by mobile → badges).

## 2026-07-30 — Phase 8 complete ✅ — ALL SPEC PHASES DONE 🎉

**Cashfree payments (spec §34)**
- [x] Billing schema: payment_orders, payments, payment_webhooks, invoices,
      coupons, coupon_usages, refunds — all money as integer paise (zero float math)
- [x] `PaymentGatewayInterface` abstraction; `CashfreePaymentGateway` (sandbox/production
      by env, PG orders API, credentials backend-only); `FakePaymentGateway` bound in tests
- [x] Checkout: backend-only price calculation (base → coupon → GST), duplicate-click
      order reuse, 30-min order expiry, 503 until Cashfree is configured
- [x] Coupons: fixed/percent with caps, min order, plan/frequency restrictions,
      global + per-user usage limits, new-users-only, backend validation
- [x] Verification: gateway-authoritative status fetch, strict amount+currency match
      (mismatch → failed + audit log), row-locked idempotent activation —
      one payment, one subscription period, one invoice regardless of redirect/webhook races
- [x] Webhooks: HMAC-SHA256 signature verification (401 on invalid), dedupe by content
      hash, queued processing with retries, full webhook log for admins
- [x] Invoices: MYPA-INV-YYYY-##### numbering, buyer/seller snapshot, printable HTML
- [x] Lifecycle: cancel-at-period-end, daily expiry (→ Free plan + notification),
      renewal reminders at 15/7/3/1/0 days, stale order cleanup
- [x] Refunds: super-admin only, partial/full with over-refund guard, gateway execution,
      payment status rollup, audited
- [x] Admin billing: payments search, webhook log, coupons CRUD
- [x] Frontend: public /pricing (monthly/annual toggle with savings, recommended badge,
      limits/features from the API), checkout dialog with live quote + coupon + terms
      acceptance + official Cashfree JS SDK, /payment/status verification page with
      polling, billing history + printable invoices in Settings

**Verified:** 108 backend tests green (431 assertions, 13 new billing tests
covering quotes/tax, coupons, idempotent activation, amount tampering, webhook
signature+dedupe, invoices, cancellation lifecycle, renewal reminders, refunds);
frontend build clean.

**The full spec (§1–§34) is now implemented.** Remaining go-live steps are
operational: Cashfree merchant onboarding (sandbox → production) and server
deployment per DEPLOYMENT.md.

## 2026-07-30 — Phase 7 complete ✅

**Security hardening**
- [x] Forced password change: seeded Super Admin (public demo credentials) must set a
      new password — login returns `must_change_password`, in-app banner until changed
- [x] Admin audit trail: `audit_logs` table records suspend/activate, role changes,
      App ID regeneration, plan edits and manual plan assignments (actor + IP + details);
      `GET /admin/audit-logs` to review
- [x] Security headers on every response (nosniff, DENY framing, referrer policy,
      permissions policy, HSTS in production)
- [x] CORS locked to `FRONTEND_URL`/`APP_URL` (no wildcard)
- [x] `.env.example` regenerated with every variable and no secrets
- [x] Rate limiting verified by test (auth endpoints 429 after 10 attempts/min)

**Performance**
- [x] Route-level code splitting: initial JS chunk down from 571 KB to a small core;
      each page lazy-loads (1–9 KB per page chunk)

**PWA**
- [x] Web manifest + installable icon, theme color, description meta
- [x] Service worker (production only): cache-first hashed assets, network-first shell
      with offline fallback, API never cached

**Production readiness**
- [x] DEPLOYMENT.md: architecture, hardening checklist, Supervisor/cron configs,
      Nginx sketch with Reverb websocket proxy, post-deploy checks, backup guidance

**Verified:** 95 backend tests green (368 assertions); frontend build clean with split chunks.

## Next: Phase 8 (final)
- Cashfree payments (spec §34): normalized billing tables, checkout, webhooks with
  signature verification, server-side payment verification, invoices, coupons,
  taxes, refunds — activating paid subscriptions on the Phase 6 plan architecture

## 2026-07-30 — Phase 6 complete ✅

**Habits**
- [x] Daily/weekly/monthly habits with per-period targets, colors, archive
- [x] One-tap logging (increment / set / un-log), backfill by date
- [x] Streak engine (consecutive periods meeting target; in-progress today doesn't break it)
- [x] 7-day heat strip, totals; habit cards UI
- [x] Fixed: infinite streak loop when target was null (0 >= null is true in PHP)
      — model defaults + guard added

**Goals**
- [x] Goals with types (personal/family/work/health/financial), target dates, motivation
- [x] Milestones with toggle; progress derived from milestones; auto-complete
      when all milestones done; group goals visible to members
- [x] Goals UI with progress bars and inline milestone management

**Bills**
- [x] Bills with amount/category/account, due dates, group sharing
- [x] Recurring frequencies (weekly→yearly); mark-paid spawns the next occurrence
- [x] Daily `mypa:send-bill-reminders` (08:00) notifies within each bill's
      reminder window (database + email)
- [x] Bills UI with unpaid/paid filters and overdue highlighting

**Subscription architecture (pre-Cashfree)**
- [x] 6 seeded plans (Free/Personal/Family/Professional/Business/Enterprise) with
      JSON limits + features — prices/limits editable via admin API, never hardcoded in UI
- [x] `SubscriptionEntitlementService`: plan resolution (active/trial, expiry fallback
      to Free), limit checks, usage reporting, upgrade-plan hints
- [x] Backend enforcement: task limit, storage quota (replaces static config),
      group count, group member count — 422 with upgrade hint
- [x] `GET /plans` (public), `GET /subscription` (plan + usage)
- [x] Admin: list/edit plans (super admin), manually assign a user's plan with audit note
- [x] Settings page shows current plan + usage meters

**Verified:** 90 backend tests green (351 assertions); frontend build clean.

## Next: Phase 7
- Hardening: rate-limit review, security pass, performance, PWA manifest
- Docs completion & production readiness
- Then Phase 8: Cashfree payments (spec §34) on top of this plan architecture

## 2026-07-29 — Phase 5 complete ✅

**Voice task creation (English + Hindi)**
- [x] `VoiceCommandService`: rule-based intent parser — create task/reminder,
      mark-task-completed (fuzzy title match against open tasks), query tasks
      (important/pending/completed/overdue/today filters)
- [x] `VoiceDateParser`: today/tomorrow/day-after, next <weekday>, "in X minutes/hours/days",
      "at 3 PM", Hindi equivalents (आज/कल/परसों, अगले सोमवार, शाम 5 बजे, "3 दिन बाद"),
      Unicode-safe word boundaries for Devanagari
- [x] Extras parsed: repeat (daily/weekly/monthly/yearly + हर दिन/हफ्ते/महीने/साल),
      reminder offsets ("three days before the due date" / "तीन दिन पहले"),
      priority/important, category hints (family/work/bills/… → seeded categories)
- [x] `POST /voice/interpret` returns a reviewable structured command + spoken reply
      (never executes directly); `POST /voice/transcribe` stub (501) behind
      `SpeechToTextInterface` so Whisper/Google/Azure can be plugged in server-side
- [x] Floating voice assistant (all pages): browser SpeechRecognition (en-IN / hi-IN),
      language toggle, live transcript, typed fallback for unsupported browsers,
      editable review card (title/due/priority/important), confirm-to-save,
      speechSynthesis confirmations in the selected language
- [x] Query intent deep-links into the tasks page filters

**Verified:** 77 backend tests green (298 assertions) incl. all spec example commands
(EN + HI); live interpret round-trip tested; frontend build clean.

## Next: Phase 6
- Habits & goals modules with streaks and milestones
- Bill/expense reminders
- Subscription architecture (plans, entitlements) — pre-Cashfree

## 2026-07-29 — Phase 4 complete ✅

**Real-time infrastructure**
- [x] Laravel Reverb WebSocket server (standalone — no Redis needed in single-server mode)
- [x] Sanctum-authenticated channel auth at `POST /api/broadcasting/auth` (Bearer token)
- [x] Channels: `user.{uuid}` (calls, personal signals) and `conversation.{uuid}` (chat)
- [x] Frontend Echo client (laravel-echo + pusher-js) with lazy token-aware connection

**Chat**
- [x] Direct conversations (privacy-checked: who_can_message everyone/connections/nobody)
- [x] Group conversations auto-synced with group membership
- [x] Messages: text, reply-to, edit (own, text only), delete for me / delete for everyone
      (tombstone + attachment purge), reactions (toggle), unread counts, mark-read,
      mute/archive per member
- [x] Attachments: files, images, and recorded voice messages (MediaRecorder → webm,
      duration stored); authenticated streamed downloads; extension blocklist
- [x] Broadcast events: message.sent / message.updated (edit, delete, reaction)
- [x] Messages UI: two-pane layout, WebSocket refresh + polling fallback, hover actions,
      quick-emoji reactions, reply/edit banners, voice recorder, deep links
      (`?start=APP_ID`, `?group=uuid`) from Connections and Groups pages

**Calls (WebRTC)**
- [x] 1:1 audio/video calls in direct conversations, privacy-checked (who_can_call)
- [x] Call lifecycle: ringing → accept/decline → ongoing → ended/missed; duplicate-call guard
- [x] Signalling relayed over private user channels (offer/answer/ICE), broadcast immediately
- [x] `GET /calls/config` serves ICE servers (STUN/TURN via env; Google STUN dev fallback)
- [x] Call history with direction, duration, missed indicator
- [x] Global CallManager: incoming-call banner, floating call window with local/remote video,
      mute, camera toggle, duration timer, hang-up

**Verified:** 65 backend tests green (255 assertions); live two-user chat flow tested
end-to-end over the API; frontend build clean.

**Dev note:** real-time needs `php artisan reverb:start` (port 8080) alongside serve,
`queue:work`, and `schedule:work`. Without Reverb running, chat still works via polling.

## Next: Phase 5
- Voice task creation (Web Speech API), speech-to-text provider abstraction
- Text-to-speech reminders, Hindi + English commands

## 2026-07-29 — Phase 3 complete ✅

**Notes**
- [x] Text + checklist notes with color, pin, search
- [x] Version history (snapshot on every update, last 20 kept)
- [x] Password-protected notes (bcrypt, content withheld with HTTP 423 until unlocked
      via X-Note-Password; only the owner can change protection; locked notes can't be shared)
- [x] Sharing by App ID with view/edit permission; group notes visible to members
- [x] Masonry notes UI with lock/pin indicators and share dialog

**Files**
- [x] Nested folders with breadcrumb browsing
- [x] Multi-file upload with per-file size cap (env-configurable, default 25 MB),
      blocked-extension list (exe/bat/php/…), per-user storage quota (default 1 GB)
- [x] Authenticated streamed downloads (never a public path), rename, move
- [x] Trash: soft delete → restore / delete forever (removes stored file)
- [x] Sharing by App ID + "Shared with me" view; storage usage meter in UI

**Family & team groups**
- [x] Groups (family/team/business/other) with owner/admin/manager/member/viewer roles
- [x] Members added by App ID; role management; leave/remove rules
      (owner immutable, managers manage, anyone can leave)
- [x] Group tasks & events: creation restricted to members, visible to all members
      (extends Task/Event visibleTo scopes); group option in the task editor
- [x] Groups UI: cards, member management modal, group-task deep link

**Reports**
- [x] Summary: totals, completion rate, average completion time, by status/priority/category
- [x] Productivity: completed-per-day series (7/30/90 days)
- [x] CSV export (UTF-8 BOM for Excel)
- [x] Reports UI: stat tiles, single-hue magnitude bars, hover detail, range toggle

**Verified:** 53 backend tests green (195 assertions); frontend build clean.

## Next: Phase 4
- Real-time chat (conversations, messages, reactions, read receipts) via Laravel Reverb
- Voice messages
- Audio/video calls (WebRTC + signalling; STUN/TURN via env)
- ⚠️ Requires Redis ≥ 6.2 (local is 5.0.14 — upgrade before starting)

## 2026-07-29 — Phase 2 complete ✅

**Reminder engine**
- [x] `mypa:process-reminders` scheduled every minute → dispatches queued `SendTaskReminder` jobs
- [x] `TaskReminderNotification` (database + mail channels, respects user notification preferences)
- [x] Snooze (minutes or until) and acknowledge endpoints; repeat-until-acknowledged re-nags every 10 min
- [x] Reminders auto-cancel when the task is completed/cancelled/archived
- [x] Timezone fix: all submitted datetimes (tasks, reminders, events) are interpreted in the
      user's profile timezone and stored as UTC — reminders fire at the user's local wall time

**Recurring tasks**
- [x] `RecurringTaskService`: daily/weekly/monthly/yearly/custom + interval + until;
      clones checklists (reset), reminders (re-offset to new due date), assignees, tags
- [x] Completing a recurring task instantly spawns the next occurrence
- [x] `mypa:generate-recurring` hourly job also rolls forward missed occurrences

**Notifications**
- [x] Database notifications + API: list, unread count, mark read / mark all, delete
- [x] Daily pruning of read notifications older than 60 days
- [x] Frontend notification bell: unread badge (30s polling), panel with
      Complete / Snooze 30m / dismiss actions, deep-links to the task

**Subtasks**
- [x] `tasks.parent_id` (one level), hidden from top-level lists, `?parent=` listing,
      inline subtask add/toggle/delete in the task modal

**Events & calendar**
- [x] Events CRUD with types (event/meeting/appointment/birthday/anniversary/holiday),
      participants invited by App ID with accept/decline/tentative RSVP
- [x] Combined `/calendar/feed` (events + due tasks); calendar UI shows both, double-click
      to create, click event to edit, deep-link tasks
- [x] ICS export (`/calendar/export.ics`) with events + due tasks

**Verified:** 43 backend tests green (131 assertions); live pipeline test
(create reminder → scheduler → queue → notification API) passed; frontend build clean.

## Next: Phase 3
- Notes module (rich text, checklists, pinned, password-protected architecture)
- File management (folders, upload/download, sharing, storage limits)
- Family/team groups (roles, shared tasks, group calendar)
- Reports module

## 2026-07-29 — Phase 1 complete ✅

**Backend (Laravel 12, `backend/`)**
- [x] Environment verified: PHP 8.4.1, Composer 2.10, MariaDB 11.4 (XAMPP), Node 24
- [x] Laravel 12 + Sanctum API scaffolding; MariaDB `mypa` database created
- [x] Migrations: users (uuid, mobile, status, soft deletes), user_profiles, app_ids,
      roles, permissions, role_user, permission_role, user_settings, login_histories,
      connections, blocked_users, categories, category_users, tasks, task_assignments,
      task_checklists, task_reminders, task_comments, task_activity_logs, tags, taggables
- [x] Auth API: register (profile + settings + App ID + role in one transaction), login,
      logout, forgot/reset password (SPA links), email verification (signed URLs),
      change password (revokes other tokens), session list/revoke, login history
- [x] Unique App ID system: `MYPA-100001…` DB-guaranteed sequence, privacy-aware search,
      QR connect payload, admin regeneration with audit trail
- [x] Roles & permissions: super_admin / admin / subadmin / user + module permissions;
      `role:` middleware; suspended-user lockout middleware
- [x] Connections: request → accept/decline flow, duplicate protection, block/unblock
- [x] Categories: 20 system defaults + unlimited custom, subcategories, sharing by App ID
- [x] Tasks core: CRUD, 10 statuses, 6 priorities, checklist, reminders (offset-based),
      tags, assignment by App ID, comments, activity log, duplicate, pin/favourite/important,
      progress tracking, rich filters + sorting
- [x] Dashboard summary endpoint (timezone-aware counts, today/overdue/recent lists)
- [x] Admin panel API: stats, user CRUD + suspend/activate, role sync (super-admin-guarded),
      App ID regeneration, roles/permissions listing, login histories
- [x] Seeders: roles/permissions, 20 default categories, demo users (1 SA, 1 admin,
      2 subadmins, 5 users) + sample tasks/checklist/reminder/assignment
- [x] **Tests: 31 passing (88 assertions)** — auth, tasks, categories, admin, App ID/connections

**Frontend (React 19 + TS, `frontend/`)**
- [x] Vite + Tailwind 4 + Router + TanStack Query + Zustand + Axios setup with API proxy
- [x] Auth pages: login, register (auto-timezone), forgot/reset password
- [x] App shell: responsive sidebar (desktop + mobile drawer), dark mode with
      pre-paint theme application, user card with App ID
- [x] Dashboard: stat cards (today/overdue/important/pending/completed/completion %),
      today/overdue/recent task lists
- [x] Tasks page: filterable list (status/priority/category/search/important/overdue),
      create/edit modal (checklist, tags, reminders, assignees by App ID), complete-toggle,
      pin, duplicate, delete
- [x] Calendar page: month grid with task chips
- [x] Categories page: defaults + custom CRUD with color picker
- [x] Connections page: my App ID + QR payload, user search, request/accept/decline flow
- [x] Settings page: profile, privacy controls (7 settings), change password
- [x] Admin panel: stats row, user table with search, create user (role-guarded),
      suspend/activate, App ID regeneration (super admin)
- [x] **Production build passes (tsc + vite)**

**Verified end-to-end:** login → dashboard counts → App ID search against the live API. ✅

## Next: Phase 2
- Recurring task materialization (scheduler job)
- Reminder dispatch via queue + in-app notifications
- Notification center UI
- Calendar drag-and-drop + ICS export
- Kanban board view
