# Changelog

All notable changes to My PA are documented here.

## [Unreleased]

### Changed — 2026-07-30 (Identity overhaul)
- Registration is mobile-first: ISD country code + mobile + username; email optional.
- Mobile verification via app-to-app OTP (in-app notification, no SMS network);
  admin can view/resend codes.
- Login by mobile, username, or email in a single identifier field.
- Identity changes (mobile/email/username) require Admin/Subadmin approval;
  username changes respect an admin-configurable cooldown; approvals trigger
  re-verification and are audit-logged.
- Sidebar shows unattended counts on Messages, Calls, and Connections that clear
  when attended.

### Added — 2026-07-30 (Phase 8 — final)
- Cashfree payment gateway behind a gateway abstraction: sandbox/production by
  env, checkout with backend-calculated GST + coupons, official JS SDK checkout.
- Idempotent server-side payment verification with strict amount/currency
  matching; signature-verified, deduped, queued webhooks.
- Invoices (numbered, printable HTML), payment history, refunds (admin,
  partial/full), coupons administration.
- Subscription lifecycle: cancel at period end, daily expiry to Free plan,
  renewal reminders (15/7/3/1/0 days), stale order cleanup.
- Public /pricing page, checkout dialog, payment status page, billing history
  in Settings.

### Added — 2026-07-30 (Phase 7)
- Forced password change for accounts with default credentials (Super Admin seeded
  with flag; banner + login flag until changed).
- Admin audit logging (suspend/activate, roles, App ID regeneration, plan changes)
  with review endpoint.
- Security headers middleware; CORS restricted to configured origins.
- Route-level code splitting (571 KB bundle → small core + per-page chunks).
- PWA: manifest, icon, and production service worker with offline shell.
- DEPLOYMENT.md production guide.

### Added — 2026-07-30 (Phase 6)
- Habits with streak tracking, per-period targets, 7-day heat strip, one-tap logging.
- Goals with milestones, derived progress, auto-completion, group goals.
- Bill reminders: recurring bills, mark-paid-spawns-next, daily reminder job.
- Subscription architecture: 6 seeded plans, entitlement service with backend
  limit enforcement (tasks/storage/groups/members), public plans API,
  usage dashboard in Settings, admin plan management + manual assignment.

### Fixed — 2026-07-30
- Habit streak computation could loop forever when a habit had no target set.
- Habit day-logs were duplicated instead of updated (date/time comparison).

### Added — 2026-07-29 (Phase 5)
- Voice assistant: floating mic on every page, browser speech recognition in
  English (en-IN) and Hindi (hi-IN) with typed fallback.
- Bilingual command interpreter: create tasks/reminders with natural dates,
  repeats, reminder offsets, priorities and category hints; complete tasks by
  name; query/filter tasks — always with an editable review step before saving.
- Text-to-speech confirmations; STT provider abstraction (Whisper/Google/Azure
  ready) with `POST /voice/transcribe` stub.

### Added — 2026-07-29 (Phase 4)
- Real-time layer: Laravel Reverb WebSockets with Sanctum-authenticated private channels.
- Chat: direct + group conversations, privacy checks, replies, edits, delete for
  me/everyone, reactions, unread counts, mute/archive, file/image/voice attachments.
- Voice messages recorded in-browser (MediaRecorder) with duration.
- WebRTC 1:1 audio/video calls: signalling over private channels, call lifecycle,
  history, ICE config endpoint, global call UI with incoming-call banner.

### Added — 2026-07-29 (Phase 3)
- Notes module: text/checklist notes, version history, password protection,
  sharing by App ID, group notes, masonry UI.
- File management: nested folders, quota-checked uploads with extension blocklist,
  authenticated streamed downloads, trash/restore, sharing, storage usage meter.
- Family & team groups with five roles, member management by App ID, and
  membership-scoped group tasks/events.
- Reports: summary, per-day productivity, CSV export, reports UI.

### Added — 2026-07-29 (Phase 2)
- Reminder engine: per-minute scheduler, queued dispatch, database + email notifications,
  snooze/acknowledge, repeat-until-acknowledged.
- Recurring tasks: automatic next-occurrence generation on completion, hourly roll-forward
  of missed occurrences, checklist/reminder/assignee/tag cloning.
- Notifications API + frontend notification bell with task actions.
- Subtasks (one level) with dedicated UI in the task editor.
- Calendar events: CRUD, participants by App ID with RSVP, combined task+event calendar
  feed, ICS export; calendar UI with event editor.
- Timezone correctness: submitted datetimes are parsed in the user's profile timezone.

### Added — 2026-07-29 (Phase 1)
- Project scaffolding: Laravel 12 backend (`backend/`), React 19 + TypeScript + Vite frontend (`frontend/`).
- Planning documents: PROJECT_PLAN.md, PROGRESS.md, DATABASE_SCHEMA.md, API_DOCUMENTATION.md.
- Phase 1 foundation: authentication (Sanctum), roles & permissions, unique My PA App ID system,
  user profiles & settings, login history, categories, core task management, admin panel foundation,
  demo seeders.
