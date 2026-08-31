# Changelog

All notable changes to My PA are documented here.

## [Unreleased]

### Changed — 2026-08-31 (Moving around the app stops flickering)

Netvork felt choppy next to something like Teams, and none of it was the
animations — the route transition was already a 180ms fade over a delayed
spinner, with the pages pre-fetched while the app sat idle. What made it feel
slow was that the app kept throwing away things it already had, and then
showing nothing while it fetched them again.

- **Skeletons instead of spinners**, everywhere — 46 of them. A spinner is
  honest but it is the wrong shape: it vanishes, the real layout takes its
  place, and the page jumps. A placeholder occupying the space the content
  will occupy makes the arrival one repaint with nothing moving. The dashboard
  and reports get skeletons drawn to their own stat grids, chat gets bubbles,
  tables get rows.
- **Switching chats no longer blanks the thread.** Opening a different
  conversation changes the query key, so the messages became `undefined` and
  the panel emptied until the request came back. Conversations are now
  pre-fetched the moment a pointer lands on one in the list — a few hundred
  milliseconds of head start, which is usually the whole request — and the
  first open of a thread shows message-shaped placeholders instead of nothing.
- **Sections remember where they were.** The app scrolls inside `<main>`,
  which is never unmounted, so its scroll position simply carried over: scroll
  halfway down a long task list, open Notes, and Notes opened halfway down.
  Now a new page starts at the top and going back returns you to where you
  were.
- **Cached answers live 30 minutes rather than 5.** The old window was shorter
  than the gap between visits to most sections, so coming back to a page ten
  minutes later was a cold load with a spinner even though nothing had
  changed. That reload was most of what "slow" actually was.
- **The whole sidebar is pre-fetched**, not the twelve routes that happened to
  be listed. The rest are 8–24KB each, so leaving Bills, Projects, Settings,
  Categories, Reports and Subscription out bought nothing and made them the
  sections that visibly waited. The two that genuinely cost something — the
  meeting room with the LiveKit client, and the admin panel — are instead
  fetched when a pointer approaches their link.
- **Avatar placeholders were rounded squares.** A default `rounded-md` and a
  caller's `rounded-full` are both rounding utilities and Tailwind's emission
  order decided the winner, the same trap `widthClass` already existed for.

### Added — 2026-08-31 (Everything notifies, on the phone and on the web)

The push transports were finished long before there was much to send through
them. Web push and FCM both worked, both read the same `toPush()` payload, and
between them they carried calls, shares and invitations — perhaps a dozen
moments in an app that has hundreds. Everything else changed the database and
told nobody: a colleague editing a task you are assigned to, an expense added
to a ledger you co-own, a missed call, a payment succeeding or failing, an
administrator suspending your account. The only way to learn any of it was to
open the app and compare what you saw against your memory of yesterday.

- **Shared expense ledgers speak.** Adding, editing or deleting an entry now
  reaches everyone else on the project — the owner included, who is not in
  `sharedWith` and is precisely the person who most wants to know. Someone
  could previously post a hundred thousand rupees of expenses to a ledger you
  co-own in complete silence. Tagged per entry, so a busy afternoon arrives as
  the several separate things it was.
- **Tasks report their own progress.** Status changes, edits, comments,
  checklist items being added, ticked and removed all reach the owner and the
  other assignees. Progress deliberately notifies only at 100: it arrives from
  a slider, and a single drag is a run of requests.
- **Missed calls leave word.** A ring carries a 45-second TTL — a call
  announced ten minutes late is worse than one never announced — so a phone
  that was off learned nothing. The missed call itself now notifies, which is
  the part worth keeping.
- **Group membership.** Being removed from a group, or having your role
  changed, is now said out loud rather than discovered by finding a button
  gone. Leaving of your own accord stays silent.
- **Account actions.** Suspension, reactivation, role changes and manual email
  verification notify the person they happened to. Suspension is sent after the
  sessions are cut, on purpose: push subscriptions belong to the device, not
  the session, so it still lands — the difference between an account that was
  suspended and an app that mysteriously logged you out.
- **Payments, plans and account change requests** now push. All three were
  bell-and-email only, so the answer to "did that go through?" arrived only if
  you went back and looked.
- **The calendar reminds you.** `events.starts_at` was written, indexed and
  read by nobody, so an appointment booked for Tuesday at nine passed in
  silence — the last dated thing in the app with no reminder. A new
  `mypa:send-event-reminders` sweep speaks up half an hour ahead (longer than a
  meeting's ten minutes, because an appointment usually has a journey in front
  of it), skips all-day entries so a birthday does not ring at 11:30pm, skips
  anyone who declined, and re-arms itself when an event is moved.

### Changed — 2026-08-31 (Alerts that can be told apart)

- **Notification categories.** Every kind now belongs to one of five
  categories — chat, reminders, money, activity, account — and the category
  decides the Android channel, the urgency and how long the push is worth
  delivering. Previously everything went out at one urgency to a channel id
  (`default`) that the app never created, which Android quietly replaces with
  the FCM library's own fallback: default importance, default tone, no
  heads-up, and one useless row in Android's notification settings governing
  all of it.
- **Five Android channels with their own sounds**, generated as short WAV
  assets that differ in contour rather than only pitch, because pitch alone
  does not survive a phone speaker in a pocket. Channels are the only handle
  Android gives a person for turning one sort of alert down without turning the
  app off, so with everything notifying they are the feature as much as the
  pushes are. The ids are versioned: a channel's importance and sound are fixed
  at creation and cannot be changed by an app update.
- **The service worker matches**, using the same vibration patterns per
  category, so an event feels the same whether it reached you through the
  browser or the installed app.
- **Push titles say something.** Every notification was titled the same, so the
  bold half of every lock-screen alert carried no information at all.
- **Chat-rate kinds never become email.** Messages, missed calls, task activity
  and ledger entries reach the bell and the device but not the inbox — a shared
  project having a busy afternoon would otherwise arrive as forty emails.
- **Every notification in the bell is now clickable.** The list understood
  exactly one destination, a task, from when a task reminder was very nearly
  the only thing in it. Every other row looked clickable, did nothing, and
  quietly marked itself read. The server had always sent `action_path`; the
  bell only had to read it.

### Fixed — 2026-08-04 (A websocket outage no longer takes the API with it)
- Call and meeting signals broadcast synchronously, so an unreachable Reverb
  threw mid-request and answered 500. Joining a meeting failed outright even
  though the participant row had been written — only the notification had.
  `App\Support\Realtime` now logs and swallows broadcast transport failures, so
  a realtime outage costs liveness rather than the whole feature. Programming
  errors still surface; only `BroadcastException` is caught.
- **Deploy scripts corrected.** `install-services.sh` wrote
  `--host=127.0.0.1 --port=8080` into the Reverb unit, which overrides
  `REVERB_SERVER_*` in the .env. `setup-reverb-tls.sh` then patched those flags
  to 8443 — so re-running the installer alone reverted Reverb to plain 8080
  while the .env, the frontend build and the firewall all still expected 8443,
  and every meeting join, call and chat broadcast 500'd. The unit now carries
  no flags and simply follows the .env, which makes both scripts idempotent.
- `deploy.sh`, `netvork-reverb.service` and `netvork-queue.service` had the
  wrong user, path and PHP version (`ea-php83` cannot satisfy composer.lock's
  `>= 8.4.1`). `deploy.sh` also now verifies paths and PHP up front instead of
  dying part-way and leaving the database behind the code.
- `websocket-proxy.conf` is marked as the unused alternative design it is, and
  README-DEPLOY.md documents the actual architecture.

### Added — 2026-08-04 (Chat presence, and failures that explain themselves)
- Read receipts actually move. `last_read_at` was recorded and never sent
  anywhere, and the tick was hardcoded to a double check — so every message
  you ever sent looked like it had been read. Opening a conversation now tells
  the senders, one tick means sent, two means read, and in a group a message
  counts as read only once everyone has seen it.
- Typing indicators, broadcast immediately and stored nowhere. Each signal
  keeps the name alive for a few seconds and then lapses, so a sender who
  closes their tab mid-word does not leave it stuck on. Sent at most once
  every two seconds rather than per keystroke, and suppressed by a block.
- Toast notifications replace `window.alert()` on the error paths — a native
  dialog steals focus, cannot be styled, and on a phone dropped a system modal
  over whatever you were doing, including a live video call.
- An ErrorBoundary at the root. A render error used to unmount the tree and
  leave a blank page, which to the person looking at it was indistinguishable
  from their data having vanished.
- A shared load-error state with a retry, on the busiest pages. Lists used to
  fall through to their empty state on failure, so "the server is down" and
  "you have nothing here" looked identical — and the reassuring one was wrong.

### Security — 2026-08-04 (Unverified accounts could use the app)
- Signing up with an address you do not own no longer gets you in. Registration
  has to return a token (confirming the address is itself an authenticated
  call), but that token opened every endpoint in the app: no route carried a
  verification check, `EnsureActiveUser` only blocked suspended accounts, and
  the SPA's route guard only looked for a token. Ignoring the OTP screen and
  following any deep link — a meeting invite, for instance — landed you in a
  fully working account, complete with an App ID other people could find and
  add to groups.
- The token issued at registration is now a limited one. A new
  `EnsureVerifiedEmail` middleware covers the authenticated route group; only
  reading your own account, resending or entering the code, and logging out
  opt out of it. Refusals carry `code: email_unverified` so the client can
  route to the verification screen instead of showing a bare error.
- Unverified sessions land on a new `/verify-email` screen. `RequireAuth` gates
  on a server-provided `email_verification_required` flag, so the client and
  the API apply the same rule.
- Seeded, admin-created and already-verified accounts are unaffected — both
  paths set `email_verified_at` on creation.

### Fixed — 2026-08-04 (Privacy, blocks and storage)
- Logging out now closes the WebSocket. `disconnectEcho()` existed but was
  never called, so the socket stayed subscribed to the previous user's
  private channel — on a shared browser the next person to sign in inherited
  their call and meeting signals.
- Blocking someone now stops them messaging and calling you. Blocks were only
  checked when a conversation was first opened, so once one existed the block
  did nothing. Group chats are unaffected: joining a group is consent. The
  blocked party is not told a block is the reason; the blocker is.
- "Who can call me: connections" is enforced. Only `nobody` had any effect
  before, so the setting worked as an on/off switch. Applied to inviting
  someone into a live call as well, which was the same hole via another door.
- "Who can see my last seen" now controls last seen. It was answered with the
  online-status setting, so the toggle did nothing. Both settings also honour
  `connections`, which was previously ignored.
- Chat attachments and meeting chat files count against the storage quota.
  Neither was checked on upload nor included in the usage figure, so chat was
  an unmetered way to fill the disk and the Files page under-reported.

### Fixed — 2026-08-04 (Adding people to Family & Teams)
- The member typeahead searched only your accepted connections, so anyone
  without connections got an empty dropdown and no explanation — the field
  looked broken even though typing an exact username always worked. It now
  searches everyone you are allowed to reach (name, username, email or App
  ID), listing your connections first and labelling the rest "not connected".
  Discovery still honours `who_can_find_me`, blocks in either direction and
  account status, and a stranger's email address is never returned.
- The suggestion list is rendered through a portal at fixed coordinates and
  flips above the field when the keyboard leaves no room below. It was
  clipped to nothing inside the scrolling modal sheet on phones.
- An empty result now says so, instead of silently showing nothing.

### Changed — 2026-08-04 (Phone-friendly pass)
- Form fields are 16px on phones. Below that iOS zooms the whole page on
  focus and never zooms back, which was the single worst thing about using
  the site on an iPhone.
- App shell uses `h-dvh` instead of `h-screen`, so the layout no longer
  mis-measures itself when a mobile browser's URL bar shows or hides.
  Messages dropped its `calc(100vh - 8rem)` for a plain flex fill.
- Bottom tab bar on phones (Home / Tasks / Chats / Meet / More) with unread
  badges, so the common screens are one tap instead of two via a hamburger.
  Hidden inside a live meeting or screen share, which want the whole screen.
- Safe-area insets honoured for the notch, rounded corners and home
  indicator (`viewport-fit=cover` was already set but nothing respected it).
- Every icon-only button gets a 44px touch area on phones without changing
  how it looks, and the shared Button / Input / Select hit a 44px floor.
  Header and drawer controls were 20–32px before.
- Modals become bottom sheets on phones, scrolling internally instead of
  pushing the page around.
- Calendar shows an agenda list on phones; the 7-column month grid needed
  640px and turned into a sideways pan through 21px chips.
- Meeting controls: the essentials stay on the bar, the rest move into an
  overflow sheet — eighteen buttons wrapped into four rows at 375px and
  covered the video. Screen share is hidden on mobile browsers, which do
  not implement getDisplayMedia.

### Added — 2026-08-04 (Meetings: Zoom-style controls)
- Presence heartbeat + `mypa:reap-meetings` scheduler: a closed tab, crashed
  browser or dropped connection now empties the room and ends the meeting, the
  same as a clean leave. Leaving on tab close is sent with `keepalive`.
- Host controls: mute one / mute all / ask to unmute, stop someone's video,
  remove a participant (who cannot walk back in), lock the meeting, spotlight,
  co-hosts, and handing the host seat over. A host who leaves mid-meeting hands
  the controls to a co-host, or to whoever has been there longest.
- Optional meeting passcode on top of the join code; visible only to moderators.
- Pre-join lobby: camera preview, live mic meter, device pickers, and
  join-muted / camera-off before anyone can see you.
- Camera reverse (front/back on phones, next webcam on desktop) plus live
  camera / microphone / speaker switching mid-meeting.
- Picture-in-picture: the meeting floats over other apps (Document PiP where
  available, single-video PiP elsewhere) and a screen wake lock.
- Gallery / speaker / sidebar layouts, pin-for-me, hide-my-own-tile, per-peer
  connection quality from `getStats()`, and automatic ICE restart when a peer
  connection drops.

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
