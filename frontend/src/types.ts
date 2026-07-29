export interface User {
  uuid: string
  name: string
  email?: string
  mobile?: string | null
  status?: string
  email_verified: boolean
  app_id?: string
  roles?: string[]
  profile?: {
    photo_path?: string | null
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
  created_at?: string
}

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
  user: { uuid: string; name: string; app_id?: string; photo_path?: string | null } | null
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

export const GOAL_TYPES = ['personal', 'family', 'work', 'health', 'financial'] as const
export const BILL_FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'] as const

export interface ConversationItem {
  uuid: string
  type: 'direct' | 'group'
  name: string
  group_uuid?: string | null
  other_user?: { uuid: string; app_id?: string; photo_path?: string | null } | null
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
  type: 'audio' | 'video'
  status: string
  is_outgoing: boolean
  is_missed?: boolean
  other_user?: { uuid: string; name: string } | null
  started_at?: string
  duration_seconds?: number | null
}

export interface CallSignalPayload {
  call_uuid: string
  conversation_uuid: string
  call_type: 'audio' | 'video'
  from_uuid: string
  from_name?: string | null
  signal: 'ring' | 'accept' | 'decline' | 'end' | 'offer' | 'answer' | 'ice'
  payload: Record<string, unknown>
}

export const TASK_STATUSES = [
  'draft', 'not_started', 'planned', 'in_progress', 'waiting',
  'on_hold', 'completed', 'cancelled', 'overdue', 'archived',
] as const

export const TASK_PRIORITIES = ['low', 'normal', 'medium', 'high', 'urgent', 'critical'] as const
