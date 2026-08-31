import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'
import { LogEntry } from './CrmLeadDetailPage'

/**
 * The Lead Log: everything that happened to every lead, newest first — the
 * old CRM's audit screen, fed by the shared activity trail.
 */
export default function CrmLeadLogPage() {
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [leadNo, setLeadNo] = useState('')
  const [appliedNo, setAppliedNo] = useState('')
  const [member, setMember] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'lead-log', applied, appliedNo, member, dateFrom, dateTo, page],
    queryFn: () =>
      crm.leads.log({
        search: applied || undefined,
        lead_no: appliedNo || undefined,
        member: member || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Lead log</h1>
        <p className="text-sm text-slate-500">Every action on every lead — who did what, and when.</p>
      </div>

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search); setAppliedNo(leadNo) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search the trail…" className="w-full pl-9" />
          </div>
          <Input type="number" min="1" value={leadNo} onChange={(e) => setLeadNo(e.target.value)} placeholder="Lead #" className="w-24" />
          <Select value={member} onChange={(e) => { setMember(e.target.value); setPage(1) }}>
            <option value="">All users</option>
            {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From date" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To date" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No log entries" hint="Lead activity appears here as it happens." />
        ) : (
          <div className="space-y-4 border-l border-slate-100 pl-1 dark:border-slate-800">
            {data.data.map((log) => (
              <div key={log.id}>
                {log.lead_no !== null && (log.lead_uuid ? (
                  <Link to={`/crm/leads/${log.lead_uuid}`} className="mb-0.5 ml-5 inline-block text-xs font-medium text-emerald-600 hover:underline">
                    Lead #{log.lead_no}
                  </Link>
                ) : (
                  <span className="mb-0.5 ml-5 inline-block text-xs font-medium text-slate-400">Lead #{log.lead_no} (deleted)</span>
                ))}
                <LogEntry log={log} />
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}
