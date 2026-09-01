import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, LogIn, Pencil, Plus, Trash2, Users } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, getCrmOrg, setCrmOrg, type CrmOrganizationRow } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Spinner } from '../../components/ui'

/**
 * Super Admin only: the CRM addon's on switch. Each organization is an
 * isolated CRM instance with its own admin, employees and billing.
 */
export default function CrmOrganizationsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  /*
   * Being on this screen means acting as the Super Admin, so any company
   * hat still on from an earlier "Open" comes off here. That keeps the two
   * contexts from ever overlapping, however you arrived.
   */
  useEffect(() => {
    if (getCrmOrg()) {
      setCrmOrg(null)
      queryClient.invalidateQueries({ queryKey: ['crm'] })
    }
  }, [queryClient])

  const enterMutation = useMutation({
    mutationFn: (uuid: string) => crm.organizations.enter(uuid),
    onSuccess: (res) => {
      // Put on that company's hat, forget everything cached under the old
      // one, and land on its dashboard as admin.
      setCrmOrg(res.data.organization_uuid)
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      toast(res.message, 'success')
      navigate('/crm')
    },
    onError: (err) => toastError(errorMessage(err)),
  })
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmOrganizationRow | null>(null)
  const [deleting, setDeleting] = useState<CrmOrganizationRow | null>(null)
  const [viewing, setViewing] = useState<CrmOrganizationRow | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState({ name: '', code: '', admin_name: '', admin_email: '', admin_password: '' })

  const { data: orgs, isLoading } = useQuery({ queryKey: ['crm', 'organizations'], queryFn: crm.organizations.list })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'organizations'] })

  const createMutation = useMutation({
    mutationFn: () =>
      crm.organizations.create({
        name: form.name,
        code: form.code || null,
        admin_name: form.admin_name,
        admin_email: form.admin_email,
        admin_password: form.admin_password || null,
      }),
    onSuccess: (res: { message?: string }) => {
      refresh()
      queryClient.invalidateQueries({ queryKey: ['crm', 'me'] })
      setShowForm(false)
      setForm({ name: '', code: '', admin_name: '', admin_email: '', admin_password: '' })
      toast(res.message ?? 'Organization created.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const toggleMutation = useMutation({
    mutationFn: ({ uuid, status }: { uuid: string; status: string }) => crm.organizations.update(uuid, { status }),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">CRM organizations</h1>
          <p className="text-sm text-slate-500">The addon switch — each organization is a separate CRM with its own admin.</p>
        </div>
        <Button onClick={() => { setError(null); setShowForm(true) }}>
          <Plus className="size-4" /> Enable CRM for a company
        </Button>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !orgs || orgs.length === 0 ? (
          <EmptyState title="No organizations yet" hint="Enable the CRM for the first company to get started." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Organization</th>
                  <th className="py-2 pr-3 font-medium">Code</th>
                  <th className="py-2 pr-3 font-medium">Admins</th>
                  <th className="py-2 pr-3 font-medium">Members</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {orgs.map((o) => (
                  <tr key={o.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2.5 pr-3">
                      <div className="flex items-center gap-2 font-medium text-slate-800 dark:text-slate-100">
                        <Building2 className="size-4 text-emerald-500" /> {o.name}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3 font-mono text-xs">{o.code}</td>
                    <td className="max-w-[220px] truncate py-2.5 pr-3 text-slate-500">
                      {o.admins.map((a) => a.name).filter(Boolean).join(', ') || '—'}
                    </td>
                    <td className="py-2.5 pr-3">{o.active_members}/{o.members}</td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        o.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                      )}>
                        {o.status === 'active' ? 'Active' : 'Suspended'}
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      <div className="flex justify-end gap-1">
                        <Button size="sm" onClick={() => enterMutation.mutate(o.uuid)} disabled={enterMutation.isPending}>
                          <LogIn className="size-3.5" /> Open
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => setViewing(o)}>
                          <Users className="size-3.5" /> Employees
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => setEditing(o)}>
                          <Pencil className="size-3.5" /> Edit
                        </Button>
                        <Button
                          size="sm"
                          variant={o.status === 'active' ? 'secondary' : 'primary'}
                          onClick={() => toggleMutation.mutate({ uuid: o.uuid, status: o.status === 'active' ? 'suspended' : 'active' })}
                        >
                          {o.status === 'active' ? 'Suspend' : 'Activate'}
                        </Button>
                        {/* Only a suspended company can be deleted: taking a
                            live CRM off the air is its own decision, made
                            first. */}
                        {o.status !== 'active' && (
                          <Button size="sm" variant="danger" onClick={() => setDeleting(o)}>
                            <Trash2 className="size-3.5" /> Delete
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

      {showForm && (
        <Modal title="Enable CRM for a company" onClose={() => setShowForm(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>Company name</Label>
              <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Acme Pvt Ltd" className="w-full" />
            </div>
            <div>
              <Label>Short code (optional)</Label>
              <Input value={form.code} onChange={(e) => set('code', e.target.value)} placeholder="ACME" className="w-full" />
            </div>
            <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
              <p className="mb-2 text-xs text-slate-400">
                The first CRM admin. An existing Netvork account with this email is linked; otherwise a new account is created with the password below.
              </p>
              <div className="space-y-3">
                <div>
                  <Label>Admin name</Label>
                  <Input value={form.admin_name} onChange={(e) => set('admin_name', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>Admin email</Label>
                  <Input type="email" value={form.admin_email} onChange={(e) => set('admin_email', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>Password (only for a new account)</Label>
                  <Input type="password" value={form.admin_password} onChange={(e) => set('admin_password', e.target.value)} placeholder="Min 8, letters + numbers" className="w-full" />
                </div>
              </div>
            </div>
            <Button
              className="w-full"
              disabled={!form.name || !form.admin_name || !form.admin_email || createMutation.isPending}
              onClick={() => createMutation.mutate()}
            >
              {createMutation.isPending ? 'Enabling…' : 'Enable CRM'}
            </Button>
          </div>
        </Modal>
      )}

      {editing && (
        <EditOrgModal org={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); refresh() }} />
      )}
      {deleting && (
        <DeleteOrgModal org={deleting} onClose={() => setDeleting(null)} onDone={() => { setDeleting(null); refresh() }} />
      )}
      {viewing && <OrgMembersModal org={viewing} onClose={() => setViewing(null)} />}
    </div>
  )
}

/** The Super Admin's read-only window into any company's whole team. */
function OrgMembersModal({ org, onClose }: { org: CrmOrganizationRow; onClose: () => void }) {
  const [roleFilter, setRoleFilter] = useState('')
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'org-members', org.uuid],
    queryFn: () => crm.organizations.members(org.uuid),
  })

  const rows = (data?.members ?? []).filter((m) => !roleFilter || m.crm_role === roleFilter)

  return (
    <Modal title={`${org.name} — employees`} onClose={onClose} wide>
      {isLoading ? (
        <div className="flex justify-center py-10"><Spinner /></div>
      ) : (
        <div className="space-y-3">
          <select
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value)}
            className="rounded-xl bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
          >
            <option value="">All roles ({data?.members.length ?? 0})</option>
            <option value="admin">Admins</option>
            <option value="subadmin">Subadmins</option>
            <option value="employee">Employees</option>
          </select>
          <div className="max-h-96 overflow-y-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Employee</th>
                  <th className="py-2 pr-3 font-medium">Role</th>
                  <th className="py-2 pr-3 font-medium">Reports to</th>
                  <th className="py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((m, i) => (
                  <tr key={i} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2 pr-3">
                      <div className="font-medium text-slate-800 dark:text-slate-100">{m.name}</div>
                      <div className="text-xs text-slate-400">{m.email}{m.employee_code && ` · ${m.employee_code}`}</div>
                    </td>
                    <td className="py-2 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium capitalize',
                        m.crm_role === 'admin' ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300'
                          : m.crm_role === 'subadmin' ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
                            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                      )}>
                        {m.crm_role}
                      </span>
                    </td>
                    <td className="py-2 pr-3">{m.reports_to ?? '—'}</td>
                    <td className="py-2">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        m.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                      )}>
                        {m.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Modal>
  )
}

/**
 * Deleting a company's CRM. The code has to be typed back before the button
 * works: it makes the row being deleted the row that was read, which is the
 * whole point when three companies in a list share most of a name.
 */
function DeleteOrgModal({ org, onClose, onDone }: { org: CrmOrganizationRow; onClose: () => void; onDone: () => void }) {
  const { toast, toastError } = useToast()
  const [confirm, setConfirm] = useState('')

  const removeMutation = useMutation({
    mutationFn: () => crm.organizations.remove(org.uuid, confirm),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={`Delete ${org.name}`} onClose={onClose}>
      <div className="space-y-3">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          This deletes the company's whole CRM — its {org.members} member{org.members === 1 ? '' : 's'},
          and every lead, client, invoice, payment and payroll record filed under it. It cannot be undone.
        </p>
        <p className="text-sm text-slate-500">
          The Netvork accounts themselves are not touched: people keep their logins and their personal
          workspace, they simply stop being employees of this company.
        </p>
        <div>
          <Label>Type the code <span className="font-mono text-slate-700 dark:text-slate-200">{org.code}</span> to confirm</Label>
          <Input value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder={org.code} className="w-full" />
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button
            variant="danger"
            disabled={confirm.trim() !== org.code || removeMutation.isPending}
            onClick={() => removeMutation.mutate()}
          >
            {removeMutation.isPending ? 'Deleting…' : 'Delete for good'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}

function EditOrgModal({ org, onClose, onDone }: { org: CrmOrganizationRow; onClose: () => void; onDone: () => void }) {
  const { toast } = useToast()
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState(org.name)
  const [code, setCode] = useState(org.code)
  const [adminEmail, setAdminEmail] = useState(org.admins[0]?.email ?? '')
  const [newPassword, setNewPassword] = useState('')

  const mutation = useMutation({
    mutationFn: () =>
      crm.organizations.update(org.uuid, {
        name,
        code,
        ...(newPassword ? { admin_email: adminEmail, admin_password: newPassword } : {}),
      }),
    onSuccess: (res: { message?: string }) => { toast(res.message ?? 'Organization updated.', 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Edit ${org.name}`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <div>
          <Label>Company name</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Short code</Label>
          <Input value={code} onChange={(e) => setCode(e.target.value)} className="w-full" />
          <p className="mt-1 text-xs text-slate-400">Letters, numbers, dashes — must stay unique across organizations.</p>
        </div>

        <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">Admin account</h3>
          {org.admins.length === 0 ? (
            <p className="mt-1 text-sm text-red-500">No CRM admin — add one from the Users screen inside the organization.</p>
          ) : (
            <>
              <div className="mt-2">
                <Label>Registered email</Label>
                {org.admins.length === 1 ? (
                  <p className="rounded-xl bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    {org.admins[0].email}
                    <span className="ml-2 font-normal text-slate-400">{org.admins[0].name}</span>
                  </p>
                ) : (
                  <select
                    value={adminEmail}
                    onChange={(e) => setAdminEmail(e.target.value)}
                    className="w-full rounded-xl bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                  >
                    {org.admins.map((a) => (
                      <option key={a.email ?? ''} value={a.email ?? ''}>{a.email} ({a.name})</option>
                    ))}
                  </select>
                )}
              </div>
              <div className="mt-2">
                <Label>Set new password (optional)</Label>
                <Input
                  type="password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="Min 8, letters + numbers — leave blank to keep current"
                  className="w-full"
                />
                <p className="mt-1 text-xs text-slate-400">Resets this admin's Netvork login password immediately.</p>
              </div>
            </>
          )}
        </div>

        <Button className="w-full" disabled={!name || !code || mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save changes'}
        </Button>
      </div>
    </Modal>
  )
}
