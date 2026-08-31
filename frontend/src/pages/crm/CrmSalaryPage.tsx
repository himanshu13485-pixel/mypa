import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Download, PlayCircle, RefreshCw, Trash2, Wallet } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmSalarySlip } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Label, Modal, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export default function CrmSalaryPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [editing, setEditing] = useState<CrmSalarySlip | null>(null)
  const [viewing, setViewing] = useState<CrmSalarySlip | null>(null)
  // The payout run: tick the pending slips, mark them all paid in one act.
  const [selected, setSelected] = useState<string[]>([])
  // Between dates: read a whole period at once instead of one month.
  const [period, setPeriod] = useState(false)
  const [monthFrom, setMonthFrom] = useState('')
  const [monthTo, setMonthTo] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'salary', year, month, period, monthFrom, monthTo],
    queryFn: () => crm.salary.list(period && monthFrom && monthTo
      ? { month_from: monthFrom, month_to: monthTo }
      : { year, month }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'salary'] })

  const generateMutation = useMutation({
    mutationFn: (refresh: boolean) => crm.salary.generate(year, month, refresh),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.salary.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const markPaidMutation = useMutation({
    mutationFn: (uuid: string) => crm.salary.update(uuid, { status: 'paid' }),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const bulkPaidMutation = useMutation({
    mutationFn: (uuids: string[]) => crm.salary.markPaid(uuids),
    onSuccess: (res) => { setSelected([]); refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const downloadPdf = async (s: CrmSalarySlip) => {
    try {
      const blob = await crm.salary.pdf(s.uuid)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'payslip-' + (s.member?.name ?? 'employee').toLowerCase().split(' ').join('-')
        + '-' + s.year + '-' + String(s.month).padStart(2, '0') + '.pdf'
      a.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const recalcMutation = useMutation({
    mutationFn: (uuid: string) => crm.salary.recalculate(uuid),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const manages = data?.manages ?? false
  const years = Array.from({ length: 5 }, (_, i) => now.getFullYear() - 2 + i)

  // Only what is still pending can be ticked; stale ticks (paid meanwhile,
  // month switched) simply fall out of the count.
  const pendingUuids = (data?.data ?? []).filter((s) => s.status === 'pending').map((s) => s.uuid)
  const ticked = selected.filter((u) => pendingUuids.includes(u))
  const toggle = (uuid: string) =>
    setSelected((cur) => (cur.includes(uuid) ? cur.filter((u) => u !== uuid) : [...cur, uuid]))
  const toggleAll = () => setSelected(ticked.length === pendingUuids.length ? [] : pendingUuids)

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Salary</h1>
          <p className="text-sm text-slate-500">
            {manages ? 'Monthly payroll run — bank details are snapshotted into each slip.' : 'Your salary slips.'}
            {!period && (
              <>
                {' '}Salary for <span className="font-medium text-slate-700 dark:text-slate-200">{MONTHS[month - 1]} {year}</span>,
                released in <span className="font-medium text-slate-700 dark:text-slate-200">{MONTHS[month % 12]} {month === 12 ? year + 1 : year}</span>.
              </>
            )}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => setPeriod((v) => !v)}
            className="rounded-xl border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800/60"
          >
            {period ? 'Single month' : 'Between dates'}
          </button>
          {period ? (
            <>
              <Input type="month" value={monthFrom} onChange={(e) => setMonthFrom(e.target.value)} aria-label="From month" />
              <Input type="month" value={monthTo} onChange={(e) => setMonthTo(e.target.value)} aria-label="To month" />
            </>
          ) : (
            <>
              <Select value={month} onChange={(e) => setMonth(Number(e.target.value))}>
                {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
              </Select>
              <Select value={year} onChange={(e) => setYear(Number(e.target.value))}>
                {years.map((y) => <option key={y} value={y}>{y}</option>)}
              </Select>
            </>
          )}
          {manages && (
            <>
            <Button onClick={() => generateMutation.mutate(false)} disabled={generateMutation.isPending}>
              <PlayCircle className="size-4" /> {generateMutation.isPending ? 'Generating…' : 'Generate slips'}
            </Button>
            {(data?.data.length ?? 0) > 0 && (
              <Button
                variant="secondary"
                disabled={generateMutation.isPending}
                onClick={() => {
                  if (confirm('Rebuild every PENDING slip for this month from the current CTC structures and plans? Paid slips are never touched.')) {
                    generateMutation.mutate(true)
                  }
                }}
              >
                Rebuild pending
              </Button>
            )}
            </>
          )}
        </div>
      </div>

      {data && manages && data.data.length > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Net payroll (with incentive)', value: inr(data.totals.net) },
            { label: 'Without incentive', value: inr(data.totals.net_without_incentive) },
            { label: 'Incentives', value: inr(data.totals.incentive) },
            { label: 'Paid out', value: inr(data.totals.paid) },
            { label: 'Pending', value: inr(data.totals.pending) },
            { label: 'Deductions', value: inr(data.totals.deductions) },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className="text-lg font-semibold text-slate-900 dark:text-white">{s.value}</div>
              <div className="text-xs text-slate-500">{s.label}</div>
            </Card>
          ))}
        </div>
      )}

      {data && manages && data.data.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Net salary by person</h2>
            <HBarChart data={data.data.map((s) => ({ label: s.member?.name ?? '—', value: Number(s.net_salary) }))} color={CHART_COLORS[3]} />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Payout status</h2>
            <DonutChart
              data={[
                { label: 'Paid', value: data.totals.paid, color: CHART_COLORS[0] },
                { label: 'Pending', value: data.totals.pending, color: CHART_COLORS[2] },
              ]}
              centerLabel="₹ payroll"
            />
          </Card>
        </div>
      )}

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title={`No slips for ${MONTHS[month - 1]} ${year}`}
            hint={manages ? 'Generate slips to start the payroll run.' : 'Your slip appears once payroll is generated.'}
          />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            {manages && pendingUuids.length > 0 && (
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/50">
                <span className="text-slate-500">
                  {ticked.length > 0
                    ? <>{ticked.length} of {pendingUuids.length} pending selected — {inr(
                        data.data.filter((s) => ticked.includes(s.uuid)).reduce((t, s) => t + Number(s.net_salary), 0),
                      )}</>
                    : <>Tick pending slips to pay several at once.</>}
                </span>
                <Button
                  variant="secondary"
                  disabled={ticked.length === 0 || bulkPaidMutation.isPending}
                  onClick={() => {
                    if (confirm(`Mark ${ticked.length} pending slip${ticked.length === 1 ? '' : 's'} as paid today?`)) {
                      bulkPaidMutation.mutate(ticked)
                    }
                  }}
                >
                  <CheckCircle2 className="size-4" />
                  {bulkPaidMutation.isPending ? 'Marking…' : `Mark ${ticked.length || ''} paid`.replace('  ', ' ')}
                </Button>
              </div>
            )}
            <table className="w-full min-w-[1080px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  {manages && (
                    <th className="w-8 py-2 pr-2">
                      {pendingUuids.length > 0 && (
                        <input
                          type="checkbox"
                          aria-label="Select all pending slips"
                          checked={ticked.length > 0 && ticked.length === pendingUuids.length}
                          onChange={toggleAll}
                          className="size-3.5 accent-emerald-600"
                        />
                      )}
                    </th>
                  )}
                  <th className="py-2 pr-3 font-medium">Employee</th>
                  <th className="py-2 pr-3 font-medium">Attendance</th>
                  <th className="py-2 pr-3 text-right font-medium">Monthly</th>
                  <th className="py-2 pr-3 text-right font-medium">Payable</th>
                  <th className="py-2 pr-3 text-right font-medium">Additions</th>
                  <th className="py-2 pr-3 text-right font-medium">Deductions</th>
                  <th className="py-2 pr-3 text-right font-medium">Incentive</th>
                  <th className="py-2 pr-3 text-right font-medium">Net w/o inc.</th>
                  <th className="py-2 pr-3 text-right font-medium">Net</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  {manages && <th className="py-2 font-medium" />}
                </tr>
              </thead>
              <tbody>
                {data.data.map((s) => (
                  <tr key={s.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    {manages && (
                      <td className="py-2.5 pr-2">
                        {s.status === 'pending' && (
                          <input
                            type="checkbox"
                            aria-label={`Select ${s.member?.name ?? 'slip'}`}
                            checked={ticked.includes(s.uuid)}
                            onChange={() => toggle(s.uuid)}
                            className="size-3.5 accent-emerald-600"
                          />
                        )}
                      </td>
                    )}
                    <td className="py-2.5 pr-3">
                      <button
                        onClick={() => setViewing(s)}
                        className="block text-left font-medium text-emerald-600 hover:underline"
                      >
                        {s.member?.name ?? '—'}
                        {period && <span className="ml-1 text-xs font-normal text-slate-400">{MONTHS[s.month - 1]?.slice(0, 3)} {s.year}</span>}
                      </button>
                      <div className="text-xs text-slate-400">
                        {[s.bank_name, s.account_no ? '…' + s.account_no.slice(-4) : null].filter(Boolean).join(' ')}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3 text-xs text-slate-500">
                      {s.attendance
                        ? <>{s.attendance.days}d · {s.attendance.late} late · {s.attendance.half_day} half</>
                        : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(s.monthly_salary)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(s.payable)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-emerald-600">
                      {Number(s.additions) ? '+' + inr(s.additions).slice(1) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-red-500" title={s.deduction_note ?? ''}>
                      {Number(s.deductions) ? '−' + inr(s.deductions).slice(1) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-emerald-600">
                      {Number(s.incentive_amount) ? inr(s.incentive_amount) : '—'}
                      {s.incentive_month && (
                        <div className="text-[10px] font-normal text-slate-400">for {s.incentive_month}</div>
                      )}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">
                      {s.net_without_incentive !== null ? inr(s.net_without_incentive) : inr(s.net_salary)}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-semibold">{inr(s.net_salary)}</td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        s.status === 'paid'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                      )}>
                        {s.status === 'paid' ? `Paid ${s.paid_on ?? ''}` : 'Pending'}
                      </span>
                    </td>
                    {manages && (
                      <td className="py-2.5 text-right">
                        <button
                          onClick={() => downloadPdf(s)}
                          aria-label="Download payslip"
                          title="Download payslip PDF"
                          className="rounded p-1.5 text-slate-400 hover:text-emerald-600"
                        >
                          <Download className="size-4" />
                        </button>
                        {s.status === 'pending' && (
                          <>
                            {/* After a late is forgiven or a leave withdrawn:
                                recompute this one person from the calendar. */}
                            <button
                              onClick={() => recalcMutation.mutate(s.uuid)}
                              aria-label="Recalculate"
                              title="Recalculate from attendance, leave and structure as they stand now"
                              className="rounded p-1.5 text-slate-400 hover:text-emerald-600"
                              disabled={recalcMutation.isPending}
                            >
                              <RefreshCw className={clsx('size-4', recalcMutation.isPending && 'animate-spin')} />
                            </button>
                            <button onClick={() => markPaidMutation.mutate(s.uuid)} aria-label="Mark paid" title="Mark paid" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                              <CheckCircle2 className="size-4" />
                            </button>
                            <button onClick={() => { if (confirm('Remove this slip?')) deleteMutation.mutate(s.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                              <Trash2 className="size-4" />
                            </button>
                          </>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing && (
        <SlipModal slip={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); refresh() }} />
      )}

      {viewing && (
        <BreakdownModal
          slip={viewing}
          canEdit={manages}
          onEdit={() => { setViewing(null); setEditing(viewing) }}
          onDownload={() => downloadPdf(viewing)}
          onClose={() => setViewing(null)}
        />
      )}
    </div>
  )
}

function SlipModal({ slip, onClose, onDone }: { slip: CrmSalarySlip; onClose: () => void; onDone: () => void }) {
  const { toast, toastError } = useToast()
  const [form, setForm] = useState({
    payable: String(Number(slip.payable)),
    additions: Number(slip.additions) ? String(Number(slip.additions)) : '',
    deductions: Number(slip.deductions) ? String(Number(slip.deductions)) : '',
    deduction_note: slip.deduction_note ?? '',
    bank_name: slip.bank_name ?? '',
    account_holder: slip.account_holder ?? '',
    account_no: slip.account_no ?? '',
    ifsc: slip.ifsc ?? '',
    status: slip.status,
    payment_mode: slip.payment_mode ?? '',
  })

  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  const net = (Number(form.payable) || 0) + (Number(form.additions) || 0) - (Number(form.deductions) || 0)

  const mutation = useMutation({
    mutationFn: () =>
      crm.salary.update(slip.uuid, {
        payable: Number(form.payable) || 0,
        additions: form.additions ? Number(form.additions) : 0,
        deductions: form.deductions ? Number(form.deductions) : 0,
        deduction_note: form.deduction_note || null,
        bank_name: form.bank_name || null,
        account_holder: form.account_holder || null,
        account_no: form.account_no || null,
        ifsc: form.ifsc || null,
        status: form.status,
        payment_mode: form.payment_mode || null,
      }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal
      title={`${slip.member?.name ?? 'Slip'} — salary for ${MONTHS[slip.month - 1]} ${slip.year}, released ${MONTHS[slip.month % 12]} ${slip.month === 12 ? slip.year + 1 : slip.year}`}
      onClose={onClose}
      wide
    >
      <div className="space-y-3">
        {slip.attendance && (
          <p className="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60">
            <Wallet className="mr-1 inline size-3.5" />
            Attendance: {slip.attendance.days} punched days · {slip.attendance.present} present · {slip.attendance.late} late · {slip.attendance.half_day} half days · {slip.attendance.holiday} holidays
          </p>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Payable (₹)</Label>
            <Input type="number" min="0" value={form.payable} onChange={(e) => set('payable', e.target.value)} className="w-full" />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <Label>Additions</Label>
              <Input type="number" min="0" value={form.additions} onChange={(e) => set('additions', e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Deductions</Label>
              <Input type="number" min="0" value={form.deductions} onChange={(e) => set('deductions', e.target.value)} className="w-full" />
            </div>
          </div>
          <div className="sm:col-span-2">
            <Label>Deduction note</Label>
            <Input value={form.deduction_note} onChange={(e) => set('deduction_note', e.target.value)} placeholder="2 absent days" className="w-full" />
          </div>
          <div>
            <Label>Bank name</Label>
            <Input value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Account holder</Label>
            <Input value={form.account_holder} onChange={(e) => set('account_holder', e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Account no.</Label>
            <Input value={form.account_no} onChange={(e) => set('account_no', e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>IFSC</Label>
            <Input value={form.ifsc} onChange={(e) => set('ifsc', e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Status</Label>
            <Select value={form.status} onChange={(e) => set('status', e.target.value as 'pending' | 'paid')} className="w-full">
              <option value="pending">Pending</option>
              <option value="paid">Paid</option>
            </Select>
          </div>
          <div>
            <Label>Payment mode</Label>
            <Input value={form.payment_mode} onChange={(e) => set('payment_mode', e.target.value)} placeholder="NEFT" className="w-full" />
          </div>
        </div>
        <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-2.5 text-sm dark:bg-slate-800/60">
          <span className="text-slate-500">Net salary</span>
          <span className="text-base font-semibold">{inr(net)}</span>
        </div>
        <Button className="w-full" disabled={mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save slip'}
        </Button>
      </div>
    </Modal>
  )
}

/**
 * The slip, line by line — earnings down to the net, the way the company's
 * sheet reads. The incentive block shows its own working: the sale, what
 * the sale cost, and the plan applied to what was left.
 */
function BreakdownModal({ slip, canEdit, onEdit, onDownload, onClose }: {
  slip: CrmSalarySlip
  canEdit: boolean
  onEdit: () => void
  onDownload: () => void
  onClose: () => void
}) {
  const inc = slip.incentive_breakdown
  const line = (label: string, amount: number | string, tone?: string) => (
    <div className="flex items-baseline justify-between gap-2 py-1 text-sm">
      <span className="text-slate-500">{label}</span>
      <span className={clsx('tabular-nums', tone ?? 'text-slate-800 dark:text-slate-100')}>{inr(amount)}</span>
    </div>
  )

  return (
    <Modal title={`${slip.member?.name ?? 'Slip'} — ${MONTHS[slip.month - 1]} ${slip.year}`} onClose={onClose} wide>
      <div className="space-y-4">
        {slip.month_days !== null && (
          <p className="text-xs text-slate-400">
            {slip.payable_days} payable of {slip.month_days} days
            {Number(slip.lop_days) > 0 && <> · {Number(slip.lop_days)} without pay</>}
          </p>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Earnings</h3>
            {slip.earnings.length === 0
              ? line('Payable', slip.payable)
              : slip.earnings.map((l) => <div key={l.key}>{line(l.label, l.amount)}</div>)}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Gross payable', slip.payable, 'font-semibold')}
            </div>
          </div>
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Deductions</h3>
            {slip.deduction_lines.length === 0
              ? <p className="py-1 text-sm text-slate-400">None.</p>
              : slip.deduction_lines.map((l) => <div key={l.key}>{line(l.label, l.amount, 'text-red-500')}</div>)}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Total deductions', slip.deductions, 'font-semibold text-red-500')}
            </div>
          </div>
        </div>

        {inc && inc.plan === 'spread' && (inc.installments?.length ?? 0) > 0 && (
          <div className="rounded-xl bg-emerald-50/60 p-3 dark:bg-emerald-500/5">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Incentive — spread installments this month
            </h3>
            {inc.installments!.map((i) => (
              <div key={i.sale_month} className="flex items-baseline justify-between gap-2 py-1 text-sm">
                <span className="text-slate-500">
                  {i.sale_month} sale {inr(i.effective_sale)} — installment {i.number} of {i.of}
                  {i.team && <span className="ml-1 text-sky-600">(team{i.seller ? ` — ${i.seller}` : ''})</span>}
                </span>
                <span className="tabular-nums text-emerald-600">{inr(i.installment + (i.team_installment ?? 0))}</span>
              </div>
            ))}
            <div className="mt-1 flex items-baseline justify-between border-t border-emerald-200/60 pt-1 text-sm font-medium dark:border-emerald-500/20">
              <span className="text-slate-500">Total this month</span>
              <span className="tabular-nums text-emerald-600">{inr(inc.total)}</span>
            </div>
          </div>
        )}

        {inc && inc.plan !== 'none' && inc.plan !== 'spread' && inc.self && (
          <div className="rounded-xl bg-emerald-50/60 p-3 dark:bg-emerald-500/5">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Incentive working — {inc.incentive_month}
            </h3>
            {line('Own sales', inc.self.total)}
            {inc.self.commission > 0 && line('Less client commission', -inc.self.commission, 'text-red-500')}
            {inc.self.charges > 0 && line('Less gateway charges', -inc.self.charges, 'text-red-500')}
            {line('Effective sale', inc.self.effective)}
            {line('Own incentive', inc.self_incentive, 'font-medium text-emerald-600')}
            {inc.team && inc.team_incentive > 0 && (
              <>
                {line('Team effective sale', inc.team.effective)}
                {line('Team incentive', inc.team_incentive, 'font-medium text-emerald-600')}
              </>
            )}
          </div>
        )}

        <div className="rounded-xl bg-slate-100 px-4 py-2.5 dark:bg-slate-800/60">
          <div className="flex items-baseline justify-between text-sm">
            <span className="text-slate-500">Net without incentive</span>
            <span className="font-medium tabular-nums">{inr(slip.net_without_incentive ?? slip.net_salary)}</span>
          </div>
          <div className="flex items-baseline justify-between">
            <span className="text-sm text-slate-500">Net salary</span>
            <span className="text-lg font-semibold tabular-nums">{inr(slip.net_salary)}</span>
          </div>
        </div>

        <div className="flex gap-2">
          {/* Everyone may take their slip away as a file. */}
          <Button className="flex-1" variant="secondary" onClick={onDownload}>
            <Download className="size-4" /> Download payslip
          </Button>
          {canEdit && slip.status === 'pending' && (
            <Button className="flex-1" variant="secondary" onClick={onEdit}>Adjust this slip</Button>
          )}
        </div>
      </div>
    </Modal>
  )
}
