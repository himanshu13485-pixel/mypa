import { useEffect, useMemo, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CopyPlus, Medal, Save, TrendingUp } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmGrowthPeriod, type CrmMe, type CrmTargetRow } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, DonutChart, GrowthChart, HBarChart } from './charts'
import { ScopeToggle, useTeamHead } from './ScopeToggle'
import { MultiSelect } from '../../components/MultiSelect'
import { listParam } from '../../lib/multiFilter'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

const inr = (v: number) => '₹' + Math.round(v).toLocaleString('en-IN')

/*
 * Both target tables carry more columns than a phone has room for, so they
 * scroll sideways in the wrapper the rest of the CRM uses. The person column
 * is pinned to the left edge: once a thumb has scrolled across to the money,
 * a row with no name beside it says nothing at all.
 */
const STICKY_PERSON = 'sticky left-0 z-10 border-r border-slate-100 bg-white dark:border-slate-800 dark:bg-slate-900'

/** A month as one number, so ranges compare and step without date maths. */
const code = (y: number, m: number) => y * 12 + m
const fromCode = (c: number) => ({ year: Math.floor((c - 1) / 12), month: ((c - 1) % 12) + 1 })

/**
 * A row being typed. Both floors are held at once, so flipping a person from
 * one to the other never throws away the number the other floor had.
 */
type TargetDraft = { kind: 'sales' | 'clients'; target: string; clientTarget: string }

const draftOf = (r: CrmTargetRow): TargetDraft => ({
  kind: r.kind,
  target: r.target ? String(r.target) : '',
  clientTarget: r.client_target ? String(r.client_target) : '',
})

const PERIODS: { key: CrmGrowthPeriod; label: string }[] = [
  { key: 'month', label: 'Monthly' },
  { key: 'quarter', label: '3 months' },
  { key: 'half', label: '6 months' },
  { key: 'year', label: 'Yearly' },
]

export default function CrmTargetsPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  // Setting targets is company authority — Admin/Subadmin, never a right.
  const canEdit = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const now = new Date()
  const thisMonth = code(now.getFullYear(), now.getMonth() + 1)

  // The reading is a run of months. One month is the plain screen; a longer
  // run adds the months' targets and their sales together.
  const [start, setStart] = useState(thisMonth)
  const [end, setEnd] = useState(thisMonth)
  const [drafts, setDrafts] = useState<Record<string, TargetDraft>>({})
  const [dirty, setDirty] = useState(false)

  const first = fromCode(Math.min(start, end))
  const last = fromCode(Math.max(start, end))

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'targets', first.year, first.month, last.year, last.month],
    queryFn: () => crm.targets.list(first.year, first.month, last.year, last.month),
  })

  // Editable copies of the target numbers, refreshed whenever the period loads.
  useEffect(() => {
    if (!data) return
    setDrafts(Object.fromEntries(data.data.map((r) => [r.member_uuid, draftOf(r)])))
    setDirty(false)
  }, [data])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'targets'] })

  // The draft may not have caught up with a just-loaded period, so the row
  // itself is the fallback.
  const draftFor = (r: CrmTargetRow) => drafts[r.member_uuid] ?? draftOf(r)

  const editDraft = (r: CrmTargetRow, patch: Partial<TargetDraft>) => {
    setDrafts((d) => ({ ...d, [r.member_uuid]: { ...(d[r.member_uuid] ?? draftOf(r)), ...patch } }))
    setDirty(true)
  }

  const saveMutation = useMutation({
    mutationFn: () =>
      crm.targets.save(first.year, first.month, (data?.data ?? [])
        .map((r) => ({ row: r, draft: draftFor(r) }))
        // A blank box means "leave this one alone", but a changed kind is worth
        // saving on its own.
        .filter(({ row, draft }) => draft.kind !== row.kind
          || (draft.kind === 'clients' ? draft.clientTarget : draft.target) !== '')
        .map(({ row, draft }) => ({
          member_uuid: row.member_uuid,
          kind: draft.kind,
          target_amount: Number(draft.target) || 0,
          client_target: Number(draft.clientTarget) || 0,
        }))),
    onSuccess: () => {
      refresh()
      toast('Targets saved.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const copyMutation = useMutation({
    mutationFn: () => crm.targets.copyPrevious(first.year, first.month),
    onSuccess: (res: { message?: string }) => {
      refresh()
      toast(res.message ?? 'Copied.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const years = Array.from({ length: 6 }, (_, i) => now.getFullYear() - 3 + i)
  const medals = ['text-amber-400', 'text-slate-400', 'text-orange-600']
  const span = data?.months ?? 1
  const editable = data?.editable ?? true
  // The one gate on every box on the page: a target is typed for a single
  // month, by someone with the authority to set it.
  const mayType = canEdit && editable

  // Two different teams judged on two different numbers, so they are read apart.
  const salesRows = useMemo(() => (data?.data ?? []).filter((r) => r.kind === 'sales'), [data])
  const clientRows = useMemo(() => (data?.data ?? []).filter((r) => r.kind === 'clients'), [data])

  // Whole months, ending with this one — the readings people actually ask for.
  const quickSpans: { label: string; months: number }[] = [
    { label: 'This month', months: 1 },
    { label: 'Last 3', months: 3 },
    { label: 'Last 6', months: 6 },
    { label: 'Last 12', months: 12 },
  ]
  const applySpan = (months: number) => {
    setStart(thisMonth - months + 1)
    setEnd(thisMonth)
  }

  const MonthPicker = ({ value, onChange }: { value: number; onChange: (next: number) => void }) => {
    const { year, month } = fromCode(value)
    return (
      <div className="flex gap-1">
        <Select value={month} onChange={(e) => onChange(code(year, Number(e.target.value)))}>
          {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
        </Select>
        <Select value={year} onChange={(e) => onChange(code(Number(e.target.value), month))}>
          {years.map((y) => <option key={y} value={y}>{y}</option>)}
        </Select>
      </div>
    )
  }

  /** Name, code, and what this person is judged on. Both tables lead with it. */
  const PersonCell = ({ row }: { row: CrmTargetRow }) => (
    <td className={clsx(STICKY_PERSON, 'py-2.5 pr-3')}>
      <div className="font-medium text-slate-800 dark:text-slate-100">{row.name}</div>
      {row.employee_code && <div className="text-xs text-slate-400">{row.employee_code}</div>}
      {mayType && (
        <Select
          value={draftFor(row).kind}
          onChange={(e) => editDraft(row, { kind: e.target.value as 'sales' | 'clients' })}
          // Fixed width rather than full: the cell is sized by the longest
          // name in the column, and a dropdown that follows it would be a
          // different size on every row.
          className="mt-1.5 min-h-[38px] w-24 py-1 text-xs sm:w-28"
        >
          <option value="sales">Sales</option>
          <option value="clients">Clients</option>
        </Select>
      )}
    </td>
  )

  /** The bar and the number, read the same way on either floor. */
  const ProgressCell = ({ percent }: { percent: number | null }) => {
    const pct = percent ?? 0
    return (
      <td className="py-2.5">
        <div className="flex items-center gap-2">
          <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div
              className={clsx(
                'h-full rounded-full transition-all',
                pct >= 100 ? 'bg-emerald-500' : pct >= 60 ? 'bg-amber-400' : 'bg-red-400',
              )}
              style={{ width: `${Math.min(100, pct)}%` }}
            />
          </div>
          <span className="w-12 text-right text-xs font-medium tabular-nums text-slate-500">
            {percent !== null ? `${percent}%` : '—'}
          </span>
        </div>
      </td>
    )
  }

  /** The medal goes to the top three who actually put something on the board. */
  const RankCell = ({ index, scored }: { index: number; scored: boolean }) => (
    <td className="py-2.5 pr-3">
      {index < 3 && scored
        ? <Medal className={clsx('size-4', medals[index])} />
        : <span className="text-slate-400">{index + 1}</span>}
    </td>
  )

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Targets</h1>
          <p className="text-sm text-slate-500">
            {data?.label ?? `${MONTHS[first.month - 1]} ${first.year}`} — achievement comes straight from the invoice ledger.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canEdit && (
            <>
              <Button variant="secondary" onClick={() => copyMutation.mutate()} disabled={!editable || copyMutation.isPending}>
                <CopyPlus className="size-4" /> Copy last month
              </Button>
              <Button onClick={() => saveMutation.mutate()} disabled={!editable || !dirty || saveMutation.isPending}>
                <Save className="size-4" /> {saveMutation.isPending ? 'Saving…' : 'Save targets'}
              </Button>
            </>
          )}
        </div>
      </div>

      <Card className="space-y-3">
        <div className="flex flex-wrap items-end gap-x-4 gap-y-2">
          <div>
            <div className="mb-1 text-xs font-medium text-slate-500">From</div>
            <MonthPicker value={start} onChange={(next) => { setStart(next); if (next > end) setEnd(next) }} />
          </div>
          <div>
            <div className="mb-1 text-xs font-medium text-slate-500">To</div>
            <MonthPicker value={end} onChange={(next) => { setEnd(next); if (next < start) setStart(next) }} />
          </div>
          <div className="flex flex-wrap gap-1.5">
            {quickSpans.map((q) => {
              const active = start === thisMonth - q.months + 1 && end === thisMonth
              return (
                <button
                  key={q.label}
                  onClick={() => applySpan(q.months)}
                  className={clsx(
                    'rounded-lg px-2.5 py-1.5 text-xs font-medium transition',
                    active
                      ? 'bg-indigo-500 text-white'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700',
                  )}
                >
                  {q.label}
                </button>
              )
            })}
          </div>
        </div>
        {span > 1 && (
          <p className="text-xs text-slate-500">
            Reading {span} months together — the target shown is the sum of those months&rsquo; own targets.
            {canEdit && ' Pick a single month to type new numbers in.'}
          </p>
        )}
      </Card>

      {data && salesRows.length > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {[
            { label: 'Total target', value: inr(data.totals.target) },
            // A target is judged on the sale, so every money figure here is the
            // taxable value: what the client was charged before tax.
            { label: 'Achieved', value: inr(data.totals.achieved), hint: 'taxable value' },
            { label: 'New business', value: inr(data.totals.achieved_new) },
            { label: 'Existing business', value: inr(data.totals.achieved_existing) },
            { label: 'Pending target', value: inr(data.totals.pending_target), hint: 'still to sell' },
            {
              label: 'Payment due',
              value: inr(data.totals.payment_due),
              tone: 'text-red-500',
              hint: 'unpaid, tax included',
            },
            {
              // Head count, not a sum — one client billed twice is one client.
              label: data.totals.per_client
                ? `Clients billed · ${inr(data.totals.per_client)} avg`
                : 'Clients billed',
              value: String(data.totals.clients),
              hint: `${data.totals.clients_new} new · ${data.totals.clients_existing} existing`,
            },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', s.tone)}>{s.value}</div>
              <div className="text-xs text-slate-500">{s.label}</div>
              {s.hint && <div className="mt-0.5 text-[11px] text-slate-400">{s.hint}</div>}
            </Card>
          ))}
        </div>
      )}

      {data && clientRows.length > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {[
            { label: 'Clients target', value: String(data.client_totals.client_target) },
            { label: 'Clients built', value: String(data.client_totals.clients_built) },
            {
              label: 'New / existing',
              value: `${data.client_totals.clients_built_new} / ${data.client_totals.clients_built_existing}`,
            },
            {
              label: 'Sales value',
              value: inr(data.client_totals.client_sales),
              hint: `from new ${inr(data.client_totals.client_sales_new)} · from existing ${inr(data.client_totals.client_sales_existing)}`,
            },
            { label: 'Pending clients', value: String(data.client_totals.clients_due) },
            {
              label: 'Of client target',
              value: data.client_totals.percent === null ? '—' : `${data.client_totals.percent}%`,
            },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className="text-lg font-semibold text-slate-900 dark:text-white">{s.value}</div>
              <div className="text-xs text-slate-500">{s.label}</div>
              {s.hint && <div className="mt-0.5 text-[11px] text-slate-400">{s.hint}</div>}
            </Card>
          ))}
        </div>
      )}

      {data && salesRows.length > 0 && (data.totals.achieved > 0 || data.totals.target > 0) && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Achievement by person</h2>
            <HBarChart
              data={salesRows.filter((r) => r.achieved > 0).map((r) => ({ label: r.name ?? '—', value: r.achieved }))}
              unit=""
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">New vs existing business</h2>
            <DonutChart
              data={[
                { label: 'New business', value: data.totals.achieved_new, color: CHART_COLORS[0] },
                { label: 'Existing business', value: data.totals.achieved_existing, color: CHART_COLORS[1] },
              ]}
              centerLabel="₹ achieved"
            />
          </Card>
        </div>
      )}

      {data && clientRows.length > 0
        && (data.client_totals.clients_built > 0 || data.client_totals.client_target > 0) && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Clients built by person</h2>
            <HBarChart
              data={clientRows.filter((r) => r.clients_built > 0).map((r) => ({ label: r.name ?? '—', value: r.clients_built }))}
              unit=""
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">New vs existing clients</h2>
            <DonutChart
              data={[
                { label: 'New clients', value: data.client_totals.clients_built_new, color: CHART_COLORS[0] },
                { label: 'Existing clients', value: data.client_totals.clients_built_existing, color: CHART_COLORS[1] },
              ]}
              centerLabel="clients built"
            />
          </Card>
        </div>
      )}

      {isLoading ? (
        <Card>
          <div className="flex justify-center py-16"><Spinner /></div>
        </Card>
      ) : !data || data.data.length === 0 ? (
        <Card>
          <EmptyState title="No salespeople for this period" hint="Mark employees as salespeople to give them targets." />
        </Card>
      ) : (
        <>
          {salesRows.length > 0 && (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Sales targets</h2>
              <div className="-mx-4 overflow-x-auto px-4">
                <table className="w-full min-w-[1140px] text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                      <th className="py-2 pr-3 font-medium">#</th>
                      <th className={clsx(STICKY_PERSON, 'py-2 pr-3 font-medium')}>Salesperson</th>
                      <th className="py-2 pr-3 text-right font-medium">Target</th>
                      <th className="py-2 pr-3 text-right font-medium" title="The taxable value sold, before tax.">Achieved</th>
                      <th className="py-2 pr-3 text-right font-medium">New</th>
                      <th className="py-2 pr-3 text-right font-medium">Existing</th>
                      <th className="py-2 pr-3 text-right font-medium">Clients</th>
                      <th className="py-2 pr-3 text-right font-medium" title="What is left of the target: the work still to do.">Pending target</th>
                      <th
                        className="py-2 pr-3 text-right font-medium"
                        title="Unpaid invoice money clients still owe on this desk's documents, tax included. Not the shortfall against the target."
                      >
                        Payment due
                      </th>
                      <th className="w-44 py-2 font-medium">Progress</th>
                    </tr>
                  </thead>
                  <tbody>
                    {salesRows.map((r, i) => (
                      <tr key={r.member_uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                        <RankCell index={i} scored={r.achieved > 0} />
                        <PersonCell row={r} />
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {mayType ? (
                            <Input
                              type="number"
                              min="0"
                              value={draftFor(r).target}
                              onChange={(e) => editDraft(r, { target: e.target.value })}
                              className="min-h-[38px] w-24 text-right sm:w-28"
                              placeholder="0"
                            />
                          ) : (
                            <span className="font-medium">{r.target ? inr(r.target) : '—'}</span>
                          )}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{inr(r.achieved)}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">{r.achieved_new ? inr(r.achieved_new) : '—'}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">{r.achieved_existing ? inr(r.achieved_existing) : '—'}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {r.clients > 0 ? (
                            <>
                              <div
                                className="font-medium text-slate-700 dark:text-slate-200"
                                title={`${r.invoices} invoice${r.invoices === 1 ? '' : 's'}${r.per_client ? ` · ${inr(r.per_client)} per client` : ''}`}
                              >
                                {r.clients}
                              </div>
                              <div className="text-[11px] text-slate-400">
                                {r.clients_new} new · {r.clients_existing} existing
                              </div>
                            </>
                          ) : <span className="text-slate-400">—</span>}
                        </td>
                        {/* Work left, not money owed, so it is not dressed in red. */}
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium text-slate-600 dark:text-slate-300">
                          {r.pending_target ? inr(r.pending_target) : '—'}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-red-500">
                          {r.payment_due ? inr(r.payment_due) : '—'}
                        </td>
                        <ProgressCell percent={r.percent} />
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-2 text-[11px] text-slate-500">
                Target, Achieved, New and Existing are taxable value, before tax. Payment due is what clients
                still owe on this desk&rsquo;s documents, tax included.
              </p>
            </Card>
          )}

          {clientRows.length > 0 && (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Client targets</h2>
              <div className="-mx-4 overflow-x-auto px-4">
                <table className="w-full min-w-[1140px] text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                      <th className="py-2 pr-3 font-medium">#</th>
                      <th className={clsx(STICKY_PERSON, 'py-2 pr-3 font-medium')}>Salesperson</th>
                      <th className="py-2 pr-3 text-right font-medium">Target (clients)</th>
                      <th className="py-2 pr-3 text-right font-medium">Built</th>
                      <th className="py-2 pr-3 text-right font-medium">New</th>
                      <th className="py-2 pr-3 text-right font-medium">Existing</th>
                      <th
                        className="py-2 pr-3 text-right font-medium"
                        title="The taxable value these clients have billed in the period, before tax."
                      >
                        Sales value
                      </th>
                      <th className="py-2 pr-3 text-right font-medium" title="Clients still to find to meet the target.">Pending clients</th>
                      <th className="w-44 py-2 font-medium">Progress</th>
                    </tr>
                  </thead>
                  <tbody>
                    {clientRows.map((r, i) => (
                      <tr key={r.member_uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                        <RankCell index={i} scored={r.clients_built > 0} />
                        <PersonCell row={r} />
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {mayType ? (
                            <Input
                              type="number"
                              min="0"
                              value={draftFor(r).clientTarget}
                              onChange={(e) => editDraft(r, { clientTarget: e.target.value })}
                              className="min-h-[38px] w-24 text-right sm:w-28"
                              placeholder="0"
                            />
                          ) : (
                            <span className="font-medium">{r.client_target || '—'}</span>
                          )}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{r.clients_built}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">{r.clients_built_new || '—'}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">{r.clients_built_existing || '—'}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {r.client_sales ? (
                            <>
                              <div className="font-medium text-slate-700 dark:text-slate-200">{inr(r.client_sales)}</div>
                              <div className="text-[11px] text-slate-400">
                                from new {inr(r.client_sales_new)} · from existing {inr(r.client_sales_existing)}
                              </div>
                            </>
                          ) : <span className="text-slate-400">—</span>}
                        </td>
                        {/* Clients still to find. Work left, so it reads like the sales floor's. */}
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium text-slate-600 dark:text-slate-300">
                          {r.clients_due || '—'}
                        </td>
                        <ProgressCell percent={r.client_percent} />
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-2 text-[11px] text-slate-500">
                Sales value is taxable value, before tax, billed by the clients this person brought in.
              </p>
            </Card>
          )}
        </>
      )}

      <GrowthMap />
    </div>
  )
}

/**
 * The growth map: the same sales seen as a run of periods, each against the
 * one before it or against the same period last year. Whole floor by
 * default, one salesperson on demand.
 */
function GrowthMap() {
  const teamHead = useTeamHead()
  const [scope, setScope] = useState<'mine' | 'team'>('mine')
  const [period, setPeriod] = useState<CrmGrowthPeriod>('month')
  const [salesperson, setSalesperson] = useState<string[] | null>(null)
  const [compare, setCompare] = useState<'previous' | 'last_year'>('previous')
  const effectiveScope = teamHead ? scope : 'team'

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'targets', 'growth', period, effectiveScope, salesperson],
    queryFn: () => crm.targets.growth({
      period,
      scope: effectiveScope,
      salesperson: effectiveScope === 'team' ? listParam(salesperson) : undefined,
    }),
  })

  const chart = useMemo(() => (data?.buckets ?? []).map((b) => ({
    label: b.label,
    values: compare === 'last_year' ? [b.achieved, b.last_year] : [b.achieved],
    change: compare === 'last_year' ? b.yoy : b.growth,
  })), [data, compare])

  const periodWord = PERIODS.find((p) => p.key === period)?.label.toLowerCase() ?? 'period'

  return (
    <Card className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <TrendingUp className="size-4 text-indigo-500" /> Growth map
          </h2>
          <p className="text-xs text-slate-500">
            Sales by {periodWord}, {compare === 'last_year' ? 'against the same period last year' : 'against the period before'}.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={period} onChange={(e) => setPeriod(e.target.value as CrmGrowthPeriod)}>
            {PERIODS.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
          </Select>
          <Select value={compare} onChange={(e) => setCompare(e.target.value as 'previous' | 'last_year')}>
            <option value="previous">vs previous period</option>
            <option value="last_year">Year on year</option>
          </Select>
        </div>
      </div>

      <ScopeToggle scope={scope} onChange={(next) => { setScope(next); setSalesperson(null) }} show={teamHead} />

      {effectiveScope === 'team' && (data?.salespeople?.length ?? 0) > 1 && (
        <MultiSelect
          label="Salesperson"
          options={data!.salespeople!.map((m) => ({ value: m.uuid, label: m.name + (m.is_me ? ' (you)' : '') }))}
          value={salesperson}
          onChange={setSalesperson}
          className="w-full sm:max-w-xs"
        />
      )}

      {isLoading || !data ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              { label: 'Period sales', value: inr(data.totals.achieved) },
              { label: 'Same span last year', value: inr(data.totals.last_year) },
              {
                label: 'Year on year',
                value: data.totals.yoy === null ? '—' : `${data.totals.yoy >= 0 ? '+' : ''}${data.totals.yoy}%`,
                tone: data.totals.yoy === null ? '' : data.totals.yoy >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500',
              },
              { label: `Unique clients${data.totals.best ? ` · best ${data.totals.best}` : ''}`, value: String(data.totals.clients) },
            ].map((s) => (
              <div key={s.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
                <div className={clsx('text-base font-semibold text-slate-900 dark:text-white', s.tone)}>{s.value}</div>
                <div className="text-[11px] text-slate-500">{s.label}</div>
              </div>
            ))}
          </div>

          <GrowthChart
            data={chart}
            series={compare === 'last_year'
              ? [{ label: 'This period', color: CHART_COLORS[0] }, { label: 'Same period last year', color: CHART_COLORS[1] }]
              : [{ label: 'Sales', color: CHART_COLORS[0] }]}
            format={inr}
          />

          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Period</th>
                  <th className="py-2 pr-3 text-right font-medium">Sales</th>
                  <th className="py-2 pr-3 text-right font-medium">Target</th>
                  <th className="py-2 pr-3 text-right font-medium">Clients</th>
                  <th className="py-2 pr-3 text-right font-medium">Last year</th>
                  <th className="py-2 text-right font-medium">Growth</th>
                </tr>
              </thead>
              <tbody>
                {data.buckets.map((b) => {
                  const change = compare === 'last_year' ? b.yoy : b.growth
                  return (
                    <tr key={b.key} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="py-2 pr-3 font-medium text-slate-700 dark:text-slate-200">{b.label}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right font-medium">{inr(b.achieved)}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right text-slate-500">{b.target ? inr(b.target) : '—'}</td>
                      <td className="py-2 pr-3 text-right text-slate-500">{b.clients || '—'}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right text-slate-500">{b.last_year ? inr(b.last_year) : '—'}</td>
                      <td className="whitespace-nowrap py-2 text-right">
                        {change === null ? (
                          <span className="text-slate-400">—</span>
                        ) : (
                          <span className={clsx(
                            'font-medium tabular-nums',
                            change >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500',
                          )}>
                            {change >= 0 ? '+' : ''}{change}%
                          </span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Card>
  )
}
