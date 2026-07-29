# Changelog

All notable changes to My PA are documented here.

## [Unreleased]

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
