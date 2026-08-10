import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity, Ban, BarChart3, Bug, CheckCircle2, ClipboardCheck, CreditCard, Flag,
  KeyRound, LogIn, MailCheck, MessagesSquare, Pencil, Plus, Radio, RefreshCw, Search, Send,
  Shield, SlidersHorizontal, UserCheck, Users, Wifi,
} from 'lucide-react'
import { format, formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { admin, adminBilling, adminCare, adminInternal, adminOps, adminSales, identity as identityApi } from '../../api/endpoints'
import type { AdminPlan } from '../../types'
import { api, errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import UserSuggest from '../../components/UserSuggest'
import { useToast } from '../../components/Toast'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner,
} from '../../components/ui'
import type { User } from '../../types'

const MODULES = ['users', 'approvals', 'moderation', 'activity'] as const
const ABILITIES = ['can_view', 'can_edit', 'can_delete'] as const

// ---------------------------------------------------------------------------
// Approvals
// ---------------------------------------------------------------------------

function ApprovalsTab() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin-change-requests'],
    queryFn: identityApi.pending,
    refetchInterval: 60_000,
  })

  const reviewMutation = useMutation({
    mutationFn: ({ uuid, action, note }: { uuid: string; action: 'approve' | 'reject'; note?: string }) =>
      identityApi.review(uuid, action, note),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-change-requests'] }),
    onError: (err) => alert(errorMessage(err)),
  })

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">Identity change approvals</h2>
      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <EmptyState title="No pending requests" hint="Mobile, email, and username changes appear here for review." />
      ) : (
        <div className="space-y-2">
          {data.data.map((r) => (
            <div key={r.uuid} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">
              <div className="text-sm">
                <p className="font-medium">
                  {r.user?.name}{' '}
                  <span className="text-xs text-slate-400">({r.user?.username ?? r.user?.email ?? r.user?.mobile})</span>
                </p>
                <p className="text-xs text-slate-500">
                  <span className="capitalize">{r.type}</span>: <s>{r.current_value ?? '—'}</s> → <b>{r.new_value}</b>
                </p>
              </div>
              <div className="flex gap-1.5">
                <Button size="sm" onClick={() => reviewMutation.mutate({ uuid: r.uuid, action: 'approve' })}>
                  Approve
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => {
                    const note = prompt('Reason for rejection (shown to the user):') ?? undefined
                    reviewMutation.mutate({ uuid: r.uuid, action: 'reject', note })
                  }}
                >
                  Reject
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Active members
// ---------------------------------------------------------------------------

function ActiveMembersTab() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-active-members'],
    queryFn: adminOps.activeMembers,
    refetchInterval: 60_000,
  })

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">Active members (last 24 hours)</h2>
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <EmptyState title="No activity in the last 24 hours" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 pr-4 font-medium">Member</th>
                <th className="pb-2 pr-4 font-medium">User ID</th>
                <th className="pb-2 pr-4 font-medium">Mobile</th>
                <th className="pb-2 pr-4 font-medium">IP address</th>
                <th className="pb-2 pr-4 font-medium">Device</th>
                <th className="pb-2 pr-4 font-medium">Last active</th>
                <th className="pb-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {data.map((m) => (
                <tr key={m.uuid} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2.5 pr-4">
                    <span className="font-medium">{m.name}</span>
                    <span className="ml-1 text-xs text-slate-400">@{m.username}</span>
                  </td>
                  <td className="py-2.5 pr-4 font-mono text-xs">{m.app_id}</td>
                  <td className="py-2.5 pr-4 text-xs text-slate-500">{m.mobile}</td>
                  <td className="py-2.5 pr-4 font-mono text-xs">{m.ip_address ?? '—'}</td>
                  <td className="py-2.5 pr-4 text-xs text-slate-500">{m.device ?? '—'}</td>
                  <td className="py-2.5 pr-4 text-xs text-slate-500">
                    {formatDistanceToNow(new Date(m.last_active_at), { addSuffix: true })}
                  </td>
                  <td className="py-2.5">
                    {m.is_online ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                        <span className="size-1.5 rounded-full bg-emerald-500" /> Online
                      </span>
                    ) : (
                      <Badge value={m.status} />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Activity (audit logs) & Logins
// ---------------------------------------------------------------------------

function ActivityTab() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['admin-audit-logs', page],
    queryFn: () => adminOps.auditLogs(undefined, page),
  })

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">Audit trail (admin & moderation actions)</h2>
      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <EmptyState title="No audit entries yet" />
      ) : (
        <div className="space-y-1.5">
          {data.data.map((log) => (
            <div key={log.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
              <div>
                <span className="font-mono text-xs text-brand-600">{log.action}</span>
                <span className="ml-2 text-xs text-slate-500">by {log.actor?.name ?? 'system'}</span>
                {log.subject_name && (
                  <span className="ml-2 text-xs font-medium text-slate-600 dark:text-slate-300">{'→'} {log.subject_name}</span>
                )}
                {log.details && (
                  <span className="ml-2 text-[11px] text-slate-400">{JSON.stringify(log.details)}</span>
                )}
              </div>
              <span className="text-[11px] text-slate-400">
                {log.ip_address} · {format(new Date(log.created_at), 'd MMM, HH:mm')}
              </span>
            </div>
          ))}
        </div>
      )}
      <Pager resp={data} onPage={setPage} />
    </Card>
  )
}

function LoginsTab() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['admin-login-histories', query, page],
    queryFn: () => adminOps.loginHistories(query || undefined, page),
  })

  return (
    <Card>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">Login history (all users)</h2>
        <div className="flex gap-1">
          <Input
            placeholder="Filter by name or email…"
            className="w-52"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && (setPage(1), setQuery(search))}
          />
          <Button variant="secondary" size="sm" onClick={() => { setPage(1); setQuery(search) }}>
            <Search className="size-4" />
          </Button>
        </div>
      </div>
      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <EmptyState title="No logins recorded" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 pr-4 font-medium">User</th>
                <th className="pb-2 pr-4 font-medium">IP address</th>
                <th className="pb-2 pr-4 font-medium">Device</th>
                <th className="pb-2 pr-4 font-medium">Logged in</th>
                <th className="pb-2 font-medium">Logged out</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2 pr-4 font-medium">{row.user?.name ?? '—'}</td>
                  <td className="py-2 pr-4 font-mono text-xs">{row.ip_address}</td>
                  <td className="py-2 pr-4 text-xs text-slate-500">{row.device_name}</td>
                  <td className="py-2 pr-4 text-xs text-slate-500">{format(new Date(row.logged_in_at), 'd MMM, HH:mm')}</td>
                  <td className="py-2 text-xs text-slate-500">
                    {row.logged_out_at ? format(new Date(row.logged_out_at), 'd MMM, HH:mm') : 'active session'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager resp={data} onPage={setPage} />
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Moderation
// ---------------------------------------------------------------------------

function ModerationTab() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('open')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['admin-reports', status, page],
    queryFn: () => adminOps.reports(status, page),
    refetchInterval: 60_000,
  })

  const actMutation = useMutation({
    mutationFn: ({ uuid, action, note }: { uuid: string; action: string; note?: string }) =>
      adminOps.actOnReport(uuid, action, note),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-reports'] }),
    onError: (err) => alert(errorMessage(err)),
  })

  const act = (uuid: string, action: string, needsNote = false) => {
    const note = needsNote ? (prompt('Note (optional, shown in warnings/audit):') ?? undefined) : undefined
    if (action === 'suspend' && !confirm('Suspend this user? They are signed out immediately.')) return
    actMutation.mutate({ uuid, action, note })
  }

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">Moderation queue</h2>
        <div className="flex gap-1">
          {['open', 'actioned', 'dismissed'].map((option) => (
            <Button key={option} size="sm" variant={status === option ? 'primary' : 'ghost'} onClick={() => setStatus(option)}>
              {option}
            </Button>
          ))}
        </div>
      </div>
      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <EmptyState title={`No ${status} reports`} hint="Reports from users about messages or members appear here." />
      ) : (
        <div className="space-y-2">
          {data.data.map((report) => (
            <div key={report.uuid} className="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-sm">
                  <p>
                    <Badge value={report.reason} className="mr-2" />
                    <span className="font-medium">{report.reporter?.name}</span>
                    <span className="text-slate-500"> reported </span>
                    <span className="font-medium">{report.reported_user?.name}</span>
                    {report.reported_user?.status === 'suspended' && <Badge value="suspended" className="ml-2" />}
                  </p>
                  {report.message && (
                    <p className="mt-1 rounded bg-slate-50 px-2 py-1 text-xs italic text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      {report.message.deleted_at ? '(message deleted)' : `“${report.message.body ?? '…'}”`}
                    </p>
                  )}
                  {report.details && <p className="mt-1 text-xs text-slate-500">Details: {report.details}</p>}
                  <p className="mt-0.5 text-[11px] text-slate-400">
                    {format(new Date(report.created_at), 'd MMM, HH:mm')}
                    {report.reviewer && ` · handled by ${report.reviewer.name} (${report.action_taken})`}
                  </p>
                </div>
                {report.status === 'open' && (
                  <div className="flex flex-wrap gap-1.5">
                    <Button size="sm" variant="secondary" onClick={() => act(report.uuid, 'dismiss')}>
                      Dismiss
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => act(report.uuid, 'warn', true)}>
                      Warn user
                    </Button>
                    {report.message && !report.message.deleted_at && (
                      <Button size="sm" variant="danger" onClick={() => act(report.uuid, 'delete_message')}>
                        Delete message
                      </Button>
                    )}
                    <Button size="sm" variant="danger" onClick={() => act(report.uuid, 'suspend')}>
                      <Ban className="size-3.5" /> Suspend
                    </Button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
      <Pager resp={data} onPage={setPage} />
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Plans
// ---------------------------------------------------------------------------

const GB = 1024 * 1024 * 1024
const PLAN_LIMIT_FIELDS: { key: string; label: string; isBytes?: boolean }[] = [
  { key: 'max_tasks', label: 'Max tasks' },
  { key: 'storage_bytes', label: 'Storage (GB)', isBytes: true },
  { key: 'max_groups', label: 'Max groups' },
  { key: 'max_group_members', label: 'Members per group' },
  { key: 'max_categories', label: 'Custom categories' },
  // The host's plan governs the whole meeting, so these are what everyone in
  // one of their rooms gets — not a per-person allowance.
  { key: 'max_meeting_participants', label: 'People per meeting' },
  { key: 'max_meeting_minutes', label: 'Meeting length (minutes)' },
]
const PLAN_FEATURE_FIELDS: { key: string; label: string }[] = [
  { key: 'calls', label: 'Audio & video calls' },
  { key: 'reports_export', label: 'Report exports' },
  { key: 'subadmins', label: 'Subadmin accounts' },
  { key: 'voice_assistant', label: 'Voice assistant' },
]

function PlanEditModal({ plan, onClose, onSaved }: { plan: AdminPlan; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState(() => ({
    monthly_price: String(plan.monthly_price),
    annual_price: String(plan.annual_price),
    trial_days: plan.trial_days,
    is_active: plan.is_active,
    is_public: plan.is_public,
    is_recommended: plan.is_recommended,
    limits: Object.fromEntries(
      PLAN_LIMIT_FIELDS.map(({ key, isBytes }) => {
        const raw = plan.limits?.[key] ?? null
        return [key, raw === null ? '' : String(isBytes ? Math.round((raw as number) / GB) : raw)]
      }),
    ) as Record<string, string>,
    features: Object.fromEntries(
      PLAN_FEATURE_FIELDS.map(({ key }) => [key, Boolean(plan.features?.[key])]),
    ) as Record<string, boolean>,
  }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await adminBilling.updatePlan(plan.slug, {
        monthly_price: Number(form.monthly_price || 0),
        annual_price: Number(form.annual_price || 0),
        trial_days: Number(form.trial_days || 0),
        is_active: form.is_active,
        is_public: form.is_public,
        is_recommended: form.is_recommended,
        limits: Object.fromEntries(
          PLAN_LIMIT_FIELDS.map(({ key, isBytes }) => {
            const raw = form.limits[key].trim()
            if (raw === '') return [key, null] // blank = unlimited
            const n = Number(raw)
            return [key, isBytes ? n * GB : n]
          }),
        ),
        features: form.features,
      })
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={`Edit plan — ${plan.name}`} onClose={onClose} wide>
      <div className="space-y-4">
        <ErrorNote message={error} />
        <div className="grid grid-cols-3 gap-3">
          <div>
            <Label>Monthly price (₹)</Label>
            <Input type="number" min={0} step="0.01" value={form.monthly_price} onChange={(e) => setForm({ ...form, monthly_price: e.target.value })} />
          </div>
          <div>
            <Label>Annual price (₹)</Label>
            <Input type="number" min={0} step="0.01" value={form.annual_price} onChange={(e) => setForm({ ...form, annual_price: e.target.value })} />
          </div>
          <div>
            <Label>Trial days</Label>
            <Input type="number" min={0} value={form.trial_days} onChange={(e) => setForm({ ...form, trial_days: Number(e.target.value) })} />
          </div>
        </div>

        <div>
          <Label>Limits (leave blank for unlimited)</Label>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {PLAN_LIMIT_FIELDS.map(({ key, label }) => (
              <div key={key}>
                <p className="mb-0.5 text-[11px] text-slate-500">{label}</p>
                <Input
                  type="number"
                  min={0}
                  placeholder="unlimited"
                  value={form.limits[key]}
                  onChange={(e) => setForm({ ...form, limits: { ...form.limits, [key]: e.target.value } })}
                />
              </div>
            ))}
          </div>
        </div>

        <div>
          <Label>Features</Label>
          <div className="grid grid-cols-2 gap-2">
            {PLAN_FEATURE_FIELDS.map(({ key, label }) => (
              <label key={key} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.features[key]}
                  onChange={(e) => setForm({ ...form, features: { ...form.features, [key]: e.target.checked } })}
                />
                {label}
              </label>
            ))}
          </div>
        </div>

        <div className="flex flex-wrap gap-4">
          {([['is_active', 'Active (usable)'], ['is_public', 'Public (shown on pricing page)'], ['is_recommended', 'Recommended badge']] as const).map(([key, label]) => (
            <label key={key} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={form[key]}
                onChange={(e) => setForm({ ...form, [key]: e.target.checked })}
              />
              {label}
            </label>
          ))}
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save plan'}</Button>
        </div>
      </div>
    </Modal>
  )
}

function PlansTab() {
  const queryClient = useQueryClient()
  const me = useAuthStore((s) => s.user)
  const isSuperAdmin = !!me?.roles?.includes('super_admin')
  const { data: plans, isLoading } = useQuery({ queryKey: ['admin-plans'], queryFn: adminBilling.plans })
  const [editing, setEditing] = useState<AdminPlan | null>(null)

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">Subscription plans</h2>
        <p className="text-[11px] text-slate-400">
          {isSuperAdmin ? 'Click ✏ to edit prices, limits and visibility.' : 'Editing requires super admin.'}
        </p>
      </div>
      {isLoading ? (
        <Spinner />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 pr-4 font-medium">Plan</th>
                <th className="pb-2 pr-4 font-medium">Monthly</th>
                <th className="pb-2 pr-4 font-medium">Annual</th>
                <th className="pb-2 pr-4 font-medium">Trial</th>
                <th className="pb-2 pr-4 font-medium">Subscribers</th>
                <th className="pb-2 pr-4 font-medium">Flags</th>
                <th className="pb-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {plans?.map((plan) => (
                <tr key={plan.slug} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2.5 pr-4">
                    <span className="font-medium">{plan.name}</span>
                    <span className="ml-1.5 font-mono text-[11px] text-slate-400">{plan.slug}</span>
                  </td>
                  <td className="py-2.5 pr-4">₹{plan.monthly_price}</td>
                  <td className="py-2.5 pr-4">₹{plan.annual_price}</td>
                  <td className="py-2.5 pr-4">{plan.trial_days ? `${plan.trial_days}d` : '—'}</td>
                  <td className="py-2.5 pr-4">{plan.subscriptions_count ?? 0}</td>
                  <td className="py-2.5 pr-4">
                    <div className="flex flex-wrap gap-1">
                      {!plan.is_active && <Badge value="suspended" />}
                      {plan.is_active && <Badge value="active" />}
                      {!plan.is_public && <Badge value="draft" />}
                      {plan.is_recommended && <Badge value="planned" />}
                    </div>
                  </td>
                  <td className="py-2.5">
                    {isSuperAdmin && (
                      <Button size="sm" variant="ghost" title="Edit plan" onClick={() => setEditing(plan)}>
                        <Pencil className="size-3.5" />
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <PlanEditModal
          plan={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            queryClient.invalidateQueries({ queryKey: ['admin-plans'] })
            queryClient.invalidateQueries({ queryKey: ['plans'] })
          }}
        />
      )}
    </Card>
  )
}

function AssignPlanModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { data: plans } = useQuery({ queryKey: ['admin-plans'], queryFn: adminBilling.plans })
  const [slug, setSlug] = useState('')
  const [months, setMonths] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const assign = async () => {
    if (!slug) return
    setBusy(true)
    setError(null)
    try {
      const res = await adminBilling.assignPlan(user.uuid, slug, months ? Number(months) : null)
      alert((res as { message?: string }).message ?? 'Plan assigned.')
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={`Assign plan — ${user.name}`} onClose={onClose}>
      <div className="space-y-4">
        <ErrorNote message={error} />
        <div>
          <Label>Plan</Label>
          <Select value={slug} onChange={(e) => setSlug(e.target.value)}>
            <option value="">Choose a plan…</option>
            {plans?.filter((p) => p.is_active).map((p) => (
              <option key={p.slug} value={p.slug}>{p.name} (₹{p.monthly_price}/mo)</option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Duration in months (blank = no expiry)</Label>
          <Input type="number" min={1} placeholder="e.g. 12" value={months} onChange={(e) => setMonths(e.target.value)} />
        </div>
        <p className="text-[11px] text-slate-400">
          The change applies immediately, replaces any current subscription, and is recorded in
          the audit log with your name.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={assign} disabled={busy || !slug}>Assign plan</Button>
        </div>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// User summary + subadmin rights modals
// ---------------------------------------------------------------------------

function UserSummaryModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-user-summary', user.uuid],
    queryFn: () => adminOps.userSummary(user.uuid),
  })

  const formatBytes = (bytes: number) => {
    if (!bytes) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB']
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
    return `${(bytes / 1024 ** i).toFixed(1)} ${units[i]}`
  }

  return (
    <Modal title={`Activity summary — ${user.name}`} onClose={onClose} wide>
      {isLoading || !data ? (
        <Spinner />
      ) : (
        <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
          {[
            ['Member since', format(new Date(data.member_since), 'd MMM yyyy')],
            ['Plan', data.plan],
            ['Last login', data.last_login ? `${formatDistanceToNow(new Date(data.last_login.at), { addSuffix: true })}` : 'never'],
            ['Last IP', data.last_login?.ip ?? '—'],
            ['Logins this week', data.logins_this_week],
            ['Tasks (total)', data.tasks.total],
            ['Tasks completed', data.tasks.completed],
            ['Tasks this week', data.tasks.created_this_week],
            ['Notes', data.notes],
            ['Files', `${data.files.count} (${formatBytes(data.files.storage_bytes)})`],
            ['Calls (total)', data.calls?.total ?? 0],
            ['Calls this week', data.calls?.this_week ?? 0],
            ['Missed calls', data.calls?.missed ?? 0],
            ['Talk time', `${data.calls?.minutes ?? 0} min`],
            ['Groups owned', data.groups_owned],
            ['Messages sent', data.messages_sent],
            ['Reports against', data.reports_against],
            ['Open reports', data.open_reports_against],
          ].map(([label, value]) => (
            <div key={String(label)} className="rounded-lg border border-slate-100 p-2.5 dark:border-slate-800">
              <p className="text-lg font-semibold capitalize">{value}</p>
              <p className="text-[11px] text-slate-500">{label}</p>
            </div>
          ))}
        </div>
      )}
      <RecordsSection userUuid={user.uuid} />
      <LockedProjectsSection userUuid={user.uuid} />
    </Modal>
  )
}

/** Password-locked projects of this user: one click emails THEM a reset code. */
function LockedProjectsSection({ userUuid }: { userUuid: string }) {
  const { data } = useQuery({
    queryKey: ['admin-locked-projects', userUuid],
    queryFn: () => adminOps.lockedProjects(userUuid),
  })
  const [sentFor, setSentFor] = useState<string | null>(null)

  if (!data?.length) return null

  return (
    <div className="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
      <p className="mb-1.5 text-xs font-semibold text-slate-500">Password-locked projects</p>
      <div className="space-y-1">
        {data.map((p) => (
          <div key={p.uuid} className="flex items-center justify-between rounded border border-slate-100 px-2.5 py-1.5 text-xs dark:border-slate-800">
            <span>🔒 {p.name}</span>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => {
                adminOps.sendProjectReset(p.uuid)
                  .then((r) => { setSentFor(p.uuid); alert(r.message) })
                  .catch((err) => alert(errorMessage(err)))
              }}
            >
              {sentFor === p.uuid ? 'Code sent ✓' : 'Email reset code'}
            </Button>
          </div>
        ))}
      </div>
      <p className="mt-1 text-[10px] text-slate-400">The code goes to the project owner's email, never to you.</p>
    </div>
  )
}

/**
 * Oversight records for admins: WHO called/messaged WHOM and when — never
 * the content. Call audio is not stored anywhere; message bodies are only
 * ever visible through the moderation flow when a user reports one.
 */
function RecordsSection({ userUuid }: { userUuid: string }) {
  const [tab, setTab] = useState<'none' | 'calls' | 'chats'>('none')
  const { data: callRecs } = useQuery({
    queryKey: ['admin-call-records', userUuid],
    queryFn: () => adminOps.callRecords(userUuid),
    enabled: tab === 'calls',
  })
  const { data: chatRecs } = useQuery({
    queryKey: ['admin-chat-records', userUuid],
    queryFn: () => adminOps.messageRecords(userUuid),
    enabled: tab === 'chats',
  })

  return (
    <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
      <div className="mb-2 flex items-center gap-2">
        <p className="text-xs font-semibold text-slate-500">Records (metadata only — no content)</p>
        <Button size="sm" variant={tab === 'calls' ? 'primary' : 'secondary'} onClick={() => setTab(tab === 'calls' ? 'none' : 'calls')}>
          Call records
        </Button>
        <Button size="sm" variant={tab === 'chats' ? 'primary' : 'secondary'} onClick={() => setTab(tab === 'chats' ? 'none' : 'chats')}>
          Chat records
        </Button>
      </div>
      {tab === 'calls' && (
        !callRecs ? <Spinner /> : !callRecs.data.length ? (
          <p className="text-xs text-slate-400">No calls on record.</p>
        ) : (
          <div className="max-h-52 space-y-1 overflow-y-auto">
            {callRecs.data.map((c) => (
              <div key={c.uuid} className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 px-2.5 py-1.5 text-xs dark:border-slate-800">
                <span>
                  <Badge value={c.type} className="mr-1.5" />
                  {c.participants.join(' ↔ ')}
                </span>
                <span className="text-slate-400">
                  {c.status}
                  {c.duration_seconds != null && c.status === 'ended' && ` · ${Math.floor(c.duration_seconds / 60)}:${String(c.duration_seconds % 60).padStart(2, '0')}`}
                  {c.started_at && ` · ${format(new Date(c.started_at), 'd MMM, HH:mm')}`}
                </span>
              </div>
            ))}
          </div>
        )
      )}
      {tab === 'chats' && (
        !chatRecs ? <Spinner /> : !chatRecs.data.length ? (
          <p className="text-xs text-slate-400">No conversations on record.</p>
        ) : (
          <div className="max-h-52 space-y-1 overflow-y-auto">
            {chatRecs.data.map((c) => (
              <div key={c.uuid} className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 px-2.5 py-1.5 text-xs dark:border-slate-800">
                <span>
                  <Badge value={c.type} className="mr-1.5" />
                  {c.name}
                  <span className="ml-1 text-slate-400">({c.members.join(', ')})</span>
                </span>
                <span className="text-slate-400">
                  {c.messages_count} message(s)
                  {c.last_message_at && ` · last ${format(new Date(c.last_message_at), 'd MMM, HH:mm')}`}
                </span>
              </div>
            ))}
          </div>
        )
      )}
    </div>
  )
}

function RightsModal({ user, onClose }: { user: User; onClose: () => void }) {
  const [grid, setGrid] = useState<Record<string, { can_view: boolean; can_edit: boolean; can_delete: boolean }> | null>(null)
  const [busy, setBusy] = useState(false)

  useQuery({
    queryKey: ['admin-user-rights', user.uuid],
    queryFn: async () => {
      const data = await adminOps.modulePermissions(user.uuid)
      setGrid(data)
      return data
    },
  })

  const setAll = (value: boolean) => {
    if (!grid) return
    setGrid(Object.fromEntries(
      Object.keys(grid).map((module) => [module, { can_view: value, can_edit: value, can_delete: value }]),
    ))
  }

  const save = async () => {
    if (!grid) return
    setBusy(true)
    try {
      await adminOps.saveModulePermissions(user.uuid, grid)
      onClose()
    } catch (err) {
      alert(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={`Subadmin rights — ${user.name}`} onClose={onClose}>
      {!grid ? (
        <Spinner />
      ) : (
        <div className="space-y-4">
          <div className="flex justify-end gap-2">
            <Button size="sm" variant="secondary" onClick={() => setAll(true)}>
              All rights
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setAll(false)}>
              Clear all
            </Button>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 text-left font-medium">Activity</th>
                <th className="pb-2 font-medium">View</th>
                <th className="pb-2 font-medium">Edit</th>
                <th className="pb-2 font-medium">Delete</th>
              </tr>
            </thead>
            <tbody>
              {MODULES.map((module) => (
                <tr key={module} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2 capitalize">{module}</td>
                  {ABILITIES.map((ability) => (
                    <td key={ability} className="py-2 text-center">
                      <input
                        type="checkbox"
                        checked={grid[module]?.[ability] ?? false}
                        onChange={(e) =>
                          setGrid({ ...grid, [module]: { ...grid[module], [ability]: e.target.checked } })
                        }
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
          <p className="text-[11px] text-slate-400">
            View lets the subadmin open the tab; Edit allows approvals/warnings/activation; Delete
            allows suspensions and message removal.
          </p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button onClick={save} disabled={busy}>Save rights</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Users tab
// ---------------------------------------------------------------------------

function UsersTab() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const me = useAuthStore((s) => s.user)
  const isSuperAdmin = !!me?.roles?.includes('super_admin')

  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [createForm, setCreateForm] = useState({ name: '', email: '', password: '', role: 'user' })
  const [error, setError] = useState<string | null>(null)
  const [summaryFor, setSummaryFor] = useState<User | null>(null)
  const [rightsFor, setRightsFor] = useState<User | null>(null)
  const [planFor, setPlanFor] = useState<User | null>(null)
  const [salesFor, setSalesFor] = useState<User | null>(null)

  const { data: users, isLoading } = useQuery({
    queryKey: ['admin-users', query, page],
    queryFn: () => admin.users({ page, ...(query ? { q: query } : {}) }),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    queryClient.invalidateQueries({ queryKey: ['admin-stats'] })
  }

  const createMutation = useMutation({
    mutationFn: () => admin.createUser(createForm),
    onSuccess: () => {
      invalidate()
      setShowCreate(false)
      setCreateForm({ name: '', email: '', password: '', role: 'user' })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const suspendMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'suspend' | 'activate' }) =>
      action === 'suspend' ? admin.suspend(uuid) : admin.activate(uuid),
    onSuccess: invalidate,
    onError: (err) => alert(errorMessage(err)),
  })

  const regenerateMutation = useMutation({
    mutationFn: (uuid: string) => admin.regenerateAppId(uuid),
    onSuccess: invalidate,
    onError: (err) => alert(errorMessage(err)),
  })

  return (
    <Card>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-semibold">Users</h2>
        <div className="flex flex-1 flex-wrap justify-end gap-2">
          {/* A fixed 224px search box plus its button plus "New user" is wider
              than a phone, and the button that fell off the end was the one
              that does something. */}
          <div className="flex min-w-0 flex-1 gap-1 sm:flex-none">
            <Input
              placeholder="Search name, email, App ID…"
              className="min-w-0 flex-1 sm:w-56"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && (setPage(1), setQuery(search))}
            />
            <Button variant="secondary" onClick={() => { setPage(1); setQuery(search) }}>
              <Search className="size-4" />
            </Button>
          </div>
          <Button onClick={() => setShowCreate(true)}>
            <Plus className="size-4" /> New user
          </Button>
        </div>
      </div>

      {isLoading ? (
        <Spinner />
      ) : !users?.data.length ? (
        <EmptyState title="No users found" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 pr-4 font-medium">Name</th>
                <th className="pb-2 pr-4 font-medium">Email</th>
                <th className="pb-2 pr-4 font-medium">App ID</th>
                <th className="pb-2 pr-4 font-medium">Roles</th>
                <th className="pb-2 pr-4 font-medium">Plan</th>
                <th className="pb-2 pr-4 font-medium">Salesperson</th>
                <th className="pb-2 pr-4 font-medium">Status</th>
                <th className="pb-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.data.map((u) => (
                <tr key={u.uuid} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2.5 pr-4 font-medium">{u.name}</td>
                  <td className="py-2.5 pr-4 text-slate-500">{u.email}</td>
                  <td className="py-2.5 pr-4 font-mono text-xs">{u.app_id}</td>
                  <td className="py-2.5 pr-4">
                    <div className="flex flex-wrap gap-1">{u.roles?.map((r) => <Badge key={r} value={r} />)}</div>
                  </td>
                  <td className="py-2.5 pr-4">
                    <Badge value={u.plan ?? 'free'} />
                  </td>
                  <td className="py-2.5 pr-4">
                    <button
                      className="text-xs text-slate-500 hover:text-brand-600 hover:underline"
                      title="Assign salesperson"
                      onClick={() => setSalesFor(u)}
                    >
                      {u.salesperson?.name ?? '— assign'}
                    </button>
                  </td>
                  <td className="py-2.5 pr-4">
                    <Badge value={u.status ?? 'active'} />
                  </td>
                  <td className="py-2.5">
                    <div className="flex gap-1">
                      <Button size="sm" variant="ghost" title="Activity summary" onClick={() => setSummaryFor(u)}>
                        <BarChart3 className="size-3.5" />
                      </Button>
                      <Button size="sm" variant="ghost" title="Assign plan" onClick={() => setPlanFor(u)}>
                        <CreditCard className="size-3.5" />
                      </Button>
                      {u.roles?.includes('subadmin') && (
                        <Button size="sm" variant="ghost" title="Subadmin rights" onClick={() => setRightsFor(u)}>
                          <SlidersHorizontal className="size-3.5" />
                        </Button>
                      )}
                      {u.uuid !== me?.uuid && (
                        u.status === 'suspended' ? (
                          <Button
                            size="sm"
                            variant="secondary"
                            title="Activate"
                            onClick={() => suspendMutation.mutate({ uuid: u.uuid, action: 'activate' })}
                          >
                            <CheckCircle2 className="size-3.5" />
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="secondary"
                            title="Suspend"
                            onClick={() => {
                              if (confirm(`Suspend ${u.name}? They will be signed out immediately.`)) {
                                suspendMutation.mutate({ uuid: u.uuid, action: 'suspend' })
                              }
                            }}
                          >
                            <Ban className="size-3.5" />
                          </Button>
                        )
                      )}
                      {!u.email_verified && u.email && (
                        <Button
                          size="sm"
                          variant="ghost"
                          title="Mark email verified (use when the OTP mail is not arriving)"
                          onClick={() => {
                            if (confirm(`Mark ${u.email} as verified without a code?`)) {
                              adminCare.verifyEmail(u.uuid).then(invalidate).catch((err) => alert(errorMessage(err)))
                            }
                          }}
                        >
                          <MailCheck className="size-3.5" />
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="ghost"
                        title="View / resend verification code (email or in-app)"
                        onClick={async () => {
                          try {
                            const res = await api.get<{ data: { code: string } | null }>(`/admin/users/${u.uuid}/otp`)
                            if (res.data.data) {
                              alert(`Active OTP for ${u.name}: ${res.data.data.code}`)
                            } else if (confirm(`No active code for ${u.name}. Send a new one? (Goes to their email if unverified, otherwise in-app.)`)) {
                              const sent = await api.post<{ data: { code: string } }>(`/admin/users/${u.uuid}/otp/resend`)
                              alert(`New OTP sent: ${sent.data.data.code}`)
                            }
                          } catch (err) {
                            alert(errorMessage(err))
                          }
                        }}
                      >
                        <KeyRound className="size-3.5" />
                      </Button>
                      {isSuperAdmin && (
                        <Button
                          size="sm"
                          variant="ghost"
                          title="Regenerate App ID"
                          onClick={() => {
                            if (confirm(`Regenerate App ID for ${u.name}? The old ID stops working permanently.`)) {
                              regenerateMutation.mutate(u.uuid)
                            }
                          }}
                        >
                          <RefreshCw className="size-3.5" />
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager resp={users} onPage={setPage} />

      {showCreate && (
        <Modal title="Create user" onClose={() => setShowCreate(false)}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              setError(null)
              createMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Full name</Label>
              <Input value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} required autoFocus />
            </div>
            <div>
              <Label>Email</Label>
              <Input type="email" value={createForm.email} onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })} required />
            </div>
            <div>
              <Label>Password</Label>
              <Input type="password" value={createForm.password} onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })} required />
            </div>
            <div>
              <Label>Role</Label>
              <Select value={createForm.role} onChange={(e) => setCreateForm({ ...createForm, role: e.target.value })}>
                <option value="user">Standard User</option>
                <option value="subadmin">Subadmin</option>
                <option value="salesperson">Salesperson</option>
                {isSuperAdmin && <option value="admin">Admin</option>}
              </Select>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? 'Creating…' : 'Create user'}
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {summaryFor && <UserSummaryModal user={summaryFor} onClose={() => setSummaryFor(null)} />}
      {rightsFor && <RightsModal user={rightsFor} onClose={() => setRightsFor(null)} />}
      {planFor && <AssignPlanModal user={planFor} onClose={() => setPlanFor(null)} />}
      {salesFor && (
        <AssignSalespersonModal
          user={salesFor}
          onClose={() => {
            setSalesFor(null)
            invalidate()
          }}
        />
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <p className="text-2xl font-semibold">{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </Card>
  )
}

function OverviewTab() {
  const queryClient = useQueryClient()
  const { data: stats } = useQuery({ queryKey: ['admin-stats'], queryFn: admin.stats })
  const { data: settings } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () =>
      api.get<{ data: Record<string, string | boolean> }>('/admin/settings').then((r) => r.data.data),
  })
  const [days, setDays] = useState<string>('')

  // Voice assistant AI settings (super admin only; the key is write-only —
  // the server never sends the saved value back).
  const [aiEnabled, setAiEnabled] = useState(false)
  const [aiKey, setAiKey] = useState('')
  const [aiModel, setAiModel] = useState('')
  const [aiBusy, setAiBusy] = useState(false)
  useEffect(() => {
    if (settings) {
      setAiEnabled(settings.voice_ai_enabled === '1')
      setAiModel(String(settings.voice_ai_model ?? 'claude-opus-5'))
    }
  }, [settings])
  const keySaved = settings?.voice_ai_key_saved === true

  const saveVoiceAi = async (extra: Record<string, unknown> = {}) => {
    setAiBusy(true)
    try {
      await api.put('/admin/settings', {
        voice_ai_enabled: aiEnabled,
        voice_ai_model: aiModel.trim() || 'claude-opus-5',
        ...(aiKey.trim() ? { voice_ai_key: aiKey.trim() } : {}),
        ...extra,
      })
      setAiKey('')
      queryClient.invalidateQueries({ queryKey: ['admin-settings'] })
      alert('Saved.')
    } catch (err) {
      alert(errorMessage(err))
    } finally {
      setAiBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      {stats && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
          <Stat label="Total users" value={stats.users.total} />
          <Stat label="Active users" value={stats.users.active} />
          <Stat label="Suspended" value={stats.users.suspended} />
          <Stat label="New this week" value={stats.users.new_this_week} />
          <Stat label="Total tasks" value={stats.tasks.total} />
          <Stat label="Completed tasks" value={stats.tasks.completed} />
          <Stat label="Overdue tasks" value={stats.tasks.overdue} />
        </div>
      )}

      <Card>
        <h2 className="mb-2 text-sm font-semibold">Identity settings</h2>
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <Label>Username change cooldown (days)</Label>
            <Input
              type="number"
              min={0}
              className="w-32"
              value={days || String(settings?.username_change_days ?? '')}
              onChange={(e) => setDays(e.target.value)}
            />
          </div>
          <Button
            size="sm"
            onClick={() =>
              api.put('/admin/settings', { username_change_days: Number(days || settings?.username_change_days || 30) })
                .then(() => alert('Saved.'))
                .catch((err) => alert(errorMessage(err)))
            }
          >
            Save
          </Button>
        </div>
        <p className="mt-1 text-[11px] text-slate-400">
          Mobile and email changes can be requested anytime; usernames only after this many days.
          (Super admin only.)
        </p>
      </Card>

      <Card>
        <h2 className="mb-2 text-sm font-semibold">Voice assistant — AI understanding</h2>
        <p className="mb-3 text-xs text-slate-500">
          The assistant always understands its built-in commands. With AI enabled, phrasings the
          rules miss are interpreted by Claude (Anthropic) — every AI-interpreted command still
          asks the user for confirmation before doing anything.
        </p>
        <div className="space-y-3">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={aiEnabled}
              onChange={(e) => setAiEnabled(e.target.checked)}
            />
            Enable AI understanding
          </label>
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-64 flex-1">
              <Label>Anthropic API key</Label>
              <Input
                type="password"
                autoComplete="new-password"
                placeholder={keySaved ? '•••••••• saved — enter a new key to replace' : 'sk-ant-…'}
                value={aiKey}
                onChange={(e) => setAiKey(e.target.value)}
              />
            </div>
            <div>
              <Label>Model</Label>
              <Select className="w-56" value={aiModel} onChange={(e) => setAiModel(e.target.value)}>
                <option value="claude-opus-5">Claude Opus 5 — best understanding</option>
                <option value="claude-sonnet-5">Claude Sonnet 5 — balanced</option>
                <option value="claude-haiku-4-5">Claude Haiku 4.5 — fastest, cheapest</option>
              </Select>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" onClick={() => saveVoiceAi()} disabled={aiBusy}>
              Save
            </Button>
            {keySaved && (
              <Button
                size="sm"
                variant="secondary"
                disabled={aiBusy}
                onClick={() => {
                  if (confirm('Remove the saved API key and turn AI understanding off?')) {
                    setAiEnabled(false)
                    saveVoiceAi({ voice_ai_key: null, voice_ai_enabled: false })
                  }
                }}
              >
                Clear key
              </Button>
            )}
            <span className="text-[11px] text-slate-400">
              {keySaved ? 'A key is saved on the server.' : 'No key saved yet — get one at console.anthropic.com.'}
            </span>
          </div>
        </div>
        <p className="mt-2 text-[11px] text-slate-400">
          The key is stored server-side and never shown again. Usage is billed by Anthropic
          (a fraction of a cent per interpreted command). (Super admin only.)
        </p>
      </Card>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Internal Work (staff-only notes about users)
// ---------------------------------------------------------------------------

function InternalTab() {
  const queryClient = useQueryClient()
  const me = useAuthStore((s) => s.user)
  const amAdmin = !!me?.roles?.some((r) => r === 'admin' || r === 'super_admin')
  const [selected, setSelected] = useState<{ uuid: string; name: string } | null>(null)
  const [identifier, setIdentifier] = useState('')
  const [body, setBody] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data: threads, isLoading } = useQuery({
    queryKey: ['internal-threads'],
    queryFn: adminInternal.threads,
    refetchInterval: 30_000,
  })

  const { data: thread } = useQuery({
    queryKey: ['internal-notes', selected?.uuid],
    queryFn: () => adminInternal.notes(selected!.uuid),
    enabled: !!selected,
    refetchInterval: 15_000,
  })

  const addMutation = useMutation({
    mutationFn: () => adminInternal.addNote(selected!.uuid, body.trim()),
    onSuccess: () => {
      setBody('')
      queryClient.invalidateQueries({ queryKey: ['internal-notes', selected?.uuid] })
      queryClient.invalidateQueries({ queryKey: ['internal-threads'] })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (noteUuid: string) => adminInternal.deleteNote(noteUuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['internal-notes', selected?.uuid] })
      queryClient.invalidateQueries({ queryKey: ['internal-threads'] })
    },
    onError: (err) => alert(errorMessage(err)),
  })

  const startThread = async () => {
    setError(null)
    try {
      const user = await adminInternal.lookup(identifier.trim())
      setSelected({ uuid: user.uuid, name: user.name })
      setIdentifier('')
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
      <Card className="self-start">
        <h2 className="mb-2 text-sm font-semibold">Discussions</h2>
        <div className="mb-3 flex gap-1">
          <div className="flex-1">
            <UserSuggest
              placeholder="username or email…"
              value={identifier}
              onChange={setIdentifier}
              onEnter={() => identifier.trim() && startThread()}
            />
          </div>
          <Button size="sm" variant="secondary" onClick={startThread} disabled={!identifier.trim()}>
            <Search className="size-4" />
          </Button>
        </div>
        <ErrorNote message={error} />
        {isLoading ? (
          <Spinner />
        ) : !threads?.length ? (
          <EmptyState title="No discussions yet" hint="Look up a user above to start one." />
        ) : (
          <div className="space-y-1">
            {threads.map((t) => (
              <button
                key={t.user.uuid}
                onClick={() => setSelected({ uuid: t.user.uuid, name: t.user.name })}
                className={clsx(
                  'w-full rounded-lg px-3 py-2 text-left text-sm transition-colors',
                  selected?.uuid === t.user.uuid
                    ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                    : 'hover:bg-slate-100 dark:hover:bg-slate-800',
                )}
              >
                <p className="font-medium">{t.user.name}</p>
                <p className="text-[11px] text-slate-400">
                  {t.notes_count} note(s) · {formatDistanceToNow(new Date(t.last_at), { addSuffix: true })}
                </p>
              </button>
            ))}
          </div>
        )}
      </Card>

      <Card className="flex min-h-[400px] flex-col">
        {!selected ? (
          <EmptyState
            title="Internal Work"
            hint="Staff-only discussion about a user — never visible to the user. Pick a thread or look one up."
          />
        ) : (
          <>
            <h2 className="mb-3 text-sm font-semibold">
              About: {thread?.user.name ?? selected.name}
              {thread?.user.username && <span className="ml-1 text-xs font-normal text-slate-400">@{thread.user.username}</span>}
            </h2>
            <div className="flex-1 space-y-2 overflow-y-auto">
              {!thread?.notes.length ? (
                <p className="text-xs text-slate-400">No notes yet — write the first one below.</p>
              ) : (
                thread.notes.map((n) => (
                  <div
                    key={n.uuid}
                    className={clsx(
                      'max-w-[85%] rounded-lg px-3 py-2 text-sm',
                      n.author.is_me
                        ? 'ml-auto bg-brand-600 text-white'
                        : 'bg-slate-100 dark:bg-slate-800',
                    )}
                  >
                    {!n.author.is_me && <p className="mb-0.5 text-[11px] font-semibold text-brand-600 dark:text-brand-400">{n.author.name}</p>}
                    <p className="whitespace-pre-wrap">{n.body}</p>
                    <div className="mt-1 flex items-center justify-between gap-2">
                      <p className={clsx('text-[10px]', n.author.is_me ? 'text-brand-200' : 'text-slate-400')}>
                        {format(new Date(n.created_at), 'd MMM, HH:mm')}
                      </p>
                      {amAdmin && (
                        <button
                          type="button"
                          className={clsx('text-[10px] hover:underline', n.author.is_me ? 'text-brand-200' : 'text-slate-400')}
                          title="Delete note (admin only — notes are otherwise a permanent record)"
                          onClick={() => {
                            if (confirm('Delete this internal note?')) deleteMutation.mutate(n.uuid)
                          }}
                        >
                          Delete
                        </button>
                      )}
                    </div>
                  </div>
                ))
              )}
            </div>
            <form
              className="mt-3 flex gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                if (body.trim()) addMutation.mutate()
              }}
            >
              <Input
                placeholder="Write an internal note…"
                value={body}
                onChange={(e) => setBody(e.target.value)}
              />
              <Button type="submit" disabled={!body.trim() || addMutation.isPending}>
                <Send className="size-4" />
              </Button>
            </form>
          </>
        )}
      </Card>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Salesperson assignment + workspace
// ---------------------------------------------------------------------------

function AssignSalespersonModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { data: salespeople } = useQuery({ queryKey: ['salespeople'], queryFn: adminCare.salespeople })
  const [choice, setChoice] = useState(user.salesperson?.uuid ?? '')
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    try {
      const res = await adminCare.assignSalesperson(user.uuid, choice || null)
      alert(res.message)
      onClose()
    } catch (err) {
      alert(errorMessage(err))
      setBusy(false)
    }
  }

  return (
    <Modal title={`Salesperson for ${user.name}`} onClose={onClose}>
      <div className="space-y-4">
        <div>
          <Label>Assigned salesperson</Label>
          <Select value={choice} onChange={(e) => setChoice(e.target.value)}>
            <option value="">— None —</option>
            {salespeople?.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.name}
              </option>
            ))}
          </Select>
          {!salespeople?.length && (
            <p className="mt-1 text-xs text-slate-400">
              No salesperson accounts yet — create one from the New user form (role: Salesperson).
            </p>
          )}
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save} disabled={busy}>
            {busy ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}

function SalesTab() {
  const { data: users, isLoading } = useQuery({
    queryKey: ['sales-my-users'],
    queryFn: () => adminSales.myUsers(),
  })
  const [summaryFor, setSummaryFor] = useState<User | null>(null)

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">My users</h2>
      <p className="mb-3 text-xs text-slate-400">
        Users assigned to you. Open a summary to see their activity and subscription.
      </p>
      {isLoading ? (
        <Spinner />
      ) : !users?.data.length ? (
        <EmptyState title="No users assigned to you yet" hint="An admin assigns users from the Users tab." />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                <th className="pb-2 pr-4 font-medium">Name</th>
                <th className="pb-2 pr-4 font-medium">Username</th>
                <th className="pb-2 pr-4 font-medium">Email</th>
                <th className="pb-2 pr-4 font-medium">Plan</th>
                <th className="pb-2 font-medium">Activity</th>
              </tr>
            </thead>
            <tbody>
              {users.data.map((u) => (
                <tr key={u.uuid} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                  <td className="py-2.5 pr-4 font-medium">{u.name}</td>
                  <td className="py-2.5 pr-4 text-slate-500">{u.username ? `@${u.username}` : '—'}</td>
                  <td className="py-2.5 pr-4 text-slate-500">{u.email ?? '—'}</td>
                  <td className="py-2.5 pr-4">
                    <Badge value={u.plan ?? 'free'} />
                  </td>
                  <td className="py-2.5">
                    <Button size="sm" variant="ghost" title="Activity summary" onClick={() => setSummaryFor(u)}>
                      <BarChart3 className="size-3.5" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {summaryFor && <SalesSummaryModal user={summaryFor} onClose={() => setSummaryFor(null)} />}
    </Card>
  )
}

function SalesSummaryModal({ user, onClose }: { user: User; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['sales-user-summary', user.uuid],
    queryFn: () => adminSales.summary(user.uuid),
  })

  return (
    <Modal title={`Activity — ${user.name}`} onClose={onClose}>
      {isLoading || !data ? (
        <Spinner />
      ) : (
        <div className="space-y-2 text-sm">
          <p>
            <span className="text-slate-400">Plan:</span> <Badge value={data.plan} />
          </p>
          <p>
            <span className="text-slate-400">Member since:</span>{' '}
            {format(new Date(data.member_since), 'd MMM yyyy')}
          </p>
          <p>
            <span className="text-slate-400">Last login:</span>{' '}
            {data.last_login ? `${formatDistanceToNow(new Date(data.last_login.at), { addSuffix: true })} (${data.last_login.ip})` : 'never'}
          </p>
          <p>
            <span className="text-slate-400">Logins this week:</span> {data.logins_this_week}
          </p>
          <p>
            <span className="text-slate-400">Tasks:</span> {data.tasks.completed}/{data.tasks.total} completed,{' '}
            {data.tasks.created_this_week} new this week
          </p>
          <p>
            <span className="text-slate-400">Notes:</span> {data.notes} ·{' '}
            <span className="text-slate-400">Files:</span> {data.files.count} ·{' '}
            <span className="text-slate-400">Messages sent:</span> {data.messages_sent}
          </p>
          <p>
            <span className="text-slate-400">Calls:</span> {data.calls?.total ?? 0} total ·{' '}
            {data.calls?.this_week ?? 0} this week · {data.calls?.missed ?? 0} missed ·{' '}
            {data.calls?.minutes ?? 0} min talk time
          </p>
        </div>
      )}
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Page shell with tabs
// ---------------------------------------------------------------------------

/**
 * What is running right now, and the ability to stop it.
 *
 * "Live" is the presence heartbeat, not merely a meeting flagged active — a
 * room everyone closed their browser on drops off by itself.
 */
function LiveMeetingsTab() {
  const { toast, toastError } = useToast()
  const qc = useQueryClient()
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'live-meetings'],
    queryFn: () => admin.liveMeetings(),
    // Short, because the whole point of the page is that it is current.
    refetchInterval: 10_000,
  })

  const end = useMutation({
    mutationFn: ({ code, reason }: { code: string; reason?: string }) => admin.endMeeting(code, reason),
    onSuccess: (res) => {
      toast(res.message, 'success')
      qc.invalidateQueries({ queryKey: ['admin', 'live-meetings'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorNote message={errorMessage(error)} />

  const rows = data?.data ?? []

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-3">
        <Card className="flex-1 p-4">
          <p className="text-xs uppercase tracking-wide text-slate-400">Meetings live now</p>
          <p className="text-2xl font-semibold">{data?.meta.live_meetings ?? 0}</p>
        </Card>
        <Card className="flex-1 p-4">
          <p className="text-xs uppercase tracking-wide text-slate-400">People in meetings</p>
          <p className="text-2xl font-semibold">{data?.meta.people_in_meetings ?? 0}</p>
        </Card>
      </div>

      {rows.length === 0 ? (
        <Card>
          <EmptyState title="Nothing is running" hint="Meetings appear here while people are actually in them." />
        </Card>
      ) : (
        <div className="space-y-2">
          {rows.map((m) => (
            <Card key={m.uuid} className="flex flex-wrap items-center gap-3 p-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">
                  {m.title || 'Untitled meeting'}
                  <span className="ml-2 font-mono text-xs text-slate-400">{m.code}</span>
                  {m.is_locked && <span className="ml-2 text-[11px] text-amber-500">locked</span>}
                </p>
                <p className="truncate text-xs text-slate-400">
                  Host {m.host?.name ?? 'unknown'} · running {m.running_minutes} min ·{' '}
                  {m.participants} in the room
                </p>
                <p className="truncate text-[11px] text-slate-400">{m.participant_names.join(', ')}</p>
              </div>
              <Button
                size="sm"
                variant="danger"
                disabled={end.isPending}
                onClick={() => {
                  // Ending somebody else's meeting throws everyone out, so it
                  // asks first and records why.
                  if (!confirm(`End “${m.title || m.code}” for all ${m.participants} people?`)) return
                  const reason = prompt('Reason (recorded in the audit log):') ?? undefined
                  end.mutate({ code: m.code, reason })
                }}
              >
                End for all
              </Button>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

/**
 * What is breaking in people's browsers.
 *
 * There was no error tracking of any kind, so a white screen produced no
 * signal at all. One row per fault rather than per occurrence — the same bug
 * hitting two hundred people is one line with a count of two hundred.
 */
function ClientErrorsTab() {
  const queryClient = useQueryClient()
  const [resolved, setResolved] = useState(false)
  const [open, setOpen] = useState<number | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['client-errors', resolved],
    queryFn: () => adminOps.clientErrors(resolved),
    refetchInterval: 60_000,
  })

  const toggle = (id: number) =>
    adminOps.resolveClientError(id).then(() => queryClient.invalidateQueries({ queryKey: ['client-errors'] }))

  return (
    <Card>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">{resolved ? 'Fixed' : 'Open'} errors</h2>
        <Button size="sm" variant="secondary" onClick={() => setResolved((r) => !r)}>
          Show {resolved ? 'open' : 'fixed'}
        </Button>
      </div>

      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <EmptyState
          title={resolved ? 'Nothing marked fixed yet' : 'Nothing is broken'}
          hint={resolved ? undefined : 'Errors in anyone’s browser show up here within a minute.'}
        />
      ) : (
        <div className="space-y-2">
          {data.data.map((e) => (
            <div key={e.id} className="rounded-xl p-3 ring-1 ring-slate-900/5 dark:ring-white/10">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <button className="min-w-0 flex-1 text-left" onClick={() => setOpen(open === e.id ? null : e.id)}>
                  <p className="break-words text-sm font-medium">{e.message}</p>
                  <p className="mt-0.5 text-xs text-slate-400">
                    {e.hits} time{e.hits === 1 ? '' : 's'} · last {new Date(e.last_seen_at).toLocaleString()}
                    {e.url ? ` · ${e.url}` : ''}
                    {e.last_user ? ` · ${e.last_user}` : ''}
                  </p>
                </button>
                <Button size="sm" variant={e.resolved_at ? 'secondary' : 'primary'} onClick={() => toggle(e.id)}>
                  {e.resolved_at ? 'Reopen' : 'Mark fixed'}
                </Button>
              </div>
              {open === e.id && e.stack && (
                <pre className="scroll-pane mt-2 max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-200">
                  {e.stack}
                </pre>
              )}
              {open === e.id && e.last_agent && (
                <p className="mt-1 break-words text-[11px] text-slate-400">{e.last_agent}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}

const TABS = [
  { key: 'overview', label: 'Overview', icon: Shield },
  { key: 'users', label: 'Users', icon: Users },
  { key: 'active', label: 'Active Members', icon: Wifi },
  { key: 'live', label: 'Live Meetings', icon: Radio },
  { key: 'plans', label: 'Plans', icon: CreditCard },
  { key: 'approvals', label: 'Approvals', icon: ClipboardCheck },
  { key: 'activity', label: 'Activity', icon: Activity },
  { key: 'logins', label: 'Logins', icon: LogIn },
  { key: 'errors', label: 'Errors', icon: Bug },
  { key: 'moderation', label: 'Moderation', icon: Flag },
  { key: 'internal', label: 'Internal Work', icon: MessagesSquare },
  { key: 'sales', label: 'My Users', icon: UserCheck },
] as const

/** Which tabs each staff role can see (the backend enforces the same rules). */
function visibleTabs(roles: string[]) {
  if (roles.includes('admin') || roles.includes('super_admin')) return TABS
  if (roles.includes('subadmin')) {
    return TABS.filter((t) => !['overview', 'active', 'plans', 'live', 'errors'].includes(t.key))
  }
  return TABS.filter((t) => ['sales', 'internal'].includes(t.key))
}

export default function AdminPage() {
  const roles = useAuthStore((s) => s.user?.roles ?? [])
  const tabs = visibleTabs(roles)
  // "?tab=users" deep links (used by the voice assistant) land on that tab,
  // as long as the current role is allowed to see it.
  const [params] = useSearchParams()
  const [tab, setTab] = useState<(typeof TABS)[number]['key']>(() => {
    const wanted = params.get('tab')
    return tabs.find((t) => t.key === wanted)?.key ?? tabs[0]?.key ?? 'internal'
  })

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Shield className="size-5 text-brand-600" />
        <h1 className="text-xl font-semibold tracking-tight">Admin Panel</h1>
      </div>

      {/* Eleven tabs wrap onto four rows of a phone — a third of the screen
          gone before any panel is shown. One scrolling row instead, as every
          app with more tabs than fit does. */}
      <div className="scroll-pane -mx-3 flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pb-2 dark:border-slate-800 sm:mx-0 sm:flex-wrap sm:px-0">
        {tabs.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={clsx(
              'flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm transition-colors',
              tab === key
                ? 'bg-brand-600 text-white'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
            )}
          >
            <Icon className="size-3.5" /> {label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <OverviewTab />}
      {tab === 'users' && <UsersTab />}
      {tab === 'active' && <ActiveMembersTab />}
      {tab === 'live' && <LiveMeetingsTab />}
      {tab === 'plans' && <PlansTab />}
      {tab === 'approvals' && <ApprovalsTab />}
      {tab === 'activity' && <ActivityTab />}
      {tab === 'logins' && <LoginsTab />}
      {tab === 'errors' && <ClientErrorsTab />}
      {tab === 'moderation' && <ModerationTab />}
      {tab === 'internal' && <InternalTab />}
      {tab === 'sales' && <SalesTab />}
    </div>
  )
}
