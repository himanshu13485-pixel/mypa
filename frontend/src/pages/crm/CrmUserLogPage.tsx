import { useState } from 'react'
import { asList, useFilterAddress, useFiltersInAddress } from '../../lib/useFiltersInAddress'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Spinner } from '../../components/ui'
import { CHART_COLORS, ColumnChart } from './charts'
import { MultiSelect } from '../../components/MultiSelect'
import { listParam } from '../../lib/multiFilter'

const ACTION_GROUPS = [
  ['employee', 'Employees'], ['client', 'Clients'], ['lead', 'Leads'],
  ['proforma', 'Proforma'], ['invoice', 'Invoices'], ['payment', 'Payments'],
  ['tds', 'TDS certificates'], ['task', 'Tasks and pendencies'],
  ['birthday', 'Birthday wishes'], ['settings', 'Settings and look'],
  ['dcw', 'Workspace fields'],
] as const

export default function CrmUserLogPage() {
  // Checkbox filters: null is everything ticked, the default.
  // The filters come from the address, and go back to it - see useFiltersInAddress.
  const address = useFilterAddress()
  const [member, setMember] = useState<string[] | null>(() => address.list('member'))
  const [action, setAction] = useState<string[] | null>(() => address.list('action'))
  const [search, setSearch] = useState(() => address.text('q'))
  const [applied, setApplied] = useState(() => address.text('q'))
  const [dateFrom, setDateFrom] = useState(() => address.text('from'))
  const [dateTo, setDateTo] = useState(() => address.text('to'))
  const [page, setPage] = useState(() => address.page())
  useFiltersInAddress('user-log', { member: asList(member), action: asList(action), q: applied.trim() || null, from: dateFrom || null, to: dateTo || null, page: page > 1 ? String(page) : null })

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'user-log', member, action, applied, dateFrom, dateTo, page],
    queryFn: () =>
      // Sent comma-separated: this call's params take plain strings, and the server reads either.
      crm.reports.userLog({
        member: listParam(member),
        action: listParam(action),
        search: applied || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  const describe = (entry: { action: string; changes: Record<string, unknown> | null }) => {
    const c = entry.changes ?? {}
    const bits: string[] = []
    for (const key of ['label', 'entity', 'type', 'title', 'kind', 'number', 'lead_no', 'company_name', 'client', 'to', 'amount', 'tds', 'status', 'channel', 'requested_by', 'reason', 'note']) {
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
          className="crm-filters mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[200px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search details…" className="w-full pl-9" />
          </div>
          <MultiSelect
            label="User"
            allLabel="Everyone"
            options={(masters?.members ?? []).map((m) => ({ value: m.uuid, label: m.name ?? '—' }))}
            value={member}
            onChange={(v) => { setMember(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
          <MultiSelect
            label="Module"
            options={ACTION_GROUPS.map(([prefix, label]) => ({ value: prefix, label }))}
            value={action}
            onChange={(v) => { setAction(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
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
