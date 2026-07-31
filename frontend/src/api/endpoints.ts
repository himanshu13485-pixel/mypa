import { api } from './client'
import type {
  AdminStats, AppNotification, BillItem, BillingQuote, BrowseResult,
  CalendarEvent, CalendarFeedTask, CallInfo, Category, ChatMessage,
  CheckoutSession, Connection, ConversationItem, DashboardSummary, FileItem,
  GoalItem, GroupItem, HabitItem, InvoiceRecord, MySubscription, Note,
  Paginated, PaymentRecord, PlanInfo, ReportSummary, Task, User,
} from '../types'

// --- Auth -------------------------------------------------------------------

export const auth = {
  register: (payload: Record<string, unknown>) =>
    api.post<{ data: User; token: string; mobile_verification_pending?: boolean }>('/auth/register', payload).then((r) => r.data),
  login: (payload: { identifier: string; password: string; device_name?: string }) =>
    api.post<{ data: User; token: string; must_change_password?: boolean }>('/auth/login', payload).then((r) => r.data),
  verifyMobile: (code: string) => api.post('/auth/mobile/verify', { code }),
  resendMobileOtp: () => api.post('/auth/mobile/resend-otp'),
  verifyEmailOtp: (code: string) => api.post('/auth/email/verify-otp', { code }),
  resendEmailOtp: () => api.post('/auth/email/resend-otp'),
  requestLoginOtp: (identifier: string) => api.post('/auth/otp/request', { identifier }),
  loginWithOtp: (payload: { identifier: string; code: string; device_name?: string }) =>
    api.post<{ data: User; token: string; must_change_password?: boolean }>('/auth/otp/login', payload).then((r) => r.data),
  logout: () => api.post('/auth/logout'),
  forgotPassword: (email: string) => api.post('/auth/forgot-password', { email }),
  resetPassword: (payload: Record<string, string>) => api.post('/auth/reset-password', payload),
  changePassword: (payload: Record<string, string>) => api.post('/auth/change-password', payload),
  me: () => api.get<{ data: User }>('/me').then((r) => r.data.data),
}

// --- Profile ----------------------------------------------------------------

export const profile = {
  update: (payload: Record<string, unknown>) =>
    api.put<{ data: User }>('/me/profile', payload).then((r) => r.data.data),
  updateSettings: (payload: Record<string, unknown>) =>
    api.put<{ data: User }>('/me/settings', payload).then((r) => r.data.data),
  myQr: () => api.get<{ data: { app_id: string; payload: string } }>('/me/app-id/qr').then((r) => r.data.data),
}

// --- Dashboard --------------------------------------------------------------

export const dashboard = {
  summary: () => api.get<{ data: DashboardSummary }>('/dashboard/summary').then((r) => r.data.data),
}

// --- Tasks ------------------------------------------------------------------

export const tasks = {
  list: (params: Record<string, unknown> = {}) =>
    api.get<Paginated<Task>>('/tasks', { params }).then((r) => r.data),
  get: (uuid: string) => api.get<{ data: Task }>(`/tasks/${uuid}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: Task }>('/tasks', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: Task }>(`/tasks/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/tasks/${uuid}`),
  setStatus: (uuid: string, status: string) =>
    api.post<{ data: Task }>(`/tasks/${uuid}/status`, { status }).then((r) => r.data.data),
  setProgress: (uuid: string, progress: number) =>
    api.post<{ data: Task }>(`/tasks/${uuid}/progress`, { progress }).then((r) => r.data.data),
  duplicate: (uuid: string) => api.post<{ data: Task }>(`/tasks/${uuid}/duplicate`).then((r) => r.data.data),
  toggle: (uuid: string, flag: 'pin' | 'favourite' | 'important') =>
    api.post<{ data: Task }>(`/tasks/${uuid}/toggle/${flag}`).then((r) => r.data.data),
  assign: (uuid: string, app_ids: string[], note?: string) =>
    api.post(`/tasks/${uuid}/assign`, { app_ids, note }),
  addChecklistItem: (uuid: string, title: string) => api.post(`/tasks/${uuid}/checklist`, { title }),
  updateChecklistItem: (uuid: string, itemId: number, payload: Record<string, unknown>) =>
    api.put(`/tasks/${uuid}/checklist/${itemId}`, payload),
  deleteChecklistItem: (uuid: string, itemId: number) => api.delete(`/tasks/${uuid}/checklist/${itemId}`),
  addComment: (uuid: string, body: string, parent_id?: number) =>
    api.post(`/tasks/${uuid}/comments`, { body, parent_id }),
}

// --- Categories -------------------------------------------------------------

export const categories = {
  list: (tree = false) =>
    api.get<{ data: Category[] }>('/categories', { params: tree ? { tree: 1 } : {} }).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: Category }>('/categories', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: Category }>(`/categories/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/categories/${uuid}`),
  share: (uuid: string, app_id: string, permission: string) =>
    api.post(`/categories/${uuid}/share`, { app_id, permission }),
}

// --- Connections & App ID ---------------------------------------------------

export const connections = {
  search: (q: string) =>
    api.get<{ data: { uuid: string; name: string; app_id: string; photo_path?: string | null; is_connected: boolean } }>(
      '/app-id/search', { params: { q } },
    ).then((r) => r.data.data),
  list: (status?: string) =>
    api.get<Paginated<Connection>>('/connections', { params: status ? { status } : {} }).then((r) => r.data),
  send: (app_id: string, message?: string) => api.post('/connections', { app_id, message }),
  respond: (uuid: string, action: 'accept' | 'decline') => api.put(`/connections/${uuid}`, { action }),
  remove: (uuid: string) => api.delete(`/connections/${uuid}`),
  blocks: () => api.get<{ data: { uuid: string; name: string; app_id?: string }[] }>('/blocks').then((r) => r.data.data),
  block: (app_id: string, reason?: string) => api.post('/blocks', { app_id, reason }),
  unblock: (app_id: string) => api.delete(`/blocks/${app_id}`),
}

// --- Identity change requests + badges --------------------------------------

export const identity = {
  myRequests: () =>
    api.get<{ data: import('../types').ChangeRequestItem[] }>('/me/change-requests').then((r) => r.data.data),
  request: (payload: { type: string; new_value: string; country_code?: string }) =>
    api.post('/me/change-requests', payload).then((r) => r.data),
  pending: () =>
    api.get<Paginated<import('../types').ChangeRequestItem>>('/admin/change-requests').then((r) => r.data),
  review: (uuid: string, action: 'approve' | 'reject', note?: string) =>
    api.post(`/admin/change-requests/${uuid}`, { action, note }).then((r) => r.data),
}

export const badges = {
  get: () => api.get<{ data: import('../types').Badges }>('/badges').then((r) => r.data.data),
  markCallsSeen: () => api.post('/calls/seen'),
  readKinds: (kinds: string[]) => api.post('/notifications/read-kinds', { kinds }),
}

// --- Reports (moderation intake) --------------------------------------------

export const reportsApi = {
  fileUser: (identifier: string, reason: string, details?: string) =>
    api.post('/reports', { identifier, reason, details }).then((r) => r.data),
  fileMessage: (message_uuid: string, reason: string, details?: string) =>
    api.post('/reports', { message_uuid, reason, details }).then((r) => r.data),
}

// --- Admin billing / plans ---------------------------------------------------

export const adminBilling = {
  plans: () => api.get<{ data: import('../types').AdminPlan[] }>('/admin/plans').then((r) => r.data.data),
  updatePlan: (slug: string, payload: Record<string, unknown>) =>
    api.put(`/admin/plans/${slug}`, payload).then((r) => r.data),
  assignPlan: (userUuid: string, plan_slug: string, months?: number | null, note?: string) =>
    api.post(`/admin/users/${userUuid}/plan`, { plan_slug, months: months ?? null, note }).then((r) => r.data),
}

// --- Admin ops ---------------------------------------------------------------

export const adminOps = {
  activeMembers: () =>
    api.get<{ data: import('../types').ActiveMember[] }>('/admin/active-members').then((r) => r.data.data),
  userSummary: (uuid: string) =>
    api.get<{ data: import('../types').UserActivitySummary }>(`/admin/users/${uuid}/summary`).then((r) => r.data.data),
  callRecords: (uuid: string, page = 1) =>
    api.get<Paginated<import('../types').AdminCallRecord>>(`/admin/users/${uuid}/call-records`, { params: { page } }).then((r) => r.data),
  messageRecords: (uuid: string, page = 1) =>
    api.get<Paginated<import('../types').AdminChatRecord>>(`/admin/users/${uuid}/message-records`, { params: { page } }).then((r) => r.data),
  modulePermissions: (uuid: string) =>
    api.get<{ data: Record<string, { can_view: boolean; can_edit: boolean; can_delete: boolean }> }>(
      `/admin/users/${uuid}/module-permissions`,
    ).then((r) => r.data.data),
  saveModulePermissions: (uuid: string, permissions: Record<string, { can_view: boolean; can_edit: boolean; can_delete: boolean }>) =>
    api.put(`/admin/users/${uuid}/module-permissions`, { permissions }),
  reports: (status = 'open', page = 1) =>
    api.get<Paginated<import('../types').ModerationReport>>('/admin/reports', { params: { status, page } }).then((r) => r.data),
  actOnReport: (uuid: string, action: string, note?: string) =>
    api.post(`/admin/reports/${uuid}/act`, { action, note }).then((r) => r.data),
  auditLogs: (action?: string, page = 1) =>
    api.get<Paginated<import('../types').AuditLogRow>>('/admin/audit-logs', { params: { page, ...(action ? { action } : {}) } }).then((r) => r.data),
  loginHistories: (q?: string, page = 1) =>
    api.get<Paginated<import('../types').LoginHistoryRow>>('/admin/login-histories', { params: { page, ...(q ? { q } : {}) } }).then((r) => r.data),
}

// --- Salesperson workspace ---------------------------------------------------

export const adminSales = {
  myUsers: (salespersonUuid?: string) =>
    api.get<Paginated<import('../types').User>>('/admin/sales/my-users', {
      params: salespersonUuid ? { salesperson: salespersonUuid } : {},
    }).then((r) => r.data),
  summary: (uuid: string) =>
    api.get<{ data: import('../types').UserActivitySummary }>(`/admin/sales/users/${uuid}/summary`).then((r) => r.data.data),
}

// --- Internal Work (staff-only notes about users) ---------------------------

export const adminInternal = {
  threads: () =>
    api.get<{ data: import('../types').InternalThread[] }>('/admin/internal/threads').then((r) => r.data.data),
  lookup: (identifier: string) =>
    api.post<{ data: { uuid: string; name: string; username: string | null } }>('/admin/internal/lookup', { identifier }).then((r) => r.data.data),
  notes: (uuid: string) =>
    api.get<{ data: { user: { uuid: string; name: string; username: string | null }; notes: import('../types').InternalNoteRow[] } }>(
      `/admin/internal/users/${uuid}/notes`,
    ).then((r) => r.data.data),
  addNote: (uuid: string, body: string) =>
    api.post(`/admin/internal/users/${uuid}/notes`, { body }),
  deleteNote: (noteUuid: string) => api.delete(`/admin/internal/notes/${noteUuid}`),
}

// --- Admin: user care --------------------------------------------------------

export const adminCare = {
  verifyEmail: (uuid: string) => api.post(`/admin/users/${uuid}/verify-email`).then((r) => r.data),
  salespeople: () =>
    api.get<{ data: { uuid: string; name: string }[] }>('/admin/salespeople').then((r) => r.data.data),
  assignSalesperson: (uuid: string, salespersonUuid: string | null) =>
    api.post<{ message: string }>(`/admin/users/${uuid}/salesperson`, { salesperson_uuid: salespersonUuid }).then((r) => r.data),
}

// --- Notifications ----------------------------------------------------------

export const notifications = {
  list: (unread = false) =>
    api.get<Paginated<AppNotification>>('/notifications', { params: unread ? { unread: 1 } : {} }).then((r) => r.data),
  unreadCount: () =>
    api.get<{ data: { count: number } }>('/notifications/unread-count').then((r) => r.data.data.count),
  markRead: (id: string) => api.post(`/notifications/${id}/read`),
  markAllRead: () => api.post('/notifications/read-all'),
  remove: (id: string) => api.delete(`/notifications/${id}`),
}

// --- Reminders --------------------------------------------------------------

export const reminders = {
  upcoming: () => api.get('/reminders/upcoming').then((r) => r.data),
  snooze: (id: number, minutes: number) => api.post(`/reminders/${id}/snooze`, { minutes }),
  acknowledge: (id: number) => api.post(`/reminders/${id}/acknowledge`),
}

// --- Events & calendar ------------------------------------------------------

export const events = {
  feed: (date_from: string, date_to: string) =>
    api.get<{ data: { events: CalendarEvent[]; tasks: CalendarFeedTask[] } }>(
      '/calendar/feed', { params: { date_from, date_to } },
    ).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: CalendarEvent }>('/events', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: CalendarEvent }>(`/events/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/events/${uuid}`),
  respond: (uuid: string, status: 'accepted' | 'declined' | 'tentative') =>
    api.post(`/events/${uuid}/respond`, { status }),
  icsUrl: '/api/v1/calendar/export.ics',
}

// --- Notes ------------------------------------------------------------------

export const notes = {
  list: (params: Record<string, unknown> = {}) =>
    api.get<Paginated<Note>>('/notes', { params }).then((r) => r.data),
  get: (uuid: string, password?: string) =>
    api.get<{ data: Note }>(`/notes/${uuid}`, {
      headers: password ? { 'X-Note-Password': password } : {},
    }).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: Note }>('/notes', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>, password?: string) =>
    api.put<{ data: Note }>(`/notes/${uuid}`, payload, {
      headers: password ? { 'X-Note-Password': password } : {},
    }).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/notes/${uuid}`),
  share: (uuid: string, app_id: string, permission: 'view' | 'edit') =>
    api.post(`/notes/${uuid}/share`, { app_id, permission }),
}

// --- Files ------------------------------------------------------------------

export const files = {
  browse: (folder?: string) =>
    api.get<{ data: BrowseResult }>('/files/browse', { params: folder ? { folder } : {} }).then((r) => r.data.data),
  upload: (fileList: File[], folder_uuid?: string) => {
    const form = new FormData()
    fileList.forEach((f) => form.append('files[]', f))
    if (folder_uuid) form.append('folder_uuid', folder_uuid)
    return api.post('/files/upload', form).then((r) => r.data)
  },
  downloadUrl: (uuid: string) => `/api/v1/files/${uuid}/download`,
  rename: (uuid: string, name: string) => api.put(`/files/${uuid}`, { name }),
  remove: (uuid: string) => api.delete(`/files/${uuid}`),
  trash: () => api.get<Paginated<FileItem>>('/files/trash').then((r) => r.data),
  restore: (uuid: string) => api.post(`/files/${uuid}/restore`),
  forceDelete: (uuid: string) => api.delete(`/files/${uuid}/force`),
  share: (uuid: string, app_id: string) => api.post(`/files/${uuid}/share`, { app_id }),
  sharedWithMe: () => api.get<Paginated<FileItem>>('/files/shared-with-me').then((r) => r.data),
  sharedByMe: () =>
    api.get<{ data: import('../types').SharedByMeItem[] }>('/files/shared-by-me').then((r) => r.data.data),
  unshare: (uuid: string, userUuid: string) =>
    api.post<{ message: string }>(`/files/${uuid}/unshare`, { user_uuid: userUuid }).then((r) => r.data),
  unshareFolder: (uuid: string, userUuid: string) =>
    api.post<{ message: string }>(`/folders/${uuid}/unshare`, { user_uuid: userUuid }).then((r) => r.data),
  createFolder: (name: string, parent_uuid?: string) =>
    api.post('/folders', { name, parent_uuid }),
  shareFolder: (uuid: string, app_id: string) => api.post(`/folders/${uuid}/share`, { app_id }),
  sharedFolderFiles: (uuid: string) =>
    api.get<{ data: { folder: { uuid: string; name: string }; files: FileItem[] } }>(`/folders/${uuid}/shared-files`).then((r) => r.data.data),
  renameFolder: (uuid: string, name: string) => api.put(`/folders/${uuid}`, { name }),
  removeFolder: (uuid: string) => api.delete(`/folders/${uuid}`),
}

// --- Groups -----------------------------------------------------------------

export const groups = {
  list: () => api.get<{ data: GroupItem[] }>('/groups').then((r) => r.data.data),
  get: (uuid: string) => api.get<{ data: GroupItem }>(`/groups/${uuid}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: GroupItem }>('/groups', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: GroupItem }>(`/groups/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/groups/${uuid}`),
  addMember: (uuid: string, app_id: string, role?: string) =>
    api.post(`/groups/${uuid}/members`, { app_id, role }),
  updateMember: (uuid: string, userUuid: string, role: string) =>
    api.put(`/groups/${uuid}/members/${userUuid}`, { role }),
  removeMember: (uuid: string, userUuid: string) =>
    api.delete(`/groups/${uuid}/members/${userUuid}`),
}

// --- Chat -------------------------------------------------------------------

export const chat = {
  conversations: () => api.get<Paginated<ConversationItem>>('/conversations').then((r) => r.data),
  start: (app_id: string) =>
    api.post<{ data: ConversationItem }>('/conversations', { app_id }).then((r) => r.data.data),
  groupConversation: (groupUuid: string) =>
    api.get<{ data: ConversationItem }>(`/groups/${groupUuid}/conversation`).then((r) => r.data.data),
  messages: (uuid: string, params: Record<string, unknown> = {}) =>
    api.get<{ data: ChatMessage[] }>(`/conversations/${uuid}/messages`, { params }).then((r) => r.data.data),
  send: (uuid: string, payload: FormData | Record<string, unknown>) =>
    api.post<{ data: ChatMessage }>(`/conversations/${uuid}/messages`, payload).then((r) => r.data.data),
  edit: (uuid: string, messageUuid: string, body: string) =>
    api.put<{ data: ChatMessage }>(`/conversations/${uuid}/messages/${messageUuid}`, { body }).then((r) => r.data.data),
  remove: (uuid: string, messageUuid: string, scope: 'me' | 'everyone') =>
    api.delete(`/conversations/${uuid}/messages/${messageUuid}?for=${scope}`),
  react: (uuid: string, messageUuid: string, emoji: string) =>
    api.post<{ data: ChatMessage }>(`/conversations/${uuid}/messages/${messageUuid}/react`, { emoji }).then((r) => r.data.data),
  markRead: (uuid: string) => api.post(`/conversations/${uuid}/read`),
  toggleMute: (uuid: string) => api.post(`/conversations/${uuid}/mute`),
  attachmentUrl: (uuid: string, attachmentId: number) => `/api/v1/conversations/${uuid}/attachments/${attachmentId}`,
}

// --- Calls ------------------------------------------------------------------

export const conversationMembers = (uuid: string) =>
  api.get<{ data: { uuid: string; name: string; username: string | null; is_me: boolean }[] }>(
    `/conversations/${uuid}/members`,
  ).then((r) => r.data.data)

export const calls = {
  config: () =>
    api.get<{ data: { iceServers: RTCIceServer[] } }>('/calls/config').then((r) => r.data.data),
  initiate: (conversationUuid: string, type: 'audio' | 'video') =>
    api.post<{ data: CallInfo }>(`/conversations/${conversationUuid}/calls`, { type }).then((r) => r.data.data),
  respond: (uuid: string, action: 'accept' | 'decline') =>
    api.post<{ data: CallInfo }>(`/calls/${uuid}/respond`, { action }).then((r) => r.data.data),
  end: (uuid: string) => api.post<{ data: CallInfo }>(`/calls/${uuid}/end`).then((r) => r.data.data),
  signal: (uuid: string, signal: 'offer' | 'answer' | 'ice', payload: Record<string, unknown>, toUuid?: string) =>
    api.post(`/calls/${uuid}/signal`, { signal, payload, ...(toUuid ? { to_uuid: toUuid } : {}) }),
  invite: (uuid: string, identifier: string) =>
    api.post<{ message: string }>(`/calls/${uuid}/invite`, { identifier }).then((r) => r.data),
  history: (page = 1) => api.get<Paginated<CallInfo>>('/calls/history', { params: { page } }).then((r) => r.data),
}

// --- Habits -----------------------------------------------------------------

export const habits = {
  list: () => api.get<{ data: HabitItem[] }>('/habits').then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: HabitItem }>('/habits', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: HabitItem }>(`/habits/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/habits/${uuid}`),
  log: (uuid: string, payload: Record<string, unknown> = {}) =>
    api.post<{ data: HabitItem }>(`/habits/${uuid}/log`, payload).then((r) => r.data.data),
}

// --- Goals ------------------------------------------------------------------

export const goals = {
  list: () => api.get<{ data: GoalItem[] }>('/goals').then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: GoalItem }>('/goals', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: GoalItem }>(`/goals/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/goals/${uuid}`),
  addMilestone: (uuid: string, title: string, due_on?: string) =>
    api.post<{ data: GoalItem }>(`/goals/${uuid}/milestones`, { title, due_on }).then((r) => r.data.data),
  toggleMilestone: (uuid: string, id: number) =>
    api.post<{ data: GoalItem }>(`/goals/${uuid}/milestones/${id}/toggle`).then((r) => r.data.data),
  removeMilestone: (uuid: string, id: number) => api.delete(`/goals/${uuid}/milestones/${id}`),
}

// --- Bills ------------------------------------------------------------------

export const meetings = {
  list: () => api.get<{ data: import('../types').MeetingItem[] }>('/meetings').then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: import('../types').MeetingItem }>('/meetings', payload).then((r) => r.data.data),
  show: (code: string) =>
    api.get<{ data: import('../types').MeetingItem }>(`/meetings/${code}`).then((r) => r.data.data),
  join: (code: string) =>
    api.post<{ data: import('../types').MeetingItem & { joined_peers?: { uuid: string; name: string }[] } }>(
      `/meetings/${code}/join`,
    ).then((r) => r.data.data),
  leave: (code: string) => api.post(`/meetings/${code}/leave`),
  rename: (code: string, display_name: string) =>
    api.post<{ message: string }>(`/meetings/${code}/name`, { display_name }).then((r) => r.data),
  end: (code: string) => api.post(`/meetings/${code}/end`),
  signal: (code: string, signal: 'offer' | 'answer' | 'ice', payload: Record<string, unknown>, toUuid: string) =>
    api.post(`/meetings/${code}/signal`, { signal, payload, to_uuid: toUuid }),
}

export const projects = {
  list: () => api.get<{ data: import('../types').ProjectItem[] }>('/projects').then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: import('../types').ProjectItem }>('/projects', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: import('../types').ProjectItem }>(`/projects/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/projects/${uuid}`),
  entries: (uuid: string, params: Record<string, unknown> = {}) =>
    api.get<Paginated<import('../types').ProjectEntryItem>>(`/projects/${uuid}/entries`, { params }).then((r) => r.data),
  createEntry: (uuid: string, payload: Record<string, unknown>) =>
    api.post(`/projects/${uuid}/entries`, payload).then((r) => r.data),
  updateEntry: (uuid: string, entryUuid: string, payload: Record<string, unknown>) =>
    api.put(`/projects/${uuid}/entries/${entryUuid}`, payload).then((r) => r.data),
  removeEntry: (uuid: string, entryUuid: string) => api.delete(`/projects/${uuid}/entries/${entryUuid}`),
  summary: (uuid: string, params: Record<string, unknown> = {}) =>
    api.get<{ data: import('../types').ProjectSummaryRow[] }>(`/projects/${uuid}/summary`, { params }).then((r) => r.data.data),
  share: (uuid: string, app_id: string, permission: 'view' | 'edit') =>
    api.post<{ message: string }>(`/projects/${uuid}/share`, { app_id, permission }).then((r) => r.data),
  unshare: (uuid: string, userUuid: string) =>
    api.post<{ message: string }>(`/projects/${uuid}/unshare`, { user_uuid: userUuid }).then((r) => r.data),
  exportUrl: (uuid: string) => `${api.defaults.baseURL}/projects/${uuid}/export`,
}

export const bills = {
  list: (status?: string) =>
    api.get<Paginated<BillItem>>('/bills', { params: status ? { status } : {} }).then((r) => r.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: BillItem }>('/bills', payload).then((r) => r.data.data),
  update: (uuid: string, payload: Record<string, unknown>) =>
    api.put<{ data: BillItem }>(`/bills/${uuid}`, payload).then((r) => r.data.data),
  remove: (uuid: string) => api.delete(`/bills/${uuid}`),
  pay: (uuid: string) => api.post(`/bills/${uuid}/pay`).then((r) => r.data),
}

// --- Subscription -----------------------------------------------------------

export const subscription = {
  plans: () => api.get<{ data: PlanInfo[] }>('/plans').then((r) => r.data.data),
  mine: () => api.get<{ data: MySubscription }>('/subscription').then((r) => r.data.data),
  quote: (plan_slug: string, frequency: 'monthly' | 'annual', coupon?: string) =>
    api.post<{ data: BillingQuote }>('/subscription/quote', { plan_slug, frequency, coupon: coupon || null })
      .then((r) => r.data.data),
  checkout: (plan_slug: string, frequency: 'monthly' | 'annual', coupon?: string) =>
    api.post<{ data: CheckoutSession }>('/subscription/checkout', { plan_slug, frequency, coupon: coupon || null })
      .then((r) => r.data.data),
  verify: (orderUuid: string) =>
    api.post<{ data: { order_uuid: string; status: string; plan: string; total: string; paid_at?: string } }>(
      `/payments/${orderUuid}/verify`,
    ).then((r) => r.data.data),
  cancel: () => api.post('/subscription/cancel').then((r) => r.data),
  payments: () => api.get<Paginated<PaymentRecord>>('/payments').then((r) => r.data),
  invoices: () => api.get<Paginated<InvoiceRecord>>('/invoices').then((r) => r.data),
  invoiceUrl: (uuid: string) => `/api/v1/invoices/${uuid}`,
}

// --- Reports ----------------------------------------------------------------

export const reports = {
  summary: () => api.get<{ data: ReportSummary }>('/reports/summary').then((r) => r.data.data),
  productivity: (days = 30) =>
    api.get<{ data: { date: string; completed: number; created: number }[] }>(
      '/reports/productivity', { params: { days } },
    ).then((r) => r.data.data),
  csvUrl: '/api/v1/reports/export.csv',
}

// --- Admin ------------------------------------------------------------------

export const admin = {
  stats: () => api.get<{ data: AdminStats }>('/admin/stats').then((r) => r.data.data),
  users: (params: Record<string, unknown> = {}) =>
    api.get<Paginated<User>>('/admin/users', { params }).then((r) => r.data),
  createUser: (payload: Record<string, unknown>) => api.post('/admin/users', payload),
  suspend: (uuid: string) => api.post(`/admin/users/${uuid}/suspend`),
  activate: (uuid: string) => api.post(`/admin/users/${uuid}/activate`),
  syncRoles: (uuid: string, roles: string[]) => api.post(`/admin/users/${uuid}/roles`, { roles }),
  regenerateAppId: (uuid: string) => api.post(`/admin/users/${uuid}/app-id/regenerate`),
}
