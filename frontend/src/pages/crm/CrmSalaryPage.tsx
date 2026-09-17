import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Download, FileSpreadsheet, MessageSquarePlus, PlayCircle, RefreshCw, Trash2, Wallet } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmNote, type CrmSalarySlip } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Label, Modal, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'
import NotesModal from './NotesModal'
import { saveBlob } from '../../lib/download'

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
  const [exporting, setExporting] = useState(false)
  // The payout run: tick the pending slips, mark them all paid in one act.
  const [selected, setSelected] = useState<string[]>([])
  // Between dates: read a whole period at once instead of one month.
  const [period, setPeriod] = useState(false)
  const [monthFrom, setMonthFrom] = useState('')
  const [monthTo, setMonthTo] = useState('')
  /*
   * Whose pay is being written about: one person's month, or the run as a
   * whole (member null). Held here rather than in the row, so the panel
   * survives the refetch that follows saving a note.
   */
  const [noting, setNoting] = useState<{ member: CrmSalarySlip['member']; year: number; month: number } | null>(null)

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
          {data?.can_export && (data?.data.length ?? 0) > 0 && (
            <Button variant="secondary" onClick={() => setExporting(true)}>
              <FileSpreadsheet className="size-4" /> Excel
            </Button>
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
            // What the payroll costs: net plus every deduction held back.
            { label: 'CTC (cost to company)', value: inr(data.totals.ctc) },
            { label: 'Net payroll (with incentive)', value: inr(data.totals.net) },
            { label: 'Without incentive', value: inr(data.totals.net_without_incentive) },
            { label: 'Incentives', value: inr(data.totals.incentive) },
            { label: 'Paid out', value: inr(data.totals.paid) },
            { label: 'Pending', value: inr(data.totals.pending) },
            { label: 'Deductions', value: inr(data.totals.deductions + (data.totals.other_deductions ?? 0)) },
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
            <table className="w-full min-w-[1160px] text-sm">
              {/* The run as a whole: a late payout, a bonus round, a change
                  of bank - said once where the register is read. */}
              <caption className="caption-top pb-2 text-left">
                {manages && !period && (
                  <span className="flex flex-wrap items-center gap-2">
                    <button
                      onClick={() => setNoting({ member: null, year: data.year, month: data.month })}
                      className={clsx('flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs',
                        data.notes?.length
                          ? 'text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-500/10'
                          : 'text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800')}
                    >
                      <MessageSquarePlus className="size-3.5" />
                      {data.notes?.length
                        ? `${data.notes.length} note${data.notes.length > 1 ? 's' : ''} on this month`
                        : 'Note on this month'}
                    </button>
                    {data.notes?.map((n) => (
                      <span key={n.id} className="rounded-lg bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        {n.body} <span className="opacity-60">— {n.author}</span>
                      </span>
                    ))}
                  </span>
                )}
              </caption>
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
                  <th className="py-2 pr-3 text-right font-medium" title="Cost to company: net salary plus every deduction">CTC</th>
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
                      {/* Read beside the name, not behind a click: a remark
                          nobody sees is one that gets asked for again. */}
                      {s.notes?.map((n) => (
                        <div key={n.id} className="text-[11px] leading-snug text-amber-600/90 dark:text-amber-400/80">
                          {n.body} <span className="text-slate-400">— {n.author}</span>
                        </div>
                      ))}
                    </td>
                    <td className="py-2.5 pr-3 text-xs text-slate-500">
                      {s.attendance
                        ? <>{s.attendance.days}d · {s.attendance.late} late · {s.attendance.half_day} half</>
                        : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(s.monthly_salary)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(s.payable)}</td>
                    <td
                      className="whitespace-nowrap py-2.5 pr-3 text-right text-emerald-600"
                      title={[s.addition_note, Number(s.reimbursements) ? `Includes reimbursements ${inr(s.reimbursements)}` : null].filter(Boolean).join(' · ')}
                    >
                      {Number(s.additions) + Number(s.reimbursements)
                        ? '+' + inr(Number(s.additions) + Number(s.reimbursements)).slice(1)
                        : '—'}
                    </td>
                    <td
                      className="whitespace-nowrap py-2.5 pr-3 text-right text-red-500"
                      title={Number(s.other_deductions) ? `Includes other deductions ${inr(s.other_deductions)}${s.other_deduction_note ? ` — ${s.other_deduction_note}` : ''}` : ''}
                    >
                      {Number(s.deductions) + Number(s.other_deductions)
                        ? '−' + inr(Number(s.deductions) + Number(s.other_deductions)).slice(1)
                        : '—'}
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
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">{inr(s.ctc)}</td>
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
                        {/* Every salary, paid or pending: the record of why
                            it was what it was outlives the payout. */}
                        <button
                          onClick={() => setNoting({ member: s.member, year: s.year, month: s.month })}
                          aria-label={`Notes on ${s.member?.name ?? 'this salary'}`}
                          title="Remarks kept for the office"
                          className={clsx('rounded p-1.5',
                            s.notes?.length ? 'text-amber-500 hover:text-amber-600' : 'text-slate-400 hover:text-emerald-600')}
                        >
                          <MessageSquarePlus className="size-4" />
                        </button>
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

      {exporting && data && (
        <ExportModal
          people={Array.from(new Map(
            data.data.flatMap((s) => (s.member ? [[s.member.uuid, { uuid: s.member.uuid, name: s.member.name }] as const] : [])),
          ).values())}
          params={period && monthFrom && monthTo ? { month_from: monthFrom, month_to: monthTo } : { year, month }}
          label={period && monthFrom && monthTo ? `${monthFrom} to ${monthTo}` : `${MONTHS[month - 1]} ${year}`}
          onClose={() => setExporting(false)}
        />
      )}

      {noting && (
        <NotesModal
          title={noting.member?.name ?? 'this month'}
          subtitle={`${MONTHS[noting.month - 1]} ${noting.year}`}
          notes={salaryNotes(data, noting)}
          placeholder={noting.member
            ? 'Why this salary is what it is — arrears, a hold, a decision taken…'
            : 'Anything worth remembering about this payroll run…'}
          hint="Kept for the office. The employee does not see these on their slip."
          onAdd={(body) => crm.salary.addNote({
            year: noting.year, month: noting.month, member_uuid: noting.member?.uuid ?? null, body,
          }).then(refresh)}
          onRemove={(id) => crm.salary.deleteNote(id).then(refresh)}
          onClose={() => setNoting(null)}
        />
      )}
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

/**
 * The remarks on whatever is open, read out of the register itself.
 *
 * Taken from the query rather than kept in the panel, so a note saved or
 * removed shows the moment the register comes back.
 */
function salaryNotes(
  data: { data: CrmSalarySlip[]; notes?: CrmNote[] } | undefined,
  at: { member: CrmSalarySlip['member']; year: number; month: number },
): CrmNote[] {
  if (!data) return []
  if (!at.member) return data.notes ?? []

  return data.data.find((s) =>
    s.member?.uuid === at.member?.uuid && s.year === at.year && s.month === at.month)?.notes ?? []
}

function SlipModal({ slip, onClose, onDone }: { slip: CrmSalarySlip; onClose: () => void; onDone: () => void }) {
  const { toast, toastError } = useToast()
  const [form, setForm] = useState({
    payable: String(Number(slip.payable)),
    additions: Number(slip.additions) ? String(Number(slip.additions)) : '',
    addition_note: slip.addition_note ?? '',
    deductions: Number(slip.deductions) ? String(Number(slip.deductions)) : '',
    other_deductions: Number(slip.other_deductions) ? String(Number(slip.other_deductions)) : '',
    other_deduction_note: slip.other_deduction_note ?? '',
    bank_name: slip.bank_name ?? '',
    account_holder: slip.account_holder ?? '',
    account_no: slip.account_no ?? '',
    ifsc: slip.ifsc ?? '',
    status: slip.status,
    payment_mode: slip.payment_mode ?? '',
  })

  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  // PF, ESI, EDLI and the rest are worked out from the structure; they are
  // not a place to type money in. Only an old slip with no computed lines
  // still has its deductions entered by hand.
  const statutoryLocked = slip.deduction_lines.length > 0

  const net = (Number(form.payable) || 0) + (Number(form.additions) || 0) + Number(slip.reimbursements)
    - (Number(form.deductions) || 0) - (Number(form.other_deductions) || 0)

  const mutation = useMutation({
    mutationFn: () =>
      crm.salary.update(slip.uuid, {
        payable: Number(form.payable) || 0,
        additions: form.additions ? Number(form.additions) : 0,
        addition_note: form.addition_note.trim() || null,
        ...(statutoryLocked ? {} : { deductions: form.deductions ? Number(form.deductions) : 0 }),
        other_deductions: form.other_deductions ? Number(form.other_deductions) : 0,
        other_deduction_note: form.other_deduction_note.trim() || null,
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
          <div>
            <Label>Statutory deductions (PF, ESI, EDLI…)</Label>
            {statutoryLocked ? (
              <div
                title="Worked out from the salary structure. Recalculate the slip to change it."
                className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60"
              >
                <span className="tabular-nums text-slate-700 dark:text-slate-200">{inr(slip.deductions)}</span>
                <span className="text-[11px] text-slate-400">computed</span>
              </div>
            ) : (
              <Input type="number" min="0" value={form.deductions} onChange={(e) => set('deductions', e.target.value)} className="w-full" />
            )}
          </div>

          {/* Each figure typed by hand sits with the reason for it, and both
              travel together onto the slip and the payslip. */}
          <div className="rounded-xl border border-emerald-200 p-3 dark:border-emerald-900/60 sm:col-span-2">
            <div className="grid gap-3 sm:grid-cols-[11rem_1fr]">
              <div>
                <Label>Additions (₹)</Label>
                <Input type="number" min="0" value={form.additions} onChange={(e) => set('additions', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Addition note</Label>
                <Input value={form.addition_note} onChange={(e) => set('addition_note', e.target.value)} placeholder="Extra bonus, arrears…" maxLength={512} className="w-full" />
              </div>
            </div>
          </div>
          <div className="rounded-xl border border-red-200 p-3 dark:border-red-900/60 sm:col-span-2">
            <div className="grid gap-3 sm:grid-cols-[11rem_1fr]">
              <div>
                <Label>Other deductions (₹)</Label>
                <Input type="number" min="0" value={form.other_deductions} onChange={(e) => set('other_deductions', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Other deduction note</Label>
                <Input value={form.other_deduction_note} onChange={(e) => set('other_deduction_note', e.target.value)} placeholder="Canteen, salary advance, 2 absent days…" maxLength={512} className="w-full" />
              </div>
            </div>
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
        {Number(slip.reimbursements) > 0 && (
          <div className="flex items-center justify-between rounded-xl bg-emerald-50/60 px-4 py-2 text-sm dark:bg-emerald-500/5">
            <span className="text-slate-500">Reimbursements (approved claims)</span>
            <span className="tabular-nums text-emerald-600">+{inr(slip.reimbursements)}</span>
          </div>
        )}
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
  // A hand-typed figure with its reason right under it.
  const noted = (label: string, amount: number | string, note: string | null, tone: string) => (
    <div>
      {line(label, amount, tone)}
      {note && <p className="-mt-0.5 break-words pb-1 text-xs italic text-slate-400">{note}</p>}
    </div>
  )
  const additions = Number(slip.additions)
  const other = Number(slip.other_deductions)

  return (
    <Modal title={`${slip.member?.name ?? 'Slip'} — ${MONTHS[slip.month - 1]} ${slip.year}`} onClose={onClose} wide>
      <div className="space-y-4">
        {(() => {
          const monthDays = slip.month_days !== null ? Number(slip.month_days) : new Date(slip.year, slip.month, 0).getDate()
          const tiles = [
            { label: 'Days in month', value: monthDays },
            { label: 'Days present', value: slip.present_days ?? '—' },
            { label: 'Payable days', value: slip.payable_days !== null ? Number(slip.payable_days) : monthDays },
            { label: 'Without pay', value: Number(slip.lop_days) || 0, tone: Number(slip.lop_days) > 0 ? 'text-red-500' : undefined },
          ]
          return (
            <div>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {tiles.map((t) => (
                  <div key={t.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
                    <div className={clsx('text-base font-semibold tabular-nums', t.tone ?? 'text-slate-800 dark:text-slate-100')}>{t.value}</div>
                    <div className="text-xs text-slate-500">{t.label}</div>
                  </div>
                ))}
              </div>
              {(Number(slip.leave_overdrawn_days) > 0 || Number(slip.leave_covered_days) > 0 || slip.present_days == null) && (
                <p className="mt-1.5 text-xs text-slate-400">
                  {[
                    Number(slip.leave_overdrawn_days) > 0 && `${Number(slip.leave_overdrawn_days)} leave beyond the balance, unpaid`,
                    Number(slip.leave_covered_days) > 0 && `${Number(slip.leave_covered_days)} absent paid from leave balance`,
                    slip.present_days == null && 'no attendance recorded this month',
                  ].filter(Boolean).join(' · ')}
                </p>
              )}
            </div>
          )
        })()}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Earnings</h3>
            {slip.earnings.length === 0
              ? line('Payable', slip.payable)
              : slip.earnings.map((l) => <div key={l.key}>{line(l.label, l.amount)}</div>)}
            {additions > 0 && noted('Additions', additions, slip.addition_note, 'text-emerald-600')}
            {/* Approved claims paid back with this salary. */}
            {(slip.reimbursement_lines ?? []).map((r) => (
              <div key={r.uuid}>
                {noted(`Reimbursement — ${r.type}`, r.amount, [r.date, r.details].filter(Boolean).join(' · ') || null, 'text-emerald-600')}
              </div>
            ))}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Gross payable', Number(slip.payable) + additions + Number(slip.reimbursements), 'font-semibold')}
            </div>
          </div>
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Deductions</h3>
            {slip.deduction_lines.length > 0
              ? slip.deduction_lines.map((l) => <div key={l.key}>{line(l.label, l.amount, 'text-red-500')}</div>)
              : Number(slip.deductions) > 0
                ? line('Deductions', slip.deductions, 'text-red-500')
                : other <= 0 && <p className="py-1 text-sm text-slate-400">None.</p>}
            {other > 0 && noted('Other deductions', other, slip.other_deduction_note, 'text-red-500')}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Total deductions', Number(slip.deductions) + other, 'font-semibold text-red-500')}
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
          <div className="mt-1 flex items-baseline justify-between border-t border-slate-200 pt-1 text-sm dark:border-slate-700">
            <span className="text-slate-500" title="Net salary plus every deduction held back">CTC — cost to company</span>
            <span className="font-medium tabular-nums">{inr(slip.ctc)}</span>
          </div>
        </div>

        {!!slip.notes?.length && (
          <div className="mb-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
            <p className="mb-1 font-semibold uppercase tracking-wide opacity-70">Remarks</p>
            {slip.notes.map((n) => (
              <p key={n.id}>
                {n.body} <span className="opacity-60">— {n.author}, {n.at.slice(0, 10)}</span>
              </p>
            ))}
          </div>
        )}
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

/**
 * The detailed salary register as Excel: everybody, or one person, for the
 * month or period on screen. PF, ESI and the welfare fund come out with the
 * employee's share and the employer's apart.
 */
function ExportModal({ people, params, label, onClose }: {
  people: { uuid: string; name: string | null }[]
  params: Record<string, string | number>
  label: string
  onClose: () => void
}) {
  const { toastError } = useToast()
  const [member, setMember] = useState('')
  const [busy, setBusy] = useState(false)

  const download = async () => {
    setBusy(true)
    try {
      const blob = await crm.salary.exportExcel({ ...params, member: member || undefined })
      const who = people.find((p) => p.uuid === member)?.name ?? 'all-employees'
      const slug = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
      saveBlob(blob, `salary-register-${slug(who)}-${slug(label)}.xlsx`)
      onClose()
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title="Download salary register (Excel)" onClose={onClose}>
      <div className="space-y-3">
        <p className="text-sm text-slate-500">
          For {label}. One row per slip: every earning, PF, ESI and welfare fund with the employee&rsquo;s and the
          employer&rsquo;s share apart, EDLI, other deductions with their notes, net salary and CTC.
        </p>
        <div>
          <Label>Employees</Label>
          <Select value={member} onChange={(e) => setMember(e.target.value)} className="w-full">
            <option value="">All employees</option>
            {people.map((p) => <option key={p.uuid} value={p.uuid}>{p.name ?? '—'}</option>)}
          </Select>
        </div>
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button disabled={busy} onClick={download}>
            <FileSpreadsheet className="size-4" /> {busy ? 'Preparing…' : 'Download Excel'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}
