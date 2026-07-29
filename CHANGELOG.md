# Changelog

All notable changes to My PA are documented here.

## [Unreleased]

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
