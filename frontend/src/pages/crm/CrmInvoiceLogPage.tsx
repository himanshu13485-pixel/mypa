import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_PAYMENT_STATUS_LABELS, type CrmInvoiceLogEntry } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'
import { ColumnChart, DonutChart } from './charts'
import { crmPath } from '../../lib/crmPath'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })

/** Plain English for each trail action, both document kinds. */
const ACTION_LABELS: Record<string, string> = {
  'invoice.created': 'Invoice raised',
  'invoice.updated': 'Invoice edited',
  'invoice.cancelled': 'Invoice cancelled',
  'invoice.update_applied': 'Change request applied',
  'proforma.created': 'Proforma raised',
  'proforma.updated': 'Proforma edited',
  'proforma.cancelled': 'Proforma cancelled',
  'proforma.converted': 'Converted to invoice',
  'payment.recorded': 'Payment recorded',
  'payment.claimed': 'Bank credit claimed',
}

const actionLabel = (action: string) => ACTION_LABELS[action] ?? action

/** The dot colour carries the meaning: money in, edits, endings. */
const dotClass = (action: string) =>
  action.startsWith('payment.') ? 'bg-emerald-400'
    : action.endsWith('.cancelled') ? 'bg-red-400'
      : action === 'proforma.converted' ? 'bg-sky-400'
        : action.endsWith('.created') ? 'bg-violet-400'
          : 'bg-amber-400'

function Entry({ log }: { log: CrmInvoiceLogEntry }) {
  return (
    <div className="relative pl-5">
      <span className={clsx('absolute left-0 top-1.5 size-2 rounded-full', dotClass(log.action))} />
      <div className="flex flex-wrap items-baseline gap-x-2">
        <span className="text-sm font-medium text-slate-800 dark:text-slate-100">{actionLabel(log.action)}</span>
        {log.document ? (
          <Link to={crmPath(`/crm/invoices/${log.document.uuid}`)} className="text-sm font-medium text-emerald-600 hover:underline">
            {log.number ?? log.document.number}
          </Link>
        ) : (
          // The document is gone; the entry still says what it was.
          <span className="text-sm text-slate-500">{log.number ?? '—'}</span>
        )}
        <span className="text-xs text-slate-400">{log.by ?? 'System'} · {log.at}</span>
      </div>
      <p className="mt-0.5 text-xs text-slate-500">
        {log.client && <>{log.client}</>}
        {log.total !== null && log.total !== undefined && <> · {inr(log.total)}</>}
        {log.amount !== null && log.amount !== undefined && <> · payment {inr(log.amount)}</>}
        {log.invoice && <> · became {log.invoice}</>}
        {log.from_proforma && <> · from {log.from_proforma}</>}
      </p>
      {log.fields && log.fields.length > 0 && (
        <p className="mt-0.5 text-xs text-slate-500">Changed: {log.fields.join(', ')}</p>
      )}
      {log.note && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500">{log.note}</p>}
    </div>
  )
}

/**
 * Invoice Log / Proforma Log — one screen, the kind rides on the query
 * string exactly as it does for the document lists themselves.
 */
export default function CrmInvoiceLogPage() {
  const [params] = useSearchParams()
  const kind = params.get('kind') === 'proforma' ? 'proforma' : 'invoice'

  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [action, setAction] = useState('')
  const [member, setMember] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'invoice-log', kind, applied, action, member, dateFrom, dateTo, page],
    queryFn: () =>
      crm.invoices.log({
        kind,
        search: applied || undefined,
        action: action || undefined,
        member: member || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  const summary = data?.summary
  const title = kind === 'proforma' ? 'Proforma log' : 'Invoice log'

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">{title}</h1>
        <p className="text-sm text-slate-500">
          Everything that happened to every {kind === 'proforma' ? 'proforma' : 'tax invoice'} — raised, edited,
          {kind === 'proforma' ? ' converted' : ' paid'}, cancelled — newest first.
        </p>
      </div>

      {summary && summary.total > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">What happened</h2>
            <DonutChart
              data={summary.by_action.map((a) => ({ label: actionLabel(a.action), value: a.count }))}
              centerLabel={`${summary.total}`}
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Activity by day</h2>
            <ColumnChart data={summary.daily.map((d) => ({ label: d.day.slice(5), value: d.count }))} />
          </Card>
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Number or client…" className="w-full pl-9" />
          </div>
          <Select value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }}>
            <option value="">All actions</option>
            {(summary?.actions ?? []).map((a) => <option key={a} value={a}>{actionLabel(a)}</option>)}
          </Select>
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
          <EmptyState title="Nothing in the log yet" hint="Raise a document and its trail starts here." />
        ) : (
          <div className="space-y-3 border-l border-slate-100 pl-2 dark:border-slate-800">
            {data.data.map((log) => (
              <div key={log.id} className="flex flex-wrap items-start justify-between gap-2">
                <Entry log={log} />
                {log.document && (
                  <span className={clsx(
                    'mt-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
                    log.document.status === 'cancelled'
                      ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400'
                      : log.document.payment_status === 'paid'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                        : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                  )}>
                    {log.document.status === 'cancelled'
                      ? 'Cancelled'
                      : CRM_PAYMENT_STATUS_LABELS[log.document.payment_status] ?? log.document.payment_status}
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}
