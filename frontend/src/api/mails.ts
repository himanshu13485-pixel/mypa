import { api } from './client'

/**
 * Mails: the mail client inside the CRM.
 *
 * Everything here is the signed-in person's own mail - the server scopes
 * every call to the mailboxes they added.
 */

export type MailFolder = 'inbox' | 'outbox' | 'drafts' | 'scheduled' | 'sent' | 'spam' | 'trash' | 'archive' | 'starred'

export interface MailAddress {
  email: string
  name?: string | null
}

export interface MailLabelTag {
  uuid: string
  name: string
  color: string
}

export interface MailSummary {
  uuid: string
  folder: MailFolder
  thread_key: string
  from_name: string | null
  from_email: string | null
  to: MailAddress[]
  subject: string | null
  snippet: string | null
  has_attachments: boolean
  is_read: boolean
  is_starred: boolean
  date: string | null
  scheduled_for: string | null
  send_after: string | null
  status: 'queued' | 'sending' | 'sent' | 'failed' | 'cancelled' | null
  error: string | null
  account_uuid: string | null
  labels: MailLabelTag[]
  thread_count?: number
  thread_unread?: number
  undo_seconds?: number
}

export interface MailAttachmentInfo {
  id: number
  filename: string
  mime: string | null
  size: number | null
  is_inline: boolean
}

export interface MailFull extends MailSummary {
  cc: MailAddress[]
  bcc: MailAddress[]
  reply_to: string | null
  message_id: string | null
  body_html: string | null
  body_text: string | null
  attachments: MailAttachmentInfo[]
}

export interface MailProvider {
  key: string
  label: string
  imap_host: string
  imap_port: number
  imap_encryption: string
  smtp_host: string
  smtp_port: number
  smtp_encryption: string
  note: string | null
}

export interface MailAutoReply {
  enabled: boolean
  subject?: string | null
  body?: string | null
  from?: string | null
  until?: string | null
}

export interface MailDnsCheck {
  ok: boolean
  record?: string | null
  note: string
  selector?: string
  policy?: string
}

export interface MailDnsResult {
  domain: string
  spf: MailDnsCheck
  dkim: MailDnsCheck
  dmarc: MailDnsCheck
  score: number
  checked_at: string
}

export interface MailShare {
  uuid: string
  name: string | null
  can_send: boolean
}

export interface MailPerson {
  uuid: string
  name: string | null
  email: string | null
  has_mails: boolean
}

export interface MailAccountInfo {
  uuid: string
  label: string | null
  email: string
  from_name: string | null
  reply_to: string | null
  provider: string
  imap_host: string | null
  imap_port: number
  imap_encryption: string
  imap_username: string | null
  has_imap_password: boolean
  smtp_host: string | null
  smtp_port: number
  smtp_encryption: string
  smtp_username: string | null
  has_smtp_password: boolean
  signature_html: string | null
  /** A shorter one for replies; blank means the same as the main signature. */
  signature_reply_html: string | null
  /** new | all | none - where the signature is put. */
  signature_on: 'new' | 'all' | 'none'
  signature_before_quote: boolean
  /** Addresses this mailbox forwards to, and whether each has answered its code. */
  forwards: { address: string; verified: boolean; sent_at: string | null }[]
  auto_reply: MailAutoReply
  forward_to: string | null
  is_default: boolean
  can_receive: boolean
  can_send: boolean
  last_synced_at: string | null
  last_error: string | null
  status: string
  /** A short badge the Admin writes, e.g. "Reports" or "Support desk". */
  tag: string | null
  is_shared: boolean
  shared_with: MailShare[]
  daily_cap: number | null
  sent_today: number
  sends_left: number | null
  dkim_selector: string | null
  /** False when this mailbox accepts a certificate issued for another name. */
  verify_cert: boolean
  dns: MailDnsResult | null
  /** Set when the account was disconnected - its mail is still here. */
  detached_at: string | null
  created_by_admin: boolean
  /** Mine to use, and mine to change? Shared mailboxes are neither. */
  is_mine?: boolean
  can_manage?: boolean
  owner?: string | null
}

export interface MailArchiveStatus {
  folder: string
  absolute: string
  files: number
  bytes: number
  stored: number
  last_backup_at: string | null
}

export interface MailBackupRow {
  uuid: string
  email: string
  label: string | null
  can_manage: boolean
  archive: MailArchiveStatus
  destinations: {
    server: true
    remote: {
      enabled: boolean
      driver: 's3' | 'webdav' | 'gdrive'
      bucket: string | null
      region: string | null
      endpoint: string | null
      key: string | null
      url: string | null
      username: string | null
      folder_id: string | null
      path: string | null
      has_secret: boolean
      last_run_at: string | null
      last_error: string | null
    }
    local: { enabled: boolean; hint: string | null }
  }
  last_error: string | null
}

export interface MailBackupRunRow {
  uuid: string
  kind: 'backup' | 'export' | 'import'
  destination: string
  status: 'running' | 'done' | 'failed'
  messages: number
  bytes: number
  path: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  mailbox: string | null
}

export interface MailPrefs {
  undo_seconds: 0 | 5 | 10 | 20 | 30
  conversation: boolean
  reading_pane: 'right' | 'bottom' | 'off'
  density: 'comfortable' | 'compact'
  accent: 'brand' | 'emerald' | 'violet' | 'rose' | 'amber' | 'slate'
  load_images: 'ask' | 'always'
  default_account: string | null
}

export interface MailDashboard {
  accounts: {
    uuid: string
    email: string
    label: string | null
    unread: number
    last_synced_at: string | null
    last_error: string | null
    can_send: boolean
    can_receive: boolean
  }[]
  unread: number
  received_today: number
  sent_today: number
  scheduled: number
  failed: number
  drafts: number
  recent_unread: MailSummary[]
  upcoming: MailSummary[]
  limit: number
}

export interface MailTeamRow {
  uuid: string
  name: string | null
  email: string | null
  role: string
  has_mails: boolean
  locked: boolean
  limit: number
  mailboxes: number
}

/** A company mailbox on the Team access screen: who owns it, who else opens it. */
export interface MailTeamMailbox {
  uuid: string
  email: string
  label: string | null
  owner: string | null
  owner_uuid: string | null
  shared_with: string[]
}

export interface MailListPage {
  data: MailSummary[]
  current_page: number
  last_page: number
  total: number
  threaded: boolean
}

export type MailComposePayload = {
  action: 'draft' | 'send' | 'schedule'
  draft?: string | null
  account: string
  to: string[]
  cc: string[]
  bcc: string[]
  subject: string
  body_html: string
  reply_to_uuid?: string | null
  forward_uuid?: string | null
  include_attachments?: boolean
  scheduled_for?: string | null
  remove_attachments?: number[]
  files?: File[]
}

const base = '/crm/mails'

export const mails = {
  dashboard: () => api.get<{ data: MailDashboard }>(`${base}/dashboard`).then((r) => r.data.data),
  counts: (account?: string) =>
    api.get<{ data: { folders: Record<string, number>; labels: (MailLabelTag & { count: number })[] } }>(`${base}/counts`, {
      params: account && account !== 'all' ? { account } : {},
    }).then((r) => r.data.data),
  list: (params: { folder?: MailFolder; label?: string; q?: string; unread?: boolean; page?: number; account?: string }) =>
    api.get<MailListPage>(`${base}/messages`, {
      params: {
        ...params,
        account: params.account && params.account !== 'all' ? params.account : undefined,
        unread: params.unread ? 1 : undefined,
      },
    }).then((r) => r.data),
  show: (uuid: string) =>
    api.get<{ data: { message: MailFull; thread: MailFull[] } }>(`${base}/messages/${uuid}`).then((r) => r.data.data),
  update: (uuid: string, body: { is_read?: boolean; is_starred?: boolean; labels?: string[] }) =>
    api.patch<{ data: MailSummary }>(`${base}/messages/${uuid}`, body).then((r) => r.data.data),
  /** `thread` makes each uuid stand for its whole conversation in that folder. */
  bulk: (uuids: string[], action: string, label?: string, thread = false) =>
    api.post<{ message: string }>(`${base}/messages/bulk`, { uuids, action, label, thread }).then((r) => r.data),
  empty: (folder: 'trash' | 'spam') => api.post<{ message: string }>(`${base}/empty`, { folder }).then((r) => r.data),
  attachmentUrl: (uuid: string, id: number) => `${base}/messages/${uuid}/attachments/${id}`,
  attachment: (uuid: string, id: number) =>
    api.get(`${base}/messages/${uuid}/attachments/${id}`, { responseType: 'blob' }).then((r) => r.data as Blob),

  compose: (payload: MailComposePayload) => {
    const form = new FormData()
    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null || key === 'files') return
      if (Array.isArray(value)) value.forEach((v) => form.append(`${key}[]`, String(v)))
      else if (typeof value === 'boolean') form.append(key, value ? '1' : '0')
      else form.append(key, String(value))
    })
    ;(payload.files ?? []).forEach((f) => form.append('attachments[]', f))

    return api.post<{ message: string; data: MailSummary }>(`${base}/compose`, form).then((r) => r.data)
  },
  cancel: (uuid: string) => api.post<{ message: string; data: MailFull }>(`${base}/messages/${uuid}/cancel`).then((r) => r.data),
  sendNow: (uuid: string) => api.post<{ message: string }>(`${base}/messages/${uuid}/send-now`).then((r) => r.data),

  accounts: () =>
    api.get<{ data: MailAccountInfo[]; limit: number; used: number; is_admin: boolean; providers: MailProvider[]; people: MailPerson[] }>(`${base}/accounts`)
      .then((r) => r.data),
  addAccount: (body: Record<string, unknown>) =>
    api.post<{ message: string; data: MailAccountInfo }>(`${base}/accounts`, body).then((r) => r.data),
  saveAccount: (uuid: string, body: Record<string, unknown>) =>
    api.put<{ message: string; data: MailAccountInfo }>(`${base}/accounts/${uuid}`, body).then((r) => r.data),
  /** Disconnect by default; `purge` (with the address typed back) removes its mail too. */
  removeAccount: (uuid: string, purge?: { confirm: string }) =>
    api.delete<{ message: string }>(`${base}/accounts/${uuid}`, purge ? { data: { purge: true, confirm: purge.confirm } } : undefined)
      .then((r) => r.data),
  testInbox: (uuid: string) =>
    api.post<{ data: { ok: boolean; folders: string[]; message: string } }>(`${base}/accounts/${uuid}/inbox-test`).then((r) => r.data.data),
  testEmail: (uuid: string, to?: string) =>
    api.post<{ data: { ok: boolean; message: string } }>(`${base}/accounts/${uuid}/test-email`, { to }).then((r) => r.data.data),
  checkDns: (uuid: string, selector?: string) =>
    api.post<{ data: MailDnsResult }>(`${base}/accounts/${uuid}/dns`, { selector }).then((r) => r.data.data),
  replicate: (uuid: string, body: Record<string, unknown>) =>
    api.post<{ message: string; data: MailAccountInfo }>(`${base}/accounts/${uuid}/replicate`, body).then((r) => r.data),
  testAccount: (uuid: string) =>
    api.post<{ data: { imap: { ok: boolean; message: string }; smtp: { ok: boolean; message: string } } }>(`${base}/accounts/${uuid}/test`)
      .then((r) => r.data.data),
  syncAccount: (uuid: string, now = false) =>
    api.post<{ message: string }>(`${base}/accounts/${uuid}/sync`, { now }).then((r) => r.data),

  addForward: (uuid: string, address: string) =>
    api.post<{ message: string; data: MailAccountInfo }>(`${base}/accounts/${uuid}/forwards`, { address }).then((r) => r.data),
  verifyForward: (uuid: string, address: string, code: string) =>
    api.post<{ message: string; data: MailAccountInfo }>(`${base}/accounts/${uuid}/forwards/verify`, { address, code }).then((r) => r.data),
  removeForward: (uuid: string, address: string) =>
    api.delete<{ message: string; data: MailAccountInfo }>(`${base}/accounts/${uuid}/forwards`, { data: { address } }).then((r) => r.data),
  signatureImage: (uuid: string, file: File) => {
    const form = new FormData()
    form.append('image', file)

    return api.post<{ data: { path: string; url: string } }>(`${base}/accounts/${uuid}/signature-image`, form).then((r) => r.data.data)
  },

  labels: () => api.get<{ data: MailLabelTag[] }>(`${base}/labels`).then((r) => r.data.data),
  addLabel: (name: string, color: string) =>
    api.post<{ data: MailLabelTag }>(`${base}/labels`, { name, color }).then((r) => r.data.data),
  saveLabel: (uuid: string, name: string, color: string) =>
    api.put<{ data: MailLabelTag }>(`${base}/labels/${uuid}`, { name, color }).then((r) => r.data.data),
  removeLabel: (uuid: string) => api.delete(`${base}/labels/${uuid}`),

  settings: () =>
    api.get<{ data: { prefs: MailPrefs; limit: number; cap: number; is_admin: boolean; ai_available: boolean } }>(`${base}/settings`)
      .then((r) => r.data.data),
  savePrefs: (prefs: Partial<MailPrefs>) =>
    api.put<{ data: MailPrefs }>(`${base}/settings/prefs`, prefs).then((r) => r.data.data),
  team: () =>
    api.get<{ data: MailTeamRow[]; cap: number; mailboxes: MailTeamMailbox[] }>(`${base}/settings/team`).then((r) => r.data),
  saveTeam: (uuid: string, enabled: boolean, limit?: number) =>
    api.put<{ message: string }>(`${base}/settings/team/${uuid}`, { enabled, limit }).then((r) => r.data),
  ai: () =>
    api.get<{ data: { enabled: boolean; provider: 'anthropic' | 'openai'; model: string; has_key: boolean; default_claude_model: string; available: boolean } }>(`${base}/settings/ai`)
      .then((r) => r.data.data),
  saveAi: (body: { enabled: boolean; provider: string; model: string; api_key?: string }) =>
    api.put<{ message: string }>(`${base}/settings/ai`, body).then((r) => r.data),

  backups: () =>
    api.get<{ data: MailBackupRow[]; runs: MailBackupRunRow[]; is_admin: boolean; drivers: string[] }>(`${base}/backups`).then((r) => r.data),
  saveBackup: (uuid: string, body: Record<string, unknown>) =>
    api.put<{ message: string }>(`${base}/backups/${uuid}`, body).then((r) => r.data),
  testBackup: (uuid: string) =>
    api.post<{ data: { ok: boolean; message: string } }>(`${base}/backups/${uuid}/test`).then((r) => r.data.data),
  runBackup: (uuid: string, full = false) =>
    api.post<{ message: string; data: MailBackupRunRow }>(`${base}/backups/${uuid}/run`, { now: true, full }).then((r) => r.data),
  exportArchive: (uuid: string, folder?: string) =>
    api.get(`${base}/backups/${uuid}/export`, { params: folder ? { folder } : {}, responseType: 'blob' }).then((r) => r.data as Blob),
  importArchive: (uuid: string, file: File, folder?: string) => {
    const form = new FormData()
    form.append('file', file)
    if (folder) form.append('folder', folder)

    return api.post<{ message: string; data: { added: number; skipped: number } }>(`${base}/backups/${uuid}/import`, form).then((r) => r.data)
  },

  write: (body: { mode: 'compose' | 'reply' | 'improve'; instruction?: string; tone?: string; to?: string; text?: string; action?: string; message_uuid?: string }) =>
    api.post<{ data: { subject: string | null; body: string } }>(`${base}/ai`, body).then((r) => r.data.data),
}
