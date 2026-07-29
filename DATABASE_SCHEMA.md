# My PA — Database Schema

MariaDB 11.4 (MySQL-compatible). Conventions: `id` BIGINT UNSIGNED PK, `uuid` CHAR(36) unique
(externally exposed), FKs indexed, `created_at`/`updated_at` timestamps, `deleted_at` soft deletes
where noted.

## Phase 1 tables (implemented)

### users
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| uuid | char(36) unique | exposed in API |
| name | varchar | full name |
| email | varchar unique | |
| mobile | varchar nullable, indexed | |
| password | varchar | bcrypt |
| status | enum: active, suspended, pending | default active |
| email_verified_at | timestamp nullable | |
| last_login_at | timestamp nullable | |
| deleted_at | soft delete | |

### user_profiles (1:1 users)
photo_path, date_of_birth, gender, country, timezone (default Asia/Kolkata), language (default en),
account_type (personal|business), bio, referral_app_id.

### app_ids (1:1 users)
| Column | Notes |
|---|---|
| user_id | FK unique |
| app_id | varchar unique e.g. `MYPA-100001` |
| sequence | bigint unique — source of the number |
| is_active | admin can deactivate |
| regenerated_from | previous app_id when admin regenerates |

### roles / permissions / role_user / permission_role
Standard many-to-many RBAC. Seeded roles: `super_admin`, `admin`, `subadmin`, `user`.
Permissions grouped by module (`users.view`, `users.manage`, `tasks.assign`, `admin.access`, …).
`role_user` also carries nullable `assigned_by`.

### user_settings (1:1 users)
theme (light|dark|system), dashboard_layout JSON, notification_preferences JSON,
privacy JSON (who_can_find_me, who_can_message, who_can_call, profile_photo_visibility,
online_status_visibility, last_seen_visibility), default_task_view, compact_mode.

### login_histories
user_id, ip, user_agent, device_name, logged_in_at, logged_out_at.

### connections
requester_id, addressee_id, status (pending|accepted|declined|blocked), message, responded_at.
Unique (requester_id, addressee_id).

### blocked_users
user_id, blocked_user_id, reason. Unique pair.

### categories
| Column | Notes |
|---|---|
| user_id | FK nullable — null = system default |
| parent_id | self-FK for subcategories |
| name, icon, color, description | |
| is_shared, visibility | private / shared |
| sort_order | |
| soft deletes | |

### category_users (shared category members)
category_id, user_id, permission (view|edit|manage).

### tasks
| Column | Notes |
|---|---|
| uuid | exposed |
| user_id | creator FK |
| category_id | FK nullable |
| title, description | |
| priority | low, normal, medium, high, urgent, critical |
| status | draft, not_started, planned, in_progress, waiting, on_hold, completed, cancelled, overdue, archived |
| start_at, due_at | datetime nullable |
| estimated_minutes, actual_minutes | |
| progress | 0–100 |
| location, contact_person | |
| color | |
| is_important, is_confidential, is_favourite, is_pinned | booleans |
| completed_at, archived_at | |
| repeat_config | JSON (frequency, interval, until) |
| soft deletes | |

### task_assignments
task_id, user_id, assigned_by, status (assigned|accepted|in_progress|done|rejected), note.
Unique (task_id, user_id).

### task_checklists
task_id, title, is_done, sort_order.

### task_reminders
task_id, user_id, remind_at, offset_minutes (nullable — relative to due), channel JSON,
repeat_until_acknowledged, snoozed_until, acknowledged_at, sent_at.

### task_comments
task_id, user_id, body, parent_id.

### task_activity_logs
task_id, user_id, action, changes JSON.

### tags / taggables
Polymorphic tagging (tasks now; notes/files later).

### notifications (Laravel native) + notification_preferences

## Later phases (planned — from spec §24 & §34.18)

`devices`, `recurring_tasks`, `task_watchers`, `task_dependencies`, `task_attachments`,
`notes`, `note_versions`, `folders`, `files`, `file_shares`, `reported_users`,
`conversations`, `conversation_members`, `messages`, `message_attachments`, `message_reactions`,
`message_reads`, `calls`, `call_participants`, `groups`, `group_members`, `events`,
`event_participants`, `habits`, `habit_logs`, `goals`, `goal_milestones`, `audit_logs`,
`system_settings`, and the subscription set: `plans`, `plan_prices`, `plan_features`, `features`,
`plan_limits`, `subscriptions`, `subscription_items`, `subscription_changes`, `subscription_usage`,
`payment_orders`, `payments`, `payment_attempts`, `payment_gateway_logs`, `payment_webhooks`,
`invoices`, `invoice_items`, `coupons`, `coupon_plan`, `coupon_usages`, `refunds`,
`tax_settings`, `billing_addresses`, `payment_settings`.
