import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CalendarClock, Pause, Play, Zap } from 'lucide-react'
import { clsx } from 'clsx'
import { crm } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Pager, Select, Spinner } from '../../components/ui'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })

const STATUS_STYLES: Record<string, string> = {
  active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
  paused: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  completed: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  cancelled: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
}

/**
 * Subscriptions: every document told to happen again. The schedule copies its
 * source document each cycle, so "edit next month's bill" means editing that
 * document — this screen manages when, not what.
 */
export default function CrmRecurringPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'recurring', status, page],
    queryFn: () => crm.recurring.list({ status: status || undefined, page }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'recurring'] })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'pause' | 'resume' | 'cancel' }) =>
      crm.recurring.decide(uuid, action),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const runMutation = useMutation({
    mutationFn: (uuid: string) => crm.recurring.run(uuid),
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['crm'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Recurring billing</h1>
        <p className="text-sm text-slate-500">
          Documents that repeat on a schedule. Start one from any proforma or invoice — open it and press Repeat.
        </p>
      </div>

      {data && (
        <div className="grid grid-cols-3 gap-3">
          {[
            { label: 'Active', value: data.summary.active },
            { label: 'Paused', value: data.summary.paused },
            { label: 'Due this week', value: data.summary.due_this_week, alert: data.summary.due_this_week > 0 },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className={clsx('text-lg font-semibold', s.alert ? 'text-amber-600' : 'text-slate-900 dark:text-white')}>{s.value}</div>
              <div className="text-xs font-medium text-slate-600 dark:text-slate-300">{s.label}</div>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All schedules</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title="Nothing repeats yet"
            hint="Open a proforma or invoice and press Repeat to bill it on a schedule."
          />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[920px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Copies</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  <th className="py-2 pr-3 text-right font-medium">Amount</th>
                  <th className="py-2 pr-3 font-medium">Cadence</th>
                  <th className="py-2 pr-3 font-medium">Next run</th>
                  <th className="py-2 pr-3 font-medium">Raised</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((s) => (
                  <tr key={s.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="py-2.5 pr-3">
                      {s.source ? (
                        <Link to={`/crm/invoices/${s.source.uuid}`} className="font-medium text-emerald-600 hover:underline">
                          {s.source.number}
                        </Link>
                      ) : '—'}
                      <div className="text-xs capitalize text-slate-400">{s.source?.kind}</div>
                    </td>
                    <td className="max-w-[200px] truncate py-2.5 pr-3">{s.client?.company_name ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">
                      {s.source ? inr(s.source.total) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      {s.frequency_label}
                      <div className="text-xs text-slate-400">
                        {[s.auto_email && 'e-mailed', s.auto_payment_link && 'pay link'].filter(Boolean).join(' · ') || 'raised only'}
                      </div>
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      {/* A one-time row's whole story is its chosen date —
                          shown whether it has run yet or not. */}
                      {s.frequency === 'once' ? (
                        <>
                          {s.starts_on}
                          <div className="text-xs text-slate-400">
                            {s.status === 'active' ? 'raises on this date' : 'invoice dated this day'}
                          </div>
                        </>
                      ) : (
                        <>
                          {s.status === 'active' ? s.next_run_on : <span className="text-slate-300 dark:text-slate-600">—</span>}
                          {s.ends_on && <div className="text-xs text-slate-400">until {s.ends_on}</div>}
                          {s.max_occurrences !== null && (
                            <div className="text-xs text-slate-400">
                              {/* A contract counts its original document too. */}
                              {s.counts_source
                                ? `${s.occurrences + 1} of ${s.max_occurrences + 1} incl. the original`
                                : `${s.occurrences} of ${s.max_occurrences}`}
                            </div>
                          )}
                        </>
                      )}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className="tabular-nums">{s.occurrences}×</span>
                      {s.last_invoice && (
                        <div className="text-xs">
                          <Link to={`/crm/invoices/${s.last_invoice.uuid}`} className="text-emerald-600 hover:underline">
                            {s.last_invoice.number}
                          </Link>
                          <span className="text-slate-400"> · {s.last_invoice.payment_status}</span>
                        </div>
                      )}
                      {s.last_error && (
                        <div className="max-w-[180px] truncate text-xs text-red-500" title={s.last_error}>{s.last_error}</div>
                      )}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-medium capitalize', STATUS_STYLES[s.status])}>
                        {s.status}
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      <div className="flex justify-end gap-1">
                        {s.status === 'active' && (
                          <>
                            <Button
                              size="sm"
                              variant="secondary"
                              title="Raise this cycle now"
                              disabled={runMutation.isPending}
                              onClick={() => { if (confirm('Raise the next document now?')) runMutation.mutate(s.uuid) }}
                            >
                              <Zap className="size-3.5" /> Run now
                            </Button>
                            <button
                              onClick={() => decideMutation.mutate({ uuid: s.uuid, action: 'pause' })}
                              aria-label="Pause"
                              className="rounded p-1.5 text-slate-400 hover:text-amber-600"
                            >
                              <Pause className="size-4" />
                            </button>
                          </>
                        )}
                        {s.status === 'paused' && (
                          <button
                            onClick={() => decideMutation.mutate({ uuid: s.uuid, action: 'resume' })}
                            aria-label="Resume"
                            className="rounded p-1.5 text-slate-400 hover:text-emerald-600"
                          >
                            <Play className="size-4" />
                          </button>
                        )}
                        {['active', 'paused'].includes(s.status) && (
                          <button
                            onClick={() => {
                              if (confirm('Cancel this schedule? Documents already raised stay.')) {
                                decideMutation.mutate({ uuid: s.uuid, action: 'cancel' })
                              }
                            }}
                            aria-label="Cancel"
                            className="rounded p-1.5 text-slate-400 hover:text-red-500"
                          >
                            <Ban className="size-4" />
                          </button>
                        )}
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

      <p className="flex items-center gap-2 text-xs text-slate-400">
        <CalendarClock className="size-4" />
        Due documents are raised each morning. A paused schedule skips its missed cycles on resume — nothing is billed in a lump.
      </p>
    </div>
  )
}
