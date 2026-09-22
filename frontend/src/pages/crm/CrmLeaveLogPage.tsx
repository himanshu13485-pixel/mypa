import { useState } from 'react'
import { asList, useFilterAddress, useFiltersInAddress } from '../../lib/useFiltersInAddress'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmLeaveLogEntry } from '../../api/crm'
import { Button, Card, EmptyState, Input, Pager, Spinner } from '../../components/ui'
import { CHART_COLORS, ColumnChart, HBarChart } from './charts'
import { MultiSelect } from '../../components/MultiSelect'
import { listParam } from '../../lib/multiFilter'

/**
 * The Leave Log: every request, and what became of it.
 *
 * The Leaves screen is about what is owed an answer — the pending queue, and
 * the account somebody still has to spend. This is the record afterwards:
 * who asked, who let them go, how long it took to say so, and which of those
 * days the company actually paid for.
 */

/**
 * What each action reads as, and the colour it wears.
 *
 * The raw slug is nearly legible ("leave.approval_withdrawn"), which is the
 * trap — nearly is not, and a log is read at a glance or not at all. Amber
 * for the asking rather than green or red: a request awaiting an answer is
 * neither good news nor bad, and colouring it either way would put a verdict
 * on the page before anybody had reached one.
 */
const ACTIONS: Record<string, { label: string; tone: string; dot: string }> = {
  'leave.requested': {
    label: 'Requested',
    tone: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    dot: 'bg-amber-400',
  },
  'leave.approved': {
    label: 'Approved',
    tone: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    dot: 'bg-emerald-500',
  },
  'leave.rejected': {
    label: 'Rejected',
    tone: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300',
    dot: 'bg-red-500',
  },
  'leave.cancelled': {
    label: 'Withdrawn by the employee',
    tone: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
    dot: 'bg-slate-400',
  },
  // Distinct from the employee withdrawing: this one puts days BACK and
  // turns a calendar day from Leave to Absent, so it must not read the same.
  'leave.approval_withdrawn': {
    label: 'Approval withdrawn',
    tone: 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    dot: 'bg-orange-500',
  },
}

const describe = (action: string) =>
  ACTIONS[action] ?? {
    label: action.replace('leave.', '').replace(/_/g, ' '),
    tone: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
    dot: 'bg-slate-400',
  }

export default function CrmLeaveLogPage() {
  // The filters come from the address, and go back to it - see useFiltersInAddress.
  const address = useFilterAddress()
  const [search, setSearch] = useState(() => address.text('q'))
  const [applied, setApplied] = useState(() => address.text('q'))
  // Checkbox filters: null is everything ticked, the default.
  const [action, setAction] = useState<string[] | null>(() => address.list('action'))
  const [employee, setEmployee] = useState<string[] | null>(() => address.list('employee'))
  const [member, setMember] = useState<string[] | null>(() => address.list('member'))
  const [dateFrom, setDateFrom] = useState(() => address.text('from'))
  const [dateTo, setDateTo] = useState(() => address.text('to'))
  const [page, setPage] = useState(() => address.page())
  useFiltersInAddress('leave-log', { q: applied.trim() || null, action: asList(action), employee: asList(employee), member: asList(member), from: dateFrom || null, to: dateTo || null, page: page > 1 ? String(page) : null })

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leave-log', applied, action, employee, member, dateFrom, dateTo, page],
    queryFn: () =>
      crm.leaves.log({
        search: applied || undefined,
        action: listParam(action),
        employee: listParam(employee),
        member: listParam(member),
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  const summary = data?.summary

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Leave log</h1>
        <p className="text-sm text-slate-500">
          Every leave request and what became of it — who asked, who decided, and when.
        </p>
      </div>

      {summary && summary.total > 0 && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            {[
              { label: 'Entries', value: String(summary.total), tone: '' },
              { label: 'Days approved', value: String(summary.approved_days), tone: 'text-emerald-600 dark:text-emerald-400' },
              {
                label: 'Of which unpaid',
                value: String(summary.unpaid_days),
                // Only coloured when there are any: nought unpaid days is
                // good news, and painting it a warning colour says otherwise.
                tone: summary.unpaid_days > 0 ? 'text-amber-600 dark:text-amber-400' : '',
              },
            ].map((c) => (
              <Card key={c.label} className="py-3">
                <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
                <div className="text-xs text-slate-500">{c.label}</div>
              </Card>
            ))}
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            {summary.by_employee.length > 0 && (
              <Card>
                <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Days taken, by employee
                </h2>
                {/* Approved days only — an asking is not a day off, and a
                    chart that counted both would overstate every person who
                    had a request turned down. */}
                <HBarChart
                  data={summary.by_employee.map((e) => ({ label: e.employee, value: e.days }))}
                  unit=" d"
                />
              </Card>
            )}
            {summary.daily.length > 1 && (
              <Card>
                <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Activity</h2>
                <ColumnChart
                  data={summary.daily.map((d) => ({ label: d.day.slice(5), value: d.count }))}
                  color={CHART_COLORS[1]}
                  height={90}
                />
              </Card>
            )}
          </div>
        </>
      )}

      <Card>
        <form
          className="crm-filters mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          {/* A floor as well as a ceiling: this row carries more filters than
              the other logs do, and a search box that is only allowed to
              shrink collapses to its own magnifying glass when they do not
              all fit. */}
          <div className="relative min-w-[180px] flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search reasons, notes…"
              className="w-full pl-9"
            />
          </div>
          {/* Two different people, and the log is read for both. */}
          <MultiSelect
            label="Whose leave"
            allLabel="Anyone"
            options={(masters?.members ?? []).map((m) => ({ value: m.uuid, label: m.name ?? '—' }))}
            value={employee}
            onChange={(v) => { setEmployee(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
          <MultiSelect
            label="Acted by"
            allLabel="Anyone"
            options={(masters?.members ?? []).map((m) => ({ value: m.uuid, label: m.name ?? '—' }))}
            value={member}
            onChange={(v) => { setMember(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
          {/* Offered from what this org has actually recorded, so the filter
              never promises a result that cannot exist. */}
          <MultiSelect
            label="What happened"
            options={(summary?.actions ?? []).map((a) => ({ value: a, label: describe(a).label }))}
            value={action}
            onChange={(v) => { setAction(v); setPage(1) }}
            className="min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]"
          />
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From" className="w-40" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To" className="w-40" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No leave activity" hint="Requests and decisions appear here as they happen." />
        ) : (
          <div className="space-y-3 border-l border-slate-100 pl-1 dark:border-slate-800">
            {data.data.map((entry) => <LogLine key={entry.id} entry={entry} />)}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}

function LogLine({ entry }: { entry: CrmLeaveLogEntry }) {
  const what = describe(entry.action)

  return (
    <div className="relative pl-5">
      <span className={clsx('absolute left-0 top-2 size-2 rounded-full', what.dot)} />
      <div className="flex flex-wrap items-baseline gap-x-2">
        <span className={clsx('rounded px-1.5 py-0.5 text-xs font-medium', what.tone)}>{what.label}</span>
        <span className="text-sm font-medium text-slate-800 dark:text-slate-100">
          {entry.employee ?? 'Someone'}
        </span>
        {entry.category && <span className="text-sm text-slate-500">· {entry.category}</span>}
        {entry.dates && <span className="text-xs text-slate-500">· {entry.dates}</span>}
        {entry.days !== null && (
          <span className="text-xs tabular-nums text-slate-500">
            · {entry.days} day{entry.days === 1 ? '' : 's'}
          </span>
        )}
        {/* Named on the line rather than left to the payslip: unpaid days are
            the part of an approval somebody will want to have been told. */}
        {(entry.unpaid_days ?? 0) > 0 && (
          <span className="text-xs font-medium text-amber-600 dark:text-amber-400">
            · {entry.unpaid_days} unpaid
          </span>
        )}
        {(entry.days_refunded ?? 0) > 0 && (
          <span className="text-xs text-orange-600 dark:text-orange-400">
            · {entry.days_refunded} returned to the account
          </span>
        )}
      </div>
      <div className="mt-0.5 text-xs text-slate-400">
        {/* A request is made by its own employee, so saying so again would be
            noise; a decision is the line where who acted actually matters. */}
        {entry.action !== 'leave.requested' && entry.by && <>{entry.by} · </>}
        {entry.at}
      </div>
      {(entry.reason || entry.note) && (
        <p className="mt-0.5 text-xs italic text-slate-500">
          &ldquo;{entry.note ?? entry.reason}&rdquo;
        </p>
      )}
    </div>
  )
}
