import { useState } from 'react'
import { Link } from 'react-router-dom'
import { asList, useFilterAddress, useFiltersInAddress } from '../../lib/useFiltersInAddress'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Spinner } from '../../components/ui'
import { LogEntry } from './CrmLeadDetailPage'
import { crmPath } from '../../lib/crmPath'
import { MultiSelect } from '../../components/MultiSelect'
import { listParam } from '../../lib/multiFilter'

/**
 * The Lead Log: everything that happened to every lead, newest first — the
 * old CRM's audit screen, fed by the shared activity trail.
 */
export default function CrmLeadLogPage() {
  // The filters come from the address, and go back to it - see useFiltersInAddress.
  const address = useFilterAddress()
  const [search, setSearch] = useState(() => address.text('q'))
  const [applied, setApplied] = useState(() => address.text('q'))
  const [leadNo, setLeadNo] = useState(() => address.text('lead'))
  const [appliedNo, setAppliedNo] = useState(() => address.text('lead'))
  // Checkbox filter: null is everyone ticked, the default.
  const [member, setMember] = useState<string[] | null>(() => address.list('member'))
  const [dateFrom, setDateFrom] = useState(() => address.text('from'))
  const [dateTo, setDateTo] = useState(() => address.text('to'))
  const [page, setPage] = useState(() => address.page())
  useFiltersInAddress('lead-log', { q: applied.trim() || null, lead: appliedNo.trim() || null, member: asList(member), from: dateFrom || null, to: dateTo || null, page: page > 1 ? String(page) : null })

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'lead-log', applied, appliedNo, member, dateFrom, dateTo, page],
    queryFn: () =>
      crm.leads.log({
        search: applied || undefined,
        lead_no: appliedNo || undefined,
        member: listParam(member),
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
          className="crm-filters mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search); setAppliedNo(leadNo) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search the trail…" className="w-full pl-9" />
          </div>
          <Input type="number" min="1" value={leadNo} onChange={(e) => setLeadNo(e.target.value)} placeholder="Lead #" className="w-24" />
          <MultiSelect
            label="User"
            allLabel="Everyone"
            options={(masters?.members ?? []).map((m) => ({ value: m.uuid, label: m.name ?? '—' }))}
            value={member}
            onChange={(v) => { setMember(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
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
                {/* The name as well as the number: a page of "Lead #41" is
                    a page of things to go and look up. */}
                {log.lead_no !== null && (log.lead_uuid ? (
                  <Link to={crmPath(`/crm/leads/${log.lead_uuid}`)} className="mb-0.5 ml-5 inline-block text-xs font-medium text-emerald-600 hover:underline">
                    Lead #{log.lead_no}{log.company_name && <> · {log.company_name}</>}
                  </Link>
                ) : (
                  <span className="mb-0.5 ml-5 inline-block text-xs font-medium text-slate-400">
                    Lead #{log.lead_no}{log.company_name && <> · {log.company_name}</>} (deleted)
                  </span>
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
