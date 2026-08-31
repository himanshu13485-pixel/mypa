import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, ColumnChart } from './charts'

const ACTION_GROUPS = [
  ['employee', 'Employees'], ['client', 'Clients'], ['lead', 'Leads'],
  ['proforma', 'Proforma'], ['invoice', 'Invoices'], ['payment', 'Payments'],
  ['dcw', 'Workspace fields'],
] as const

export default function CrmUserLogPage() {
  const [member, setMember] = useState('')
  const [action, setAction] = useState('')
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'user-log', member, action, applied, dateFrom, dateTo, page],
    queryFn: () =>
      crm.reports.userLog({
        member: member || undefined,
        action: action || undefined,
        search: applied || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  const describe = (entry: { action: string; changes: Record<string, unknown> | null }) => {
    const c = entry.changes ?? {}
    const bits: string[] = []
    for (const key of ['label', 'entity', 'type', 'number', 'lead_no', 'company_name', 'client', 'amount', 'requested_by', 'reason', 'note']) {
      if (c[key] !== undefined && c[key] !== null && c[key] !== '') {
        bits.push(`${key.replace(/_/g, ' ')}: ${String(c[key])}`)
      }
    }
    return bits.join(' · ')
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">User log</h1>
        <p className="text-sm text-slate-500">Everything anyone did, across every module.</p>
      </div>

      {data && data.daily.length > 0 && (
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Activity — last 14 days</h2>
          <ColumnChart data={data.daily.map((d) => ({ label: d.date.slice(5), value: d.count }))} color={CHART_COLORS[1]} height={90} />
        </Card>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[200px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search details…" className="w-full pl-9" />
          </div>
          <Select value={member} onChange={(e) => { setMember(e.target.value); setPage(1) }}>
            <option value="">All users</option>
            {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <Select value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }}>
            <option value="">All modules</option>
            {ACTION_GROUPS.map(([prefix, label]) => <option key={prefix} value={prefix}>{label}</option>)}
          </Select>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No activity found" hint="Actions across the CRM land here as they happen." />
        ) : (
          <div className="space-y-3 border-l border-slate-100 pl-1 dark:border-slate-800">
            {data.data.map((entry) => (
              <div key={entry.id} className="relative pl-5">
                <span className="absolute left-0 top-1.5 size-2 rounded-full bg-emerald-400" />
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <span className="text-sm font-medium text-slate-800 dark:text-slate-100">
                    {entry.action.replace('.', ' → ').replace(/_/g, ' ')}
                  </span>
                  <span className="text-xs text-slate-400">{entry.by ?? 'System'} · {entry.at}</span>
                </div>
                {describe(entry) && <p className="mt-0.5 text-xs text-slate-500">{describe(entry)}</p>}
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}
