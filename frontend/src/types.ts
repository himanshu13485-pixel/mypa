export interface User {
  uuid: string
  name: string
  email?: string
  mobile?: string | null
  status?: string
  email_verified: boolean
  /** Server's own gate: the account has an email address that is unconfirmed. */
  email_verification_required?: boolean
  app_id?: string
  /** An account an application signs in as. It gets the service panel, not the app. */
  is_service_account?: boolean
  plan?: string | null
  salesperson?: { uuid: string; name: string } | null
  roles?: string[]
  profile?: {
    photo_path?: string | null
    /** A picked illustration ("f3", "m1"), used when there is no photo. */
    avatar?: string | null
    date_of_birth?: string | null
    gender?: string | null
    country?: string | null
    timezone?: string
    language?: string
    account_type?: string
    bio?: string | null
  }
  settings?: {
    theme: 'light' | 'dark' | 'system'
    compact_mode: boolean
    default_task_view: string
    dashboard_layout?: Record<string, unknown> | null
    notification_preferences?: Record<string, unknown> | null
    privacy: Record<string, 'everyone' | 'connections' | 'nobody'>
  }
  must_change_password?: boolean
  has_password?: boolean
  username?: string | null
  country_code?: string | null
  mobile_verified?: boolean
  created_at?: string
}

export interface ChangeRequestItem {
  uuid: string
  type: 'mobile' | 'email' | 'username'
  current_value?: string | null
  new_value: string
  country_code?: string | null
  status: 'pending' | 'approved' | 'rejected'
  review_note?: string | null
  reviewed_at?: string | null
  created_at: string
  user?: { uuid: string; name: string; username?: string; email?: string; mobile?: string }
}

export interface Badges {
  messages: number
  calls: number
  connections: number
  notifications: number
}

export interface ActiveMember {
  uuid: string
  name: string
  username?: string | null
  app_id?: string | null
  mobile?: string | null
  roles: string[]
  status: string
  ip_address?: string | null
  device?: string | null
  last_active_at: string
  is_online: boolean
}

export interface UserActivitySummary {
  user: { uuid: string; name: string; username?: string | null }
  last_login?: { at: string; ip?: string | null; device?: string | null } | null
  member_since: string
  plan: string
  tasks: { total: number; completed: number; created_this_week: number }
  notes: number
  files: { count: number; storage_bytes: number }
  calls?: { total: number; this_week: number; missed: number; minutes: number }
  groups_owned: number
  messages_sent: number
  logins_this_week: number
  reports_against: number
  open_reports_against: number
}

export interface ModerationReport {
  uuid: string
  reason: string
  details?: string | null
  status: string
  action_taken?: string | null
  created_at: string
  reporter?: { uuid: string; name: string; username?: string }
  reported_user?: { uuid: string; name: string; username?: string; status?: string }
  message?: { uuid: string; body?: string | null; type: string; deleted_at?: string | null } | null
  reviewer?: { name: string } | null
}

export interface AuditLogRow {
  subject_name?: string | null
  id: number
  action: string
  details?: Record<string, unknown> | null
  ip_address?: string | null
  created_at: string
  actor?: { uuid: string; name: string; email?: string } | null
}

export interface LoginHistoryRow {
  id: number
  ip_address?: string | null
  user_agent?: string | null
  device_name?: string | null
  logged_in_at: string
  logged_out_at?: string | null
  user?: { uuid: string; name: string; email?: string } | null
}

export const REPORT_REASONS = ['spam', 'harassment', 'inappropriate', 'impersonation', 'scam', 'other'] as const

export interface AdminPlan {
  id: number
  slug: string
  name: string
  description?: string | null
  monthly_price: string | number
  annual_price: string | number
  currency: string
  trial_days: number
  limits: Record<string, number | null> | null
  features: Record<string, boolean> | null
  is_active: boolean
  is_public: boolean
  is_recommended: boolean
  sort_order: number
  subscriptions_count?: number
}

export const ISD_CODES: { code: string; label: string }[] = [
  { code: '+91', label: '🇮🇳 India (+91)' },
  { code: '+1', label: '🇺🇸 USA/Canada (+1)' },
  { code: '+44', label: '🇬🇧 UK (+44)' },
  { code: '+971', label: '🇦🇪 UAE (+971)' },
  { code: '+966', label: '🇸🇦 Saudi Arabia (+966)' },
  { code: '+65', label: '🇸🇬 Singapore (+65)' },
  { code: '+61', label: '🇦🇺 Australia (+61)' },
  { code: '+49', label: '🇩🇪 Germany (+49)' },
  { code: '+33', label: '🇫🇷 France (+33)' },
  { code: '+81', label: '🇯🇵 Japan (+81)' },
  { code: '+86', label: '🇨🇳 China (+86)' },
  { code: '+880', label: '🇧🇩 Bangladesh (+880)' },
  { code: '+94', label: '🇱🇰 Sri Lanka (+94)' },
  { code: '+977', label: '🇳🇵 Nepal (+977)' },
]

export interface ChecklistItem {
  id: number
  title: string
  is_done: boolean
  sort_order: number
}

export interface Task {
  uuid: string
  title: string
  description?: string | null
  priority: string
  status: string
  start_at?: string | null
  due_at?: string | null
  estimated_minutes?: number | null
  progress: number
  location?: string | null
  contact_person?: string | null
  color?: string | null
  is_important: boolean
  is_confidential: boolean
  is_favourite: boolean
  is_pinned: boolean
  is_overdue: boolean
  repeat_config?: { frequency: string; interval?: number; until?: string } | null
  completed_at?: string | null
  owner?: { uuid: string; name: string; app_id?: string }
  group?: { uuid: string; name: string } | null
  category?: { uuid: string; name: string; icon?: string; color?: string } | null
  assignees?: { uuid: string; name: string; status: string }[]
  checklists?: ChecklistItem[]
  reminders?: { id: number; remind_at: string; offset_minutes?: number | null; channels?: string[] }[]
  tags?: string[]
  parent?: { uuid: string; title: string } | null
  subtasks?: Task[]
  created_at: string
  updated_at?: string
}

export interface Category {
  uuid: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  visibility: 'private' | 'shared'
  sort_order: number
  is_system: boolean
  is_own: boolean
  children?: Category[]
  tasks_count?: number
}

export interface Connection {
  uuid: string
  status: 'pending' | 'accepted' | 'declined'
  message?: string | null
  direction: 'sent' | 'received'
  user: {
    uuid: string
    name: string
    app_id?: string
    photo_path?: string | null
    avatar?: string | null
    /** Using the app now — already filtered by their privacy setting server-side. */
    is_online?: boolean
  } | null
  responded_at?: string | null
  created_at: string
}

export interface DashboardSummary {
  counts: {
    today: number
    upcoming: number
    overdue: number
    important: number
    completed: number
    pending: number
    assigned_to_me: number
    assigned_by_me: number
    total: number
    completion_rate: number
  }
  today_tasks: Task[]
  overdue_tasks: Task[]
  recent_tasks: Task[]
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number; per_page: number }
  links?: { next?: string | null; prev?: string | null }
}

export interface AdminStats {
  users: {
    total: number
    active: number
    suspended: number
    new_this_week: number
    online_last_hour: number
  }
  tasks: { total: number; completed: number; overdue: number; created_this_week: number }
  registrations_by_day: { date: string; count: number }[]
}

export interface AppNotification {
  id: string
  type: string
  data: {
    kind: string
    // Where clicking this should go. Written by SocialNotification for
    // every kind; absent on rows created before it existed.
    action_path?: string | null
    reminder_id?: number
    task_uuid?: string
    task_title?: string
    due_at?: string | null
    priority?: string
    message: string
    actions?: string[]
  }
  read_at: string | null
  created_at: string
}

export interface CalendarEvent {
  kind: 'event'
  uuid: string
  title: string
  description?: string | null
  type: string
  starts_at: string
  ends_at?: string | null
  all_day: boolean
  location?: string | null
  meeting_link?: string | null
  color?: string | null
  is_own: boolean
  participants: { uuid: string; name: string; status: string }[]
  created_at?: string
}

export interface CalendarFeedTask {
  kind: 'task'
  uuid: string
  title: string
  starts_at: string
  all_day: boolean
  color?: string | null
  status: string
  priority: string
}

export const EVENT_TYPES = ['event', 'meeting', 'appointment', 'birthday', 'anniversary', 'holiday'] as const

export interface Note {
  uuid: string
  title: string
  type: 'text' | 'checklist'
  color?: string | null
  is_pinned: boolean
  is_locked: boolean
  is_own: boolean
  group?: { uuid: string; name: string } | null
  body?: string | null
  checklist?: { text: string; done?: boolean }[] | null
  preview?: string | null
  updated_at: string
  created_at: string
}

export interface FileItem {
  uuid: string
  name: string
  mime_type?: string | null
  size: number
  is_own: boolean
  folder_uuid?: string | null
  created_at: string
  deleted_at?: string
  owner?: { uuid: string; name: string }
}

export interface FolderItem {
  uuid: string
  name: string
  files_count?: number
  created_at?: string
}

export interface BrowseResult {
  folder: { uuid: string; name: string } | null
  breadcrumb: { uuid: string; name: string }[]
  folders: FolderItem[]
  files: FileItem[]
  usage: { used_bytes: number; limit_bytes: number; percent: number }
}

export interface GroupItem {
  uuid: string
  name: string
  type: string
  description?: string | null
  icon?: string | null
  color?: string | null
  is_owner: boolean
  my_role: string | null
  members_count: number
  tasks_count: number
  created_at?: string
  members?: {
    uuid: string
    name: string
    app_id?: string
    photo_path?: string | null
    avatar?: string | null
    role: string
    joined_at?: string
  }[]
}

export interface ReportSummary {
  totals: {
    total: number
    completed: number
    pending: number
    overdue: number
    important: number
    completion_rate: number
    avg_completion_hours: number | null
  }
  by_status: Record<string, number>
  by_priority: Record<string, number>
  by_category: { name: string; color?: string | null; total: number; completed: number }[]
}

export const GROUP_TYPES = ['family', 'team', 'business', 'other'] as const

export interface HabitItem {
  uuid: string
  name: string
  description?: string | null
  frequency: 'daily' | 'weekly' | 'monthly'
  target_per_period: number
  icon?: string | null
  color?: string | null
  reminder_time?: string | null
  is_archived: boolean
  today_count: number
  done_today: boolean
  streak: number
  total_completions: number
  week: { date: string; count: number }[]
}

export interface GoalItem {
  uuid: string
  title: string
  description?: string | null
  type: string
  target_date?: string | null
  status: 'active' | 'completed' | 'abandoned'
  progress: number
  motivation?: string | null
  is_own: boolean
  group?: { uuid: string; name: string } | null
  milestones: { id: number; title: string; due_on?: string | null; is_done: boolean }[]
  completed_at?: string | null
  created_at?: string
}

export interface BillItem {
  uuid: string
  name: string
  category?: string | null
  amount?: string | number | null
  currency: string
  due_on: string
  due_time?: string | null
  remind_minutes_before?: number | null
  status: 'unpaid' | 'paid'
  is_overdue: boolean
  repeat_frequency?: string | null
  payment_account?: string | null
  remind_days_before: number
  notes?: string | null
  is_own: boolean
  group?: { uuid: string; name: string } | null
  paid_at?: string | null
}

export interface PlanInfo {
  slug: string
  name: string
  description?: string | null
  monthly_price: string | number
  annual_price: string | number
  currency: string
  trial_days: number
  limits: Record<string, number | null>
  features: Record<string, boolean>
  is_recommended: boolean
}

export interface MySubscription {
  plan: PlanInfo
  status: string
  started_at?: string | null
  trial_ends_at?: string | null
  ends_at?: string | null
  usage: Record<string, { used: number; limit: number | null }>
}

export interface BillingQuote {
  plan: string
  frequency: 'monthly' | 'annual'
  base: string
  discount: string
  tax: string
  tax_label: string
  tax_percent: number
  total: string
  currency: string
  coupon_applied?: string | null
}

export interface CheckoutSession {
  order_uuid: string
  order_number: string
  payment_session_id: string
  total: string
  currency: string
  gateway_mode: 'sandbox' | 'production'
  expires_at?: string
}

export interface PaymentRecord {
  uuid: string
  amount: string
  currency: string
  status: string
  method?: string | null
  plan: string
  frequency: string
  order_number: string
  invoice_uuid?: string | null
  refunded: string
  paid_at?: string | null
}

export interface InvoiceRecord {
  uuid: string
  invoice_number: string
  plan_name: string
  total: string
  currency: string
  issued_at: string
}

export const GOAL_TYPES = ['personal', 'family', 'work', 'health', 'financial'] as const
export const BILL_FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'] as const

export interface ConversationItem {
  uuid: string
  type: 'direct' | 'group'
  name: string
  group_uuid?: string | null
  other_user?: { uuid: string; username?: string | null; app_id?: string; photo_path?: string | null; avatar?: string | null } | null
  members_count: number
  unread_count: number
  is_muted: boolean
  is_archived: boolean
  last_message_at?: string | null
}

export interface ChatMessage {
  uuid: string
  type: string
  body: string | null
  is_deleted: boolean
  is_own: boolean
  /** Everyone else in the conversation has read this one. */
  read_by_others?: boolean
  sender?: { uuid: string; name: string } | null
  reply_to?: { uuid: string; body: string | null; sender_name?: string } | null
  attachments: { id: number; name: string; mime_type?: string | null; size: number; duration_seconds?: number | null }[]
  reactions: { emoji: string; count: number; mine: boolean }[]
  edited_at?: string | null
  created_at: string
}

export interface CallInfo {
  uuid: string
  conversation_uuid: string
  is_group?: boolean
  group_name?: string | null
  joined_peers?: { uuid: string; name: string }[]
  type: 'audio' | 'video'
  status: string
  is_outgoing: boolean
  is_missed?: boolean
  other_user?: { uuid: string; name: string } | null
  /** Live count and names of whoever is in the call right now. */
  joined_count?: number
  joined_names?: string[]
  /** Still ringing or ongoing — so it can be joined. */
  is_active?: boolean
  started_at?: string
  duration_seconds?: number | null
}

export interface CallSignalPayload {
  call_uuid: string
  conversation_uuid: string
  call_type: 'audio' | 'video'
  from_uuid: string
  from_name?: string | null
  signal: 'ring' | 'accept' | 'decline' | 'end' | 'offer' | 'answer' | 'ice' | 'peer-left' | 'share' | 'record' | 'media' | 'rec-request' | 'rec-allow' | 'rec-deny'
  payload: Record<string, unknown>
}

export const TASK_STATUSES = [
  'draft', 'not_started', 'planned', 'in_progress', 'waiting',
  'on_hold', 'completed', 'cancelled', 'overdue', 'archived',
] as const

export const TASK_PRIORITIES = ['low', 'normal', 'medium', 'high', 'urgent', 'critical'] as const

export interface InternalThread {
  user: { uuid: string; name: string; username: string | null; email: string | null }
  notes_count: number
  last_at: string
}

export interface InternalNoteRow {
  uuid: string
  body: string
  author: { uuid: string; name: string; is_me: boolean }
  created_at: string
}

export interface SharedByMeItem {
  kind: 'file' | 'folder'
  uuid: string
  name: string
  files_count?: number
  shared_with: { uuid: string; name: string; username: string | null; permission: string }[]
}

export interface ProjectItem {
  uuid: string
  name: string
  purpose: string
  base_currency: string
  notes?: string | null
  is_archived: boolean
  daily_report?: boolean
  report_format?: 'excel' | 'pdf'
  has_password?: boolean
  entries_count?: number | null
  is_owner: boolean
  permission: 'owner' | 'view' | 'edit' | null
  owner?: { uuid: string; name: string } | null
  shared_with: { uuid: string; name: string; username: string | null; permission: string }[]
  created_at: string
}

export interface ProjectEntryItem {
  uuid: string
  entry_date: string
  description: string
  direction: 'credit' | 'debit'
  amount: string
  currency: string
  mode: 'cash' | 'bank'
  bank_account?: string | null
  counterparty?: string | null
  reminder_at?: string | null
  created_by?: string | null
  updated_by?: string | null
}

export interface ProjectSummaryRow {
  currency: string
  credit: number
  debit: number
  net: number
  cash: number
  bank: number
  entries: number
  /** The same money split by whoever entered it; these add up to the row. */
  people: ProjectPersonRow[]
}

export interface ProjectPersonRow {
  uuid: string | null
  name: string
  currency: string
  credit: number
  debit: number
  net: number
  entries: number
}

export type MeetingRole = 'host' | 'cohost' | 'participant'

export interface MeetingParticipant {
  uuid: string
  name: string
  avatar?: string | null
  role: MeetingRole
  mic_on: boolean
  cam_on: boolean
  hand_raised: boolean
}

export interface MeetingItem {
  uuid: string
  code: string
  title?: string | null
  type: 'audio' | 'video'
  /**
   * 'not_started' is derived, not stored: a meeting with no time set was never
   * scheduled for anything, it was just made. The column defaults to
   * 'scheduled' the moment the row exists, which is not the same claim.
   */
  status: 'not_started' | 'scheduled' | 'active' | 'ended'
  scheduled_at?: string | null
  started_at?: string | null
  host: { uuid: string; name: string }
  is_host: boolean
  is_screen?: boolean
  requires_approval?: boolean
  is_locked?: boolean
  has_passcode?: boolean
  /**
   * Derived from the password, never stored separately: with one set, the
   * ordinary invite link admits people who have no account and type it.
   */
  allows_guests?: boolean
  /** Only sent to the host / co-hosts — they are the ones sharing it. */
  passcode?: string | null
  /**
   * Ceilings from the HOST's plan, applied to everyone in the room — a guest
   * has no plan to consult and a room that resized itself per joiner would be
   * impossible to explain. Null means no ceiling.
   */
  participant_limit?: number | null
  minutes_limit?: number | null
  expires_at?: string | null
  /**
   * How media reaches this room. Decided by the server and the same for
   * everyone in it — half a room on each would simply not see the other half.
   * 'mesh' is peer-to-peer and free to host; 'sfu' routes through LiveKit and
   * is what makes a room bigger than about eight people work.
   */
  transport?: 'mesh' | 'sfu'
  spotlight_uuid?: string | null
  my_role?: MeetingRole
  can_moderate?: boolean
  joined_count?: number | null
  ended_at?: string | null
  duration_seconds?: number | null
  participants?: string[]
  created_at: string
}

export interface MeetingHeartbeat {
  status: 'scheduled' | 'active' | 'ended'
  /** Why it ended, when it was not a person who ended it. */
  ended_reason?: 'time_limit'
  is_locked?: boolean
  spotlight_uuid?: string | null
  participants: MeetingParticipant[]
  waiting: { uuid: string; name: string }[]
  /** When the host's plan runs out. Null means the meeting has no limit. */
  expires_at?: string | null
  participant_limit?: number | null
  /**
   * Repeated on every beat so the room can tell if it has changed. It is not
   * meant to, but two people on different transports simply do not see each
   * other, and nothing else in the room would ever notice.
   */
  transport?: 'mesh' | 'sfu'
}

export type MeetingHostAction =
  | 'mute' | 'mute_all' | 'ask_unmute' | 'stop_video' | 'remove'
  | 'lock' | 'unlock' | 'promote' | 'demote' | 'transfer_host'
  | 'spotlight' | 'clear_spotlight'

export interface MeetingSignalPayload {
  meeting_code: string
  meeting_type: 'audio' | 'video'
  from_uuid: string
  from_name?: string | null
  signal: 'join' | 'leave' | 'end' | 'offer' | 'answer' | 'ice' | 'rename' | 'react' | 'knock' | 'admitted' | 'denied' | 'chat' | 'share' | 'record' | 'media' | 'rec-request' | 'rec-allow' | 'rec-deny' | 'host-mute' | 'host-ask-unmute' | 'host-stop-video' | 'removed' | 'lock' | 'role' | 'spotlight' | 'transport'
  payload: Record<string, unknown>
}

export interface AdminCallRecord {
  uuid: string
  type: 'audio' | 'video'
  status: string
  started_at?: string | null
  duration_seconds?: number | null
  caller?: string | null
  participants: string[]
}

export interface AdminChatRecord {
  uuid: string
  type: string
  name: string
  members: string[]
  messages_count: number
  last_message_at?: string | null
}

/** One token belonging to a service account, as an admin sees it. */
export interface BotToken {
  id: number
  name: string
  created_at: string
  last_used_at: string | null
  revealable: boolean
}

// --- Booking links ----------------------------------------------------------

export interface BookingHour {
  weekday: number
  start_time: string
  end_time: string
}

export interface BookingPageConfig {
  uuid: string
  slug: string
  url: string
  title: string | null
  description: string | null
  duration_minutes: number
  buffer_minutes: number
  min_notice_minutes: number
  max_days_ahead: number
  is_active: boolean
  timezone: string
  hours: BookingHour[]
}

/** What the host sees about somebody who booked them. */
export interface BookingRow {
  uuid: string
  name: string
  email: string
  note: string | null
  starts_at: string
  ends_at: string
  guest_timezone: string
  status: 'confirmed' | 'cancelled'
  meeting_code: string | null
}

/** What a stranger sees before booking: no more than the link already gave. */
export interface PublicBookingPage {
  slug: string
  host_name: string
  title: string
  description: string | null
  duration_minutes: number
  timezone: string
  max_days_ahead: number
}

export interface BookingDetail {
  uuid: string
  name: string
  email: string
  note: string | null
  starts_at: string
  ends_at: string
  guest_timezone: string
  status: 'confirmed' | 'cancelled'
  host_name: string
  slug: string
  meeting: { code: string; passcode: string; join_url: string } | null
}
