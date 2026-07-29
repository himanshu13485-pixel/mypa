import { api } from './client'
import type {
  AdminStats, AppNotification, CalendarEvent, CalendarFeedTask, Category,
  Connection, DashboardSummary, Paginated, Task, User,
} from '../types'

// --- Auth -------------------------------------------------------------------

export const auth = {
  register: (payload: Record<string, unknown>) =>
    api.post<{ data: User; token: string }>('/auth/register', payload).then((r) => r.data),
  login: (payload: { email: string; password: string; device_name?: string }) =>
    api.post<{ data: User; token: string }>('/auth/login', payload).then((r) => r.data),
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
