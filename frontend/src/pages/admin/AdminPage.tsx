import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity, Ban, BarChart3, CheckCircle2, ClipboardCheck, CreditCard, Flag,
  KeyRound, LogIn, Pencil, Plus, RefreshCw, Search, Shield, SlidersHorizontal,
  Users, Wifi,
} from 'lucide-react'
import { format, formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { admin, adminBilling, adminOps, identity as identityApi } from '../../api/endpoints'
import type { AdminPlan } from '../../types'
import { api, errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner,
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
  const { data, isLoading } = useQuery({ queryKey: ['admin-audit-logs'], queryFn: () => adminOps.auditLogs() })

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
    </Card>
  )
}

function LoginsTab() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const { data, isLoading } = useQuery({
    queryKey: ['admin-login-histories', query],
    queryFn: () => adminOps.loginHistories(query || undefined),
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
            onKeyDown={(e) => e.key === 'Enter' && setQuery(search)}
          />
          <Button variant="secondary" size="sm" onClick={() => setQuery(search)}>
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
    </Card>
  )
}

// ---------------------------------------------------------------------------
// Moderation
// ---------------------------------------------------------------------------

function ModerationTab() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('open')
  const { data, isLoading } = useQuery({
    queryKey: ['admin-reports', status],
    queryFn: () => adminOps.reports(status),
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
    </Modal>
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

  const { data: users, isLoading } = useQuery({
    queryKey: ['admin-users', query],
    queryFn: () => admin.users(query ? { q: query } : {}),
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
        <div className="flex gap-2">
          <div className="flex gap-1">
            <Input
              placeholder="Search name, email, App ID…"
              className="w-56"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && setQuery(search)}
            />
            <Button variant="secondary" onClick={() => setQuery(search)}>
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
                      <Button
                        size="sm"
                        variant="ghost"
                        title="View / resend mobile OTP"
                        onClick={async () => {
                          try {
                            const res = await api.get<{ data: { code: string } | null }>(`/admin/users/${u.uuid}/otp`)
                            if (res.data.data) {
                              alert(`Active OTP for ${u.name}: ${res.data.data.code}`)
                            } else if (confirm(`No active code for ${u.name}. Send a new one to their app?`)) {
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
  const { data: stats } = useQuery({ queryKey: ['admin-stats'], queryFn: admin.stats })
  const { data: settings } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => api.get<{ data: Record<string, string> }>('/admin/settings').then((r) => r.data.data),
  })
  const [days, setDays] = useState<string>('')

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
              value={days || (settings?.username_change_days ?? '')}
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
    </div>
  )
}

// ---------------------------------------------------------------------------
// Page shell with tabs
// ---------------------------------------------------------------------------

const TABS = [
  { key: 'overview', label: 'Overview', icon: Shield },
  { key: 'users', label: 'Users', icon: Users },
  { key: 'active', label: 'Active Members', icon: Wifi },
  { key: 'plans', label: 'Plans', icon: CreditCard },
  { key: 'approvals', label: 'Approvals', icon: ClipboardCheck },
  { key: 'activity', label: 'Activity', icon: Activity },
  { key: 'logins', label: 'Logins', icon: LogIn },
  { key: 'moderation', label: 'Moderation', icon: Flag },
] as const

export default function AdminPage() {
  const [tab, setTab] = useState<(typeof TABS)[number]['key']>('overview')

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Shield className="size-5 text-brand-600" />
        <h1 className="text-lg font-semibold">Admin Panel</h1>
      </div>

      <div className="flex flex-wrap gap-1 border-b border-slate-200 pb-2 dark:border-slate-800">
        {TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={clsx(
              'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors',
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
      {tab === 'plans' && <PlansTab />}
      {tab === 'approvals' && <ApprovalsTab />}
      {tab === 'activity' && <ActivityTab />}
      {tab === 'logins' && <LoginsTab />}
      {tab === 'moderation' && <ModerationTab />}
    </div>
  )
}
