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

export const TASK_STATUSES = [
  'draft', 'not_started', 'planned', 'in_progress', 'waiting',
  'on_hold', 'completed', 'cancelled', 'overdue', 'archived',
] as const

export const TASK_PRIORITIES = ['low', 'normal', 'medium', 'high', 'urgent', 'critical'] as const
