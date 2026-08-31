import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, PauseCircle, PlayCircle, TrendingUp } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmIncentiveLedgerRow, type CrmMe, type CrmScheduleStatus } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

const STATUS_STYLE: Record<CrmScheduleStatus, string> = {
  paid: 'bg-emerald-500 text-white',
  on_slip: 'bg-emerald-200 text-emerald-800 dark:bg-emerald-500/30 dark:text-emerald-200',
  due: 'bg-amber-400 text-white',
  upcoming: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  held: 'bg-orange-400 text-white',
  arrear: 'bg-sky-400 text-white',
  cancelled: 'bg-red-200 text-red-700 line-through dark:bg-red-500/20 dark:text-red-300',
  awaiting_payment: 'bg-violet-200 text-violet-800 dark:bg-violet-500/25 dark:text-violet-200',
}

const STATUS_LABEL: Record<CrmScheduleStatus, string> = {
  paid: 'Paid',
  on_slip: 'On slip, pending payout',
  due: 'Due — payroll not generated yet',
  upcoming: 'Upcoming',
  held: 'Held',
  arrear: 'Held — pays as arrear',
  cancelled: 'Cancelled',
  awaiting_payment: 'Awaiting full payment',
}

/**
 * The Incentives ledger: client by client, each sale's schedule of
 * installments — which months paid, which are coming, which an Admin
 * stopped. The one screen where an employee sees exactly what next month's
 * payroll will bring, without asking anyone.
 */
export default function CrmIncentivesPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const manager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [memberUuid, setMemberUuid] = useState('')
  const [ruling, setRuling] = useState<CrmIncentiveLedgerRow | null>(null)
  // The emergency brake: one ruling over every run at once.
  const [bulk, setBulk] = useState(false)
  // Check two or more months side by side.
  const [monthFrom, setMonthFrom] = useState('')
  const [monthTo, setMonthTo] = useState('')

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters, enabled: manager })
  const span = monthFrom && monthTo && monthTo >= monthFrom
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'incentive-ledger', memberUuid, span ? monthFrom : '', span ? monthTo : ''],
    queryFn: () => crm.incentives.ledger(
      memberUuid || undefined,
      span ? monthFrom : undefined,
      span ? monthTo : undefined,
    ),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'incentive-ledger'] })

  const release = useMutation({
    mutationFn: (uuid: string) => crm.incentives.release(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Incentives</h1>
          <p className="text-sm text-slate-500">
            Every sale&rsquo;s incentive run, client by client — what has paid, what is coming, what was stopped.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          {/* The between-months check, labelled so the two pickers read as
              what they are — and one click fills a sensible span. */}
          <div>
            <Label>Between months — from</Label>
            <Input type="month" value={monthFrom} onChange={(e) => setMonthFrom(e.target.value)} className="w-40" />
          </div>
          <div>
            <Label>to</Label>
            <Input type="month" value={monthTo} onChange={(e) => setMonthTo(e.target.value)} className="w-40" />
          </div>
          {!span ? (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => {
                const now = new Date()
                const ym = (d: Date) => d.toISOString().slice(0, 7)
                const from = new Date(now.getFullYear(), now.getMonth() - 2, 1)
                const to = new Date(now.getFullYear(), now.getMonth() + 1, 1)
                setMonthFrom(ym(from))
                setMonthTo(ym(to))
              }}
            >
              Last 3 months + next
            </Button>
          ) : (
            <Button variant="secondary" size="sm" onClick={() => { setMonthFrom(''); setMonthTo('') }}>
              Clear
            </Button>
          )}
          {manager && (
            <div>
              <Label>Whose ledger</Label>
              <Select value={memberUuid} onChange={(e) => setMemberUuid(e.target.value)}>
                <option value="">My own ledger</option>
                {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
              </Select>
            </div>
          )}
          {manager && (data?.rows.length ?? 0) > 0 && (
            <Button variant="secondary" size="sm" onClick={() => setBulk(true)}>
              <PauseCircle className="size-3.5" /> Hold / cancel ALL
            </Button>
          )}
        </div>
      </div>

      {isLoading || !data ? (
        <div className="flex justify-center py-20"><Spinner /></div>
      ) : (
        <>
          {data.next_month && (
            <Card className="py-3">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <CalendarClock className="size-5 text-emerald-500" />
                  <div>
                    <div className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                      Coming with {data.next_month.payroll_month}&rsquo;s payroll
                    </div>
                    <div className="text-xs text-slate-400">
                      Incentive earned up to {data.next_month.earned_month}, computed from the ledger as it stands today.
                      {data.next_month.arrear_total > 0 && <> Includes {inr(data.next_month.arrear_total)} arrear incentive release.</>}
                    </div>
                  </div>
                </div>
                <div className="text-2xl font-semibold tabular-nums text-emerald-600">{inr(data.next_month.total)}</div>
              </div>
            </Card>
          )}

          {(data.months?.length ?? 0) > 0 && (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                Month by month — earned, and when it pays
              </h2>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                {data.months.map((m) => (
                  <div key={m.earned_month} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
                    <div className={clsx(
                      'text-base font-semibold tabular-nums',
                      m.total < 0 ? 'text-red-500' : 'text-slate-900 dark:text-white',
                    )}>
                      {inr(m.total)}
                    </div>
                    <div className="text-[11px] text-slate-500">
                      earned {m.earned_month} · pays {m.payroll_month}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1 text-[10px]">
                      <span className={clsx('rounded-full px-1.5 py-0.5 font-medium', STATUS_STYLE[m.status])}>
                        {STATUS_LABEL[m.status]}
                      </span>
                      {m.arrear_total > 0 && <span className="text-sky-600">+{inr(m.arrear_total)} arrear</span>}
                      {m.recovery_total > 0 && <span className="text-red-500">−{inr(m.recovery_total)} recovered</span>}
                    </div>
                  </div>
                ))}
              </div>
              <p className="mt-2 text-xs text-slate-400">
                Total over the span:{' '}
                <span className="font-semibold text-slate-700 dark:text-slate-200">
                  {inr(data.months.reduce((sum, m) => sum + m.total, 0))}
                </span>
              </p>
            </Card>
          )}

          {data.plan === 'none' ? (
            <Card><EmptyState title="No incentive plan" hint="This person's slip carries salary only." /></Card>
          ) : data.plan !== 'spread' ? (
            <Card>
              <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                <TrendingUp className="size-4 text-emerald-500" /> Recent months
              </h2>
              <p className="mb-3 text-xs text-slate-400">
                This plan pays in one go, so there is no installment schedule — each month&rsquo;s figure below is
                that month&rsquo;s own earning.
              </p>
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                {data.recent.map((r) => (
                  <div key={r.earned_month} className="rounded-xl bg-slate-50 px-3 py-2 text-center dark:bg-slate-800/40">
                    <div className="font-semibold tabular-nums">{inr(r.total)}</div>
                    <div className="text-[11px] text-slate-500">{r.earned_month}</div>
                  </div>
                ))}
              </div>
            </Card>
          ) : data.rows.length === 0 ? (
            <Card><EmptyState title="No running incentives" hint="A sale starts its run the month it is invoiced." /></Card>
          ) : (
            <Card>
              <div className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                {(Object.keys(STATUS_LABEL) as CrmScheduleStatus[]).map((k) => (
                  <span key={k} className="flex items-center gap-1">
                    <span className={clsx('inline-block size-2.5 rounded-full', STATUS_STYLE[k].split(' ')[0])} />
                    {STATUS_LABEL[k]}
                  </span>
                ))}
              </div>
              <div className="-mx-4 overflow-x-auto px-4">
                <table className="w-full min-w-[1100px] text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                      <th className="py-2 pr-3 font-medium">Client / sale</th>
                      <th className="py-2 pr-3 text-right font-medium">Effective sale</th>
                      <th className="py-2 pr-3 text-right font-medium">Pool</th>
                      <th className="py-2 pr-3 text-right font-medium">Per month</th>
                      <th className="py-2 pr-3 text-right font-medium">Paid so far</th>
                      <th className="py-2 pr-3 font-medium">Schedule</th>
                      {data.manages && <th className="py-2 font-medium" />}
                    </tr>
                  </thead>
                  <tbody>
                    {data.rows.map((row) => (
                      <tr key={row.invoice_id} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                        <td className="max-w-[200px] py-2.5 pr-3">
                          <div className="truncate font-medium text-slate-800 dark:text-slate-100">
                            {row.client ?? '—'}
                            {/* A teammate's sale paying the leader's team %. */}
                            {row.team && (
                              <span className="ml-1.5 rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                                Team{row.seller ? ` — ${row.seller}` : ''}
                              </span>
                            )}
                          </div>
                          <div className="text-xs text-slate-400">{row.invoice_no} · {row.sale_month}</div>
                          {row.withdrawn_month && (
                            <div className="mt-0.5 text-[11px] text-slate-500">
                              {row.seller ?? 'Team'} access withdrawal ({row.withdrawn_month}) — this run is already
                              earned and pays to its scheduled end; no new sales join.
                            </div>
                          )}
                          {row.awaiting_payment && (
                            <div className="mt-0.5 text-[11px] text-violet-600 dark:text-violet-400">
                              Awaiting full payment ({row.payment_status}) — releases itself when it lands
                            </div>
                          )}
                          {row.hold && (
                            <div className="mt-0.5 text-[11px] text-orange-600 dark:text-orange-400">
                              {row.hold.kind === 'cancel' ? 'Cancelled' : 'Held'} from {row.hold.from_month}
                              {row.hold.note && <> — {row.hold.note}</>}
                            </div>
                          )}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {inr(row.effective)}
                          {(row.costs > 0 || row.tds > 0) && (
                            <div className="text-[10px] text-slate-400">
                              of {inr(row.total)}
                              {row.costs > 0 && <> − costs {inr(row.costs)}</>}
                              {row.tds > 0 && <> − TDS {inr(row.tds)}</>}
                            </div>
                          )}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                          {inr(row.pool)}
                          {/* The run's own vintage — a rate change never
                              rewrites a run already in flight. */}
                          <div className="text-[10px] text-slate-400">{row.percent}%{row.team ? ' team' : ''} over {row.months} mo</div>
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{inr(row.installment)}</td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right text-emerald-600">{inr(row.paid_so_far)}</td>
                        <td className="py-2.5 pr-3">
                          <div className="flex flex-wrap gap-0.5">
                            {row.schedule.map((cell) => (
                              <span
                                key={cell.number}
                                title={`${cell.earned_month} → ${cell.payroll_month} payroll · ${inr(cell.amount)} · ${STATUS_LABEL[cell.status]}${cell.pays_at ? ` (pays ${cell.pays_at})` : ''}`}
                                className={clsx(
                                  'flex h-5 w-6 items-center justify-center rounded text-[9px] font-semibold',
                                  STATUS_STYLE[cell.status],
                                )}
                              >
                                {cell.number}
                              </span>
                            ))}
                          </div>
                        </td>
                        {data.manages && (
                          <td className="whitespace-nowrap py-2.5 text-right">
                            {row.hold ? (
                              <Button size="sm" variant="secondary" onClick={() => release.mutate(row.hold!.uuid)} disabled={release.isPending}>
                                <PlayCircle className="size-3.5" />
                                {row.hold.kind === 'cancel' ? 'Regain' : 'Release'}
                              </Button>
                            ) : (
                              <Button size="sm" variant="secondary" onClick={() => setRuling(row)}>
                                <PauseCircle className="size-3.5" /> Hold / cancel
                              </Button>
                            )}
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </>
      )}

      {ruling && data && (
        <RulingDialog
          row={ruling}
          memberUuid={data.member.uuid}
          onClose={() => setRuling(null)}
          onDone={() => { setRuling(null); refresh() }}
        />
      )}

      {bulk && data && (
        <BulkRulingDialog
          memberUuid={data.member.uuid}
          memberName={data.member.name}
          runCount={data.rows.length}
          onClose={() => setBulk(false)}
          onDone={() => { setBulk(false); refresh() }}
        />
      )}
    </div>
  )
}

/**
 * The emergency brake: one ruling over EVERY run on this ledger — hold all
 * remaining or cancel — instead of clicking thirty rows one by one. Runs
 * already under a standing ruling are left untouched, and each can still be
 * released or regained individually afterwards.
 */
function BulkRulingDialog({ memberUuid, memberName, runCount, onClose, onDone }: {
  memberUuid: string
  memberName: string | null
  runCount: number
  onClose: () => void
  onDone: () => void
}) {
  const { toast } = useToast()
  const [scope, setScope] = useState<'remaining' | 'cancel'>('remaining')
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [recover, setRecover] = useState(false)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => crm.incentives.holdAll({
      member_uuid: memberUuid,
      scope, month,
      recover: scope === 'cancel' ? recover : false,
      note: note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Stop ALL of ${memberName ?? 'this member'}’s runs?`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <p className="text-sm text-slate-500">
          One ruling over all {runCount} run{runCount === 1 ? '' : 's'} on this ledger. Runs already held or
          cancelled are left as they are; each can be released one by one afterwards.
        </p>

        <div className="space-y-2">
          {([
            ['remaining', 'Hold everything', 'Every run stops and piles up. Releasing a run pays its withheld months as one arrear.'],
            ['cancel', 'Cancel everything', 'Every run stops for good. Each can be regained later — future months resume, the cancelled months are gone.'],
          ] as const).map(([key, label, hint]) => (
            <label key={key} className={clsx(
              'flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm transition',
              scope === key
                ? 'border-emerald-400 bg-emerald-50/50 dark:border-emerald-500 dark:bg-emerald-500/5'
                : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/40',
            )}>
              <input type="radio" checked={scope === key} onChange={() => setScope(key)} className="mt-0.5 accent-emerald-600" />
              <span>
                <span className="font-medium text-slate-800 dark:text-slate-100">{label}</span>
                <span className="block text-xs text-slate-400">{hint}</span>
              </span>
            </label>
          ))}
        </div>

        {scope === 'cancel' && (
          <label className="flex items-start gap-2 rounded-xl bg-red-50/60 p-3 text-sm text-slate-600 dark:bg-red-500/5 dark:text-slate-300">
            <input type="checkbox" checked={recover} onChange={(e) => setRecover(e.target.checked)} className="mt-0.5 size-4 accent-red-500" />
            <span>
              <span className="font-medium text-slate-800 dark:text-slate-100">Also recover what was already paid</span>
              <span className="block text-xs text-slate-400">
                Every run&rsquo;s paid installments come back as a minus on the next slip, computed from the payroll record.
              </span>
            </span>
          </label>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>From month</Label>
            <Input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Reason</Label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} className="w-full" placeholder="goes on the trail" />
          </div>
        </div>

        <Button
          className="w-full"
          variant="danger"
          disabled={!month || mutation.isPending}
          onClick={() => { setError(null); mutation.mutate() }}
        >
          {mutation.isPending ? 'Saving…' : scope === 'cancel' ? `Cancel all ${runCount} runs` : `Hold all ${runCount} runs`}
        </Button>
      </div>
    </Modal>
  )
}

/**
 * Stopping one sale's run. Three strengths, exactly as asked: this month
 * only (pays next month as an arrear on its own), all remaining months
 * (pays out whenever released), or cancel (regainable, but the cancelled
 * months are gone).
 */
function RulingDialog({ row, memberUuid, onClose, onDone }: {
  row: CrmIncentiveLedgerRow
  memberUuid: string
  onClose: () => void
  onDone: () => void
}) {
  const { toast } = useToast()
  const [scope, setScope] = useState<'once' | 'remaining' | 'cancel'>('once')
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [recover, setRecover] = useState(false)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => crm.incentives.hold({
      member_uuid: memberUuid,
      invoice_uuid: row.invoice_uuid,
      scope, month,
      recover: scope === 'cancel' ? recover : false,
      note: note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`${row.client ?? row.invoice_no} — stop this run?`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <p className="text-sm text-slate-500">
          {inr(row.installment)}/month from the {row.sale_month} sale of {inr(row.effective)}.
        </p>

        <div className="space-y-2">
          {([
            ['once', 'Hold this month only', 'That installment pays NEXT month automatically, remarked as an arrear incentive release.'],
            ['remaining', 'Hold all remaining months', 'Installments stop and pile up. Releasing the hold pays everything withheld as one arrear.'],
            ['cancel', 'Cancel the remaining incentive', 'The run stops for good. You can regain it later — future months resume, but the cancelled months are gone.'],
          ] as const).map(([key, label, hint]) => (
            <label key={key} className={clsx(
              'flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm transition',
              scope === key
                ? 'border-emerald-400 bg-emerald-50/50 dark:border-emerald-500 dark:bg-emerald-500/5'
                : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/40',
            )}>
              <input type="radio" checked={scope === key} onChange={() => setScope(key)} className="mt-0.5 accent-emerald-600" />
              <span>
                <span className="font-medium text-slate-800 dark:text-slate-100">{label}</span>
                <span className="block text-xs text-slate-400">{hint}</span>
              </span>
            </label>
          ))}
        </div>

        {scope === 'cancel' && (
          <label className="flex items-start gap-2 rounded-xl bg-red-50/60 p-3 text-sm text-slate-600 dark:bg-red-500/5 dark:text-slate-300">
            <input type="checkbox" checked={recover} onChange={(e) => setRecover(e.target.checked)} className="mt-0.5 size-4 accent-red-500" />
            <span>
              <span className="font-medium text-slate-800 dark:text-slate-100">Also recover what was already paid</span>
              <span className="block text-xs text-slate-400">
                The sale came back in full, so its paid installments come back too — computed from the payroll
                record, shown as a minus on the next slip, remarked &ldquo;incentive recovery (sale returned)&rdquo;.
              </span>
            </span>
          </label>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>{scope === 'once' ? 'Which month' : 'From month'}</Label>
            <Input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Reason</Label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} className="w-full" placeholder="goes on the trail" />
          </div>
        </div>

        <Button
          className="w-full"
          disabled={!month || mutation.isPending}
          onClick={() => { setError(null); mutation.mutate() }}
        >
          {mutation.isPending ? 'Saving…' : scope === 'cancel' ? 'Cancel the run' : 'Hold'}
        </Button>
      </div>
    </Modal>
  )
}
