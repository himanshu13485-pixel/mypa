import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm } from '../../api/crm'
import { Button, Card, EmptyState, Input, Label, Pager, Select, Spinner } from '../../components/ui'
import { ErrorPill } from './CrmComplaintsPage'
import { HBarChart } from './charts'
import { crmPath } from '../../lib/crmPath'

/**
 * The Complaint Log: every complaint that has been settled, and how it was
 * settled. The live register is about work still owed; this is the record
 * afterwards — what was promised, how long it took, and whose mistake it
 * turned out to be.
 */
export default function CrmComplaintLogPage() {
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [page, setPage] = useState(1)

  const { data: options } = useQuery({ queryKey: ['crm', 'complaint-options'], queryFn: crm.complaints.options })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'complaint-log', applied, filters, page],
    // Closed, however it ended — that is what makes this the log.
    queryFn: () => crm.complaints.log({ ...filters, search: applied || undefined, page }),
  })

  const setFilter = (key: string, value: string) => {
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
  }

  const hours = (v: number | null) => (v === null ? '—' : v < 24 ? `${v} h` : `${Math.round(v / 24 * 10) / 10} d`)
  const took = (c: { created_at: string | null; closed_at: string | null }) => {
    if (!c.created_at || !c.closed_at) return '—'
    const h = (new Date(c.closed_at).getTime() - new Date(c.created_at).getTime()) / 3600000
    return h < 24 ? `${Math.round(h)} h` : `${Math.round(h / 24 * 10) / 10} d`
  }

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Complaint log</h1>
        <p className="text-sm text-slate-500">
          Every complaint that has been settled — how it ended, how long it took, and whose error it was.
          {data && <> · {data.summary.count} closed</>}
        </p>
      </div>

      {data && data.summary.count > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Closed with satisfaction', value: String(data.summary.closed_satisfied), tone: 'text-emerald-600 dark:text-emerald-400' },
            {
              label: 'Closed with dissatisfaction',
              value: String(data.summary.closed_dissatisfied),
              tone: data.summary.closed_dissatisfied ? 'text-red-500' : '',
            },
            { label: 'First reply (avg)', value: hours(data.summary.avg_first_response_hours) },
            { label: 'Time to close (avg)', value: hours(data.summary.avg_resolution_hours) },
          ].map((c) => (
            <Card key={c.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
              <div className="text-xs text-slate-500">{c.label}</div>
            </Card>
          ))}
        </div>
      )}

      {data && data.summary.count > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Whose error</h2>
            <div className="flex flex-wrap gap-2">
              {data.summary.by_error_type.filter((e) => e.count > 0).map((e) => (
                <button
                  key={e.key}
                  onClick={() => setFilter('final_error_type', filters.final_error_type === e.key ? '' : e.key)}
                  className={clsx(
                    'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition',
                    filters.final_error_type === e.key
                      ? 'border-indigo-400 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-500/10'
                      : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
                  )}
                >
                  <ErrorPill type={e.key} label={e.label} />
                  <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{e.count}</span>
                </button>
              ))}
            </div>
            {data.summary.by_subject.length > 0 && (
              <>
                <h3 className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  What they were about
                </h3>
                <HBarChart data={data.summary.by_subject.map((s) => ({ label: s.subject, value: s.count }))} unit="" />
              </>
            )}
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              Errors traced to a person
            </h2>
            {data.summary.by_error_member.length === 0 ? (
              <p className="py-6 text-center text-sm text-slate-400">
                No closed complaint has been pinned on anyone.
              </p>
            ) : (
              <HBarChart data={data.summary.by_error_member.map((m) => ({ label: m.name, value: m.count }))} unit="" />
            )}
          </Card>
        </div>
      )}

      <Card>
        <form
          className="mb-3 flex flex-wrap items-end gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[240px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="CMS no., company, subject…" className="w-full pl-9" />
          </div>
          <div>
            <Label>Closed from</Label>
            <Input type="date" value={filters.closed_from ?? ''} onChange={(e) => setFilter('closed_from', e.target.value)} />
          </div>
          <div>
            <Label>Closed to</Label>
            <Input type="date" value={filters.closed_to ?? ''} onChange={(e) => setFilter('closed_to', e.target.value)} />
          </div>
          <Select value={filters.status ?? ''} onChange={(e) => setFilter('status', e.target.value)}>
            <option value="">However it ended</option>
            <option value="closed_satisfied">With satisfaction</option>
            <option value="closed_dissatisfied">With dissatisfaction</option>
          </Select>
          <Select value={filters.final_error_type ?? ''} onChange={(e) => setFilter('final_error_type', e.target.value)}>
            <option value="">Any error type</option>
            {options && Object.entries(options.error_types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </Select>
          <Select value={filters.error_member ?? ''} onChange={(e) => setFilter('error_member', e.target.value)}>
            <option value="">Any error owner</option>
            {options?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <Select value={filters.subject ?? ''} onChange={(e) => setFilter('subject', e.target.value)}>
            <option value="">Any subject</option>
            {options?.subjects.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => { setFilters({}); setSearch(''); setApplied(''); setPage(1) }}
          >
            Clear
          </Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title="Nothing closed yet"
            hint="A complaint lands here the moment it is closed, with its resolution and its final error type."
          />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[1080px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">CMS ID</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  <th className="py-2 pr-3 font-medium">Subject</th>
                  <th className="py-2 pr-3 font-medium">How it ended</th>
                  <th className="py-2 pr-3 font-medium">Final error</th>
                  <th className="py-2 pr-3 font-medium">Closed</th>
                  <th className="py-2 pr-3 text-right font-medium">Took</th>
                  <th className="py-2 font-medium">Resolution</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((c) => (
                  <tr key={c.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      <Link to={crmPath(`/crm/complaints/${c.uuid}`)} className="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100">
                        {c.cms_no}
                      </Link>
                      <div className="text-xs text-slate-400">{c.complained_on}</div>
                    </td>
                    <td className="max-w-[180px] py-2.5 pr-3">
                      <div className="truncate text-slate-700 dark:text-slate-200">{c.company_name}</div>
                      <div className="truncate text-xs text-slate-400">{c.contact_person ?? '—'}</div>
                    </td>
                    <td className="max-w-[200px] py-2.5 pr-3">
                      <div className="truncate text-slate-700 dark:text-slate-200">{c.subject ?? '—'}</div>
                      <div className="truncate text-xs text-slate-400">
                        {[c.complaint_type, c.source].filter(Boolean).join(' · ')}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
                        c.status === 'closed_satisfied'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
                      )}>
                        {c.status === 'closed_satisfied' ? 'Satisfied' : 'Dissatisfied'}
                      </span>
                    </td>
                    <td className="py-2.5 pr-3">
                      {c.final_error_type ? (
                        <>
                          <ErrorPill type={c.final_error_type} label={c.final_error_label ?? ''} />
                          {c.final_error_member && <div className="mt-0.5 text-xs text-slate-400">{c.final_error_member}</div>}
                        </>
                      ) : <span className="text-slate-400">—</span>}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-xs text-slate-500">
                      {c.closed_at?.slice(0, 16) ?? '—'}
                      {c.closed_by && <div className="text-slate-400">by {c.closed_by}</div>}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-600 dark:text-slate-300">
                      {took(c)}
                    </td>
                    <td className="max-w-[260px] py-2.5">
                      <div className="truncate text-xs text-slate-500" title={c.resolution ?? ''}>
                        {c.resolution ?? '—'}
                      </div>
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
