import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle2, ClipboardCheck, KeyRound, Plus, RefreshCw, Search, Shield, Users } from 'lucide-react'
import { admin, identity as identityApi } from '../../api/endpoints'
import { api } from '../../api/client'
import { errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner,
} from '../../components/ui'

function ApprovalsCard() {
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
      <div className="mb-3 flex items-center gap-2">
        <ClipboardCheck className="size-4 text-slate-400" />
        <h2 className="text-sm font-semibold">Identity change approvals</h2>
        {data?.data.length ? (
          <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
            {data.data.length} pending
          </span>
        ) : null}
      </div>
      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <p className="text-xs text-slate-400">No pending requests.</p>
      ) : (
        <div className="space-y-2">
          {data.data.map((r) => (
            <div key={r.uuid} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">
              <div className="text-sm">
                <p className="font-medium">{r.user?.name} <span className="text-xs text-slate-400">({r.user?.username ?? r.user?.email ?? r.user?.mobile})</span></p>
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

function SettingsCard() {
  const { data } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => api.get<{ data: Record<string, string> }>('/admin/settings').then((r) => r.data.data),
  })
  const [days, setDays] = useState<string>('')

  return (
    <Card>
      <h2 className="mb-2 text-sm font-semibold">Identity settings</h2>
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <Label>Username change cooldown (days)</Label>
          <Input
            type="number"
            min={0}
            className="w-32"
            value={days || (data?.username_change_days ?? '')}
            onChange={(e) => setDays(e.target.value)}
          />
        </div>
        <Button
          size="sm"
          onClick={() =>
            api.put('/admin/settings', { username_change_days: Number(days || data?.username_change_days || 30) })
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
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <p className="text-2xl font-semibold">{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </Card>
  )
}

export default function AdminPage() {
  const queryClient = useQueryClient()
  const me = useAuthStore((s) => s.user)
  const isSuperAdmin = !!me?.roles?.includes('super_admin')

  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [createForm, setCreateForm] = useState({ name: '', email: '', password: '', role: 'user' })
  const [error, setError] = useState<string | null>(null)

  const { data: stats } = useQuery({ queryKey: ['admin-stats'], queryFn: admin.stats })
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
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <Shield className="size-5 text-brand-600" />
        <h1 className="text-lg font-semibold">Admin Panel</h1>
      </div>

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

      <ApprovalsCard />
      <SettingsCard />

      <Card>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Users className="size-4 text-slate-400" />
            <h2 className="text-sm font-semibold">Users</h2>
          </div>
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
            <table className="w-full min-w-[720px] text-left text-sm">
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
                      <div className="flex flex-wrap gap-1">
                        {u.roles?.map((r) => <Badge key={r} value={r} />)}
                      </div>
                    </td>
                    <td className="py-2.5 pr-4">
                      <Badge value={u.status ?? 'active'} />
                    </td>
                    <td className="py-2.5">
                      <div className="flex gap-1">
                        {u.uuid !== me?.uuid && (
                          u.status === 'suspended' ? (
                            <Button
                              size="sm"
                              variant="secondary"
                              title="Activate"
                              onClick={() => suspendMutation.mutate({ uuid: u.uuid, action: 'activate' })}
                            >
                              <CheckCircle2 className="size-3.5" /> Activate
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
                              <Ban className="size-3.5" /> Suspend
                            </Button>
                          )
                        )}
                        <Button
                          size="sm"
                          variant="ghost"
                          title="View / resend mobile OTP"
                          onClick={async () => {
                            try {
                              const res = await api.get<{ data: { code: string; expires_at: string } | null }>(`/admin/users/${u.uuid}/otp`)
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
      </Card>

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
    </div>
  )
}
