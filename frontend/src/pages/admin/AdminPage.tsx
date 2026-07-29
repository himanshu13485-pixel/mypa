import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle2, Plus, RefreshCw, Search, Shield, Users } from 'lucide-react'
import { admin } from '../../api/endpoints'
import { errorMessage } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner,
} from '../../components/ui'

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
