import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Check, Plus, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'

export function decisionBadge(status: string) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    status === 'approved' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    status === 'pending' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    status === 'rejected' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
    status === 'cancelled' && 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  )
}

// A day or half of one. The office does not deal in quarters.
const DURATION_LABELS: Record<string, string> = { full: 'Full day', half: 'Half day' }

export default function CrmLeavesPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const decides = crmCan(me, 'leaves', 'edit')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ category: '', duration: 'full', date_from: '', date_to: '', reason: '' })
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leaves', status, page],
    queryFn: () => crm.leaves.list({ status: status || undefined, page }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'leaves'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] })
  }

  const createMutation = useMutation({
    mutationFn: () =>
      crm.leaves.create({
        category: form.category,
        duration: form.duration,
        date_from: form.date_from,
        date_to: form.date_to || form.date_from,
        reason: form.reason || null,
      }),
    onSuccess: (res) => {
      refresh()
      setShowForm(false)
      setForm({ category: '', duration: 'full', date_from: '', date_to: '', reason: '' })
      toast(res.message ?? 'Requested.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, verdict }: { uuid: string; verdict: 'approved' | 'rejected' }) => {
      const note = verdict === 'rejected' ? prompt('Reason for rejecting (optional)') ?? undefined : undefined
      return crm.leaves.decide(uuid, verdict, note)
    },
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const cancelMutation = useMutation({
    mutationFn: (uuid: string) => crm.leaves.cancel(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Leaves</h1>
          <p className="text-sm text-slate-500">
            {data ? <>{data.summary.pending} pending · {data.summary.approved_days} days approved in view</> : 'Request and track leave.'}
          </p>
        </div>
        <Button onClick={() => { setError(null); setShowForm(true) }}>
          <Plus className="size-4" /> Request leave
        </Button>
      </div>

      {data?.summary.account && (
        <Card className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Your paid-leave account · FY {data.summary.account.label}
              </h2>
              <p className="mt-0.5 text-xs text-slate-400">
                {data.summary.account.on_probation
                  ? `On probation until ${data.summary.account.probation_ends_on ?? '—'}. Leave taken now is unpaid; you start earning ${data.summary.account.monthly_credit} day a month from ${data.summary.account.accrual_starts_on ?? 'the month after'}.`
                  : `${data.summary.account.monthly_credit} day earned on the 1st of each month. Anything left at the end of the year is paid out at a day of basic salary.`}
              </p>
            </div>
            <div className="flex gap-4">
              {[
                { label: 'Earned', value: data.summary.account.earned },
                { label: 'Taken', value: data.summary.account.taken },
                { label: 'Balance', value: data.summary.account.balance, strong: true },
              ].map((c) => (
                <div key={c.label} className="text-right">
                  <div className={clsx(
                    'text-lg font-semibold tabular-nums',
                    c.strong
                      ? (c.value > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400')
                      : 'text-slate-700 dark:text-slate-200',
                  )}>
                    {c.value}
                  </div>
                  <div className="text-xs text-slate-500">{c.label}</div>
                </div>
              ))}
            </div>
          </div>
        </Card>
      )}

      {data && data.summary.by_category.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Days by category</h2>
            <HBarChart data={data.summary.by_category.map((c) => ({ label: c.category, value: c.days }))} color={CHART_COLORS[3]} />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Requests by status</h2>
            <DonutChart
              data={data.summary.by_status.map((s) => ({
                label: s.status,
                value: s.count,
                color: { approved: CHART_COLORS[0], pending: CHART_COLORS[2], rejected: CHART_COLORS[4], cancelled: '#64748b' }[s.status],
              }))}
              centerLabel="requests"
            />
          </Card>
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <CalendarOff className="size-4 text-emerald-500" /> {decides ? 'All requests' : 'My requests'}
          </h2>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No leave requests" hint="Requests appear here once submitted." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  {decides && <th className="py-2 pr-3 font-medium">Employee</th>}
                  <th className="py-2 pr-3 font-medium">Category</th>
                  <th className="py-2 pr-3 font-medium">Dates</th>
                  <th className="py-2 pr-3 text-right font-medium">Days</th>
                  <th className="py-2 pr-3 font-medium">Reason</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((l) => (
                  <tr key={l.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    {decides && <td className="py-2.5 pr-3 font-medium">{l.member?.name ?? '—'}</td>}
                    <td className="py-2.5 pr-3">
                      {l.category}
                      <div className="text-xs text-slate-400">
                        {DURATION_LABELS[l.duration]}
                        {l.status === 'approved' && Number(l.unpaid_days) > 0 && (
                          <span className="ml-1 text-amber-600 dark:text-amber-400">
                            · {Number(l.unpaid_days)} unpaid
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      {l.date_from}{l.date_to !== l.date_from && <> → {l.date_to}</>}
                    </td>
                    <td className="py-2.5 pr-3 text-right font-medium">{Number(l.days)}</td>
                    <td className="max-w-[200px] truncate py-2.5 pr-3 text-slate-500" title={l.reason ?? ''}>{l.reason ?? '—'}</td>
                    <td className="py-2.5 pr-3">
                      <span className={decisionBadge(l.status)} title={l.decision_note ?? ''}>
                        {l.status}{l.decided_by && ` · ${l.decided_by}`}
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      {l.status === 'pending' && decides && l.member?.uuid !== me?.member?.uuid && (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" onClick={() => decideMutation.mutate({ uuid: l.uuid, verdict: 'approved' })}>
                            <Check className="size-3.5" /> Approve
                          </Button>
                          <Button size="sm" variant="secondary" onClick={() => decideMutation.mutate({ uuid: l.uuid, verdict: 'rejected' })}>
                            <X className="size-3.5" /> Reject
                          </Button>
                        </div>
                      )}
                      {l.status === 'pending' && l.member?.uuid === me?.member?.uuid && (
                        <Button size="sm" variant="ghost" onClick={() => cancelMutation.mutate(l.uuid)}>Withdraw</Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <Modal title="Request leave" onClose={() => setShowForm(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>Category</Label>
              <Select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} className="w-full">
                <option value="">Select</option>
                {masters?.leave_categories.map((c) => <option key={c} value={c}>{c}</option>)}
              </Select>
            </div>
            <div>
              <Label>Duration</Label>
              <Select value={form.duration} onChange={(e) => setForm((f) => ({ ...f, duration: e.target.value }))} className="w-full">
                {Object.entries(DURATION_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>From</Label>
                <Input type="date" value={form.date_from} onChange={(e) => setForm((f) => ({ ...f, date_from: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>To</Label>
                <Input type="date" value={form.date_to} onChange={(e) => setForm((f) => ({ ...f, date_to: e.target.value }))} className="w-full" />
              </div>
            </div>
            <div>
              <Label>Reason</Label>
              <Textarea rows={2} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} className="w-full" />
            </div>
            <Button className="w-full" disabled={!form.category || !form.date_from || createMutation.isPending} onClick={() => createMutation.mutate()}>
              {createMutation.isPending ? 'Requesting…' : 'Submit request'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
