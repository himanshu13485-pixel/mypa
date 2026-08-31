import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'

const ROLE_LABELS: Record<string, string> = { admin: 'Admin', subadmin: 'Subadmin', employee: 'Employee' }

export default function CrmEmployeesPage() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [role, setRole] = useState('')
  const [reportsTo, setReportsTo] = useState('')
  const [status, setStatus] = useState('active')
  const [page, setPage] = useState(1)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  // Registering staff is company authority, not a grantable right.
  const manages = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'employees', applied, role, status, reportsTo, page],
    queryFn: () => crm.employees.list({ search: applied || undefined, crm_role: role || undefined, status: status || undefined, reports_to: reportsTo || undefined, page }),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Employees</h1>
          <p className="text-sm text-slate-500">Admins, subadmins and employees of the organization.</p>
        </div>
        {manages && (
          <Button onClick={() => navigate('/crm/employees/new')}>
            <Plus className="size-4" /> Register employee
          </Button>
        )}
      </div>

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setPage(1)
            setApplied(search)
          }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, email, code, department…" className="w-full pl-9" />
          </div>
          <Select value={role} onChange={(e) => { setRole(e.target.value); setPage(1) }}>
            <option value="">All roles</option>
            <option value="admin">Admin</option>
            <option value="subadmin">Subadmin</option>
            <option value="employee">Employee</option>
          </Select>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="">All</option>
          </Select>
          <Select value={reportsTo} onChange={(e) => { setReportsTo(e.target.value); setPage(1) }} title="Show one team leader's people (Team Workspace or org chart)">
            <option value="">Any team</option>
            {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>Under {m.name}</option>)}
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No employees found" hint="Register the first employee to build the team." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Employee</th>
                  <th className="py-2 pr-3 font-medium">Code</th>
                  <th className="py-2 pr-3 font-medium">Role</th>
                  <th className="py-2 pr-3 font-medium">Department</th>
                  <th className="py-2 pr-3 font-medium">Designation</th>
                  <th className="py-2 pr-3 font-medium">Team leader</th>
                  <th className="py-2 pr-3 font-medium">Joined</th>
                  <th className="py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((m) => (
                  <tr key={m.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="py-2.5 pr-3">
                      <Link to={`/crm/employees/${m.uuid}`} className="font-medium text-emerald-600 hover:underline">
                        {m.name ?? '—'}
                      </Link>
                      <div className="text-xs text-slate-400">{m.email}</div>
                    </td>
                    <td className="py-2.5 pr-3">{m.employee_code ?? '—'}</td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        m.crm_role === 'admin' ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300'
                          : m.crm_role === 'subadmin' ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
                            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                      )}>
                        {ROLE_LABELS[m.crm_role]}
                        {m.is_salesperson && ' · Sales'}
                      </span>
                    </td>
                    <td className="py-2.5 pr-3">{m.department ?? '—'}</td>
                    <td className="py-2.5 pr-3">{m.designation ?? '—'}</td>
                    {/* Team Workspace leaders first; the org-chart manager
                        (everyone's default: the Admin) as the fallback. */}
                    <td className="py-2.5 pr-3">
                      {(m.team_leaders?.length ?? 0) > 0
                        ? m.team_leaders!.map((l) => l.name).filter(Boolean).join(', ')
                        : m.manager?.name ?? '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{m.joined_at ?? '—'}</td>
                    <td className="py-2.5">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        m.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                      )}>
                        {m.status === 'active' ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}
