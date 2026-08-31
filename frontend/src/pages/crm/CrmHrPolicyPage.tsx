import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Clock, Plus, Save, ScrollText, Trash2, Wallet } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmHrPolicy } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Select, Spinner } from '../../components/ui'

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

/**
 * HR Policy — the house rules in one place.
 *
 * Everything downstream reads from here: what the punch report calls Late,
 * when a late arrival becomes half a day, which days the office is shut,
 * how long probation runs, and how paid leave is earned and bought back.
 * Everyone can read it; only the Company Admin can move it.
 */
export default function CrmHrPolicyPage() {
  const { data, isLoading } = useQuery({ queryKey: ['crm', 'hr-policy'], queryFn: crm.hr.policy })

  if (isLoading || !data) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">HR Policy</h1>
        <p className="text-sm text-slate-500">
          The rules everyone is measured against — Subadmins included. Punch timings, the holiday calendar,
          probation and paid leave all read from here, so nothing downstream can quietly disagree.
          {!data.can_edit && <> You can read the policy; changing it is the Company Admin&rsquo;s.</>}
        </p>
      </div>

      <PolicyCard policy={data.policy} canEdit={data.can_edit} />
      <HolidayCalendar canManage={data.can_manage_holidays} financialYear={data.financial_year} />
      <LeaveAccounts financialYear={data.financial_year} />
    </div>
  )
}

function PolicyCard({ policy, canEdit }: { policy: CrmHrPolicy; canEdit: boolean }) {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [draft, setDraft] = useState<CrmHrPolicy>(policy)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => setDraft(policy), [policy])

  const save = useMutation({
    mutationFn: () => crm.hr.savePolicy(draft),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'hr-policy'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'punch'] })
      toast(res.message, 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const set = <K extends keyof CrmHrPolicy>(key: K, value: CrmHrPolicy[K]) =>
    setDraft((d) => ({ ...d, [key]: value }))

  const toggleDay = (day: number) =>
    setDraft((d) => ({
      ...d,
      week_off_days: d.week_off_days.includes(day)
        ? d.week_off_days.filter((x) => x !== day)
        : [...d.week_off_days, day].sort(),
    }))

  const field = (key: keyof CrmHrPolicy, label: string, hint: string, props: Record<string, unknown> = {}) => (
    <div>
      <Label>{label}</Label>
      <Input
        value={String(draft[key] ?? '')}
        onChange={(e) => set(key, (props.type === 'number' ? Number(e.target.value) : e.target.value) as never)}
        disabled={!canEdit}
        className="w-full"
        {...props}
      />
      <p className="mt-1 text-xs text-slate-400">{hint}</p>
    </div>
  )

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Clock className="size-4 text-emerald-500" /> Punch rules
      </h2>
      <ErrorNote message={error} />

      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {field('work_start', 'Day starts at', 'When the office opens.', { type: 'time' })}
        {field('work_end', 'Day ends at', 'When it closes.', { type: 'time' })}
        {field('grace_minutes', 'Late after (minutes)', 'Arriving past start + this is Late.', { type: 'number', min: 0, max: 240 })}
        {field('half_day_after_minutes', 'Half day after (minutes)', 'Arriving this far past start stops being lateness and becomes half a day.', { type: 'number', min: 0, max: 600 })}
        {field('half_day_hours', 'Half day under (hours)', 'Leaving before working this long is a half day, whenever they arrived.', { type: 'number', step: 0.5, min: 0, max: 24 })}
        {field('full_day_hours', 'Full day (hours)', 'What a whole day of work is.', { type: 'number', step: 0.5, min: 0, max: 24 })}
      </div>

      {/* Each weekday's own office hours: Mon–Fri 10:00–18:30, Saturday
          10:00–18:00 by default. Lateness is measured from THAT day's
          start; a weekly-off day needs no hours at all. */}
      <div className="mt-4">
        <Label>Office timings, day by day</Label>
        <div className="mt-1 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
          {DAY_NAMES.map((name, day) => {
            const off = draft.week_off_days.includes(day)
            const sched = draft.day_schedule?.[String(day)] ?? null
            const setSched = (patch: { start?: string; end?: string }) =>
              set('day_schedule', {
                ...(draft.day_schedule ?? {}),
                [String(day)]: {
                  start: patch.start ?? sched?.start ?? draft.work_start,
                  end: patch.end ?? sched?.end ?? draft.work_end,
                },
              })
            return (
              <div key={name} className={clsx('flex items-center gap-2 rounded-xl px-2.5 py-1.5', off ? 'bg-slate-100/60 opacity-60 dark:bg-slate-800/30' : 'bg-slate-50 dark:bg-slate-800/60')}>
                <span className="w-9 shrink-0 text-xs font-medium text-slate-500">{name.slice(0, 3)}</span>
                {off ? (
                  <span className="text-xs text-slate-400">Weekly off</span>
                ) : (
                  <>
                    <Input type="time" value={sched?.start ?? draft.work_start} onChange={(e) => setSched({ start: e.target.value })} disabled={!canEdit} className="w-full" />
                    <span className="text-slate-400">–</span>
                    <Input type="time" value={sched?.end ?? draft.work_end} onChange={(e) => setSched({ end: e.target.value })} disabled={!canEdit} className="w-full" />
                  </>
                )}
              </div>
            )
          })}
        </div>
        <p className="mt-1 text-xs text-slate-400">
          Punch lateness and half-days are measured from each day&rsquo;s own start time.
        </p>
      </div>

      {/* The late rule the salary feels: every N lates cost half a day. */}
      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {field('lates_per_half_day', 'Late policy — lates per half day', 'Every this-many lates in a month cost half a day’s pay, automatically, in salary. 0 switches the rule off. (Default: 4 lates = 1 half day.)', { type: 'number', min: 0, max: 31 })}
      </div>

      <div className="mt-4">
        <Label>Weekly off</Label>
        <div className="mt-1 flex flex-wrap gap-1.5">
          {DAY_NAMES.map((name, day) => (
            <button
              key={name}
              disabled={!canEdit}
              onClick={() => toggleDay(day)}
              className={clsx(
                'rounded-xl border px-3 py-1.5 text-sm transition disabled:opacity-60',
                draft.week_off_days.includes(day)
                  ? 'border-emerald-400 bg-emerald-50 font-medium text-emerald-700 dark:border-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-300'
                  : 'border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
              )}
            >
              {name.slice(0, 3)}
            </button>
          ))}
        </div>
        <p className="mt-1 text-xs text-slate-400">Nobody is expected in on these, and they count as paid days.</p>
      </div>

      <h2 className="mt-6 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <ScrollText className="size-4 text-emerald-500" /> Probation &amp; paid leave
      </h2>
      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {field('probation_days', 'Probation (days)', 'Applies to everyone by default. A single employee can be given longer on their own record, and that is logged.', { type: 'number', min: 0, max: 1095 })}
        {field('monthly_leave_credit', 'Paid leave earned per month', 'Credited on the 1st, starting the month after probation ends.', { type: 'number', step: 0.5, min: 0, max: 5 })}
        <div>
          <Label>Financial year starts</Label>
          <Select
            value={draft.financial_year_start_month}
            onChange={(e) => set('financial_year_start_month', Number(e.target.value))}
            disabled={!canEdit}
            className="w-full"
          >
            {['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
              .map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
          </Select>
          <p className="mt-1 text-xs text-slate-400">Leave accounts open and close on this month.</p>
        </div>
      </div>

      <h2 className="mt-6 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Wallet className="size-4 text-emerald-500" /> Standard salary structure — statutory rates
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        Both sides of every scheme, edited here when the law changes. Every payroll run reads these; a change
        applies from the next run, never to slips already made.
      </p>
      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {field('pf_employer_rate', 'PF — employer (%)', `On capped basic — max Rs ${Math.round(draft.pf_wage_cap * draft.pf_employer_rate / 100).toLocaleString('en-IN')} today.`, { type: 'number', step: 0.25, min: 0, max: 100 })}
        {field('pf_employee_rate', 'PF — employee (%)', `On capped basic — max Rs ${Math.round(draft.pf_wage_cap * draft.pf_employee_rate / 100).toLocaleString('en-IN')} today. Kept separate so a law change can move one side alone.`, { type: 'number', step: 0.25, min: 0, max: 100 })}
        {field('pf_wage_cap', 'PF wage ceiling (₹)', 'The basic above which PF stops growing, both sides.', { type: 'number', min: 0 })}
        {field('esi_employer_rate', 'ESI — employer (%)', 'On the salary gross, rounded up to the rupee.', { type: 'number', step: 0.05, min: 0, max: 100 })}
        {field('esi_employee_rate', 'ESI — employee (%)', 'On the salary gross, rounded up.', { type: 'number', step: 0.05, min: 0, max: 100 })}
        {field('edli_rate', 'EDLI (%)', 'Employer only, on capped basic.', { type: 'number', step: 0.25, min: 0, max: 100 })}
        {field('welfare_employee_rate', 'EWF — employee (%)', `Of gross, capped at Rs ${draft.welfare_employee_cap}.`, { type: 'number', step: 0.05, min: 0, max: 100 })}
        {field('welfare_employee_cap', 'EWF cap (₹)', 'The most an employee pays in.', { type: 'number', min: 0 })}
        {field('welfare_employer_multiple', 'EWF employer multiple', 'The employer pays this many times the employee share.', { type: 'number', step: 0.5, min: 0, max: 10 })}
        {field('incentive_spread_months', 'Incentive spread (months)', 'The standard run for spread incentive plans — a sale\u2019s incentive divides over this many months instead of paying in one go. TDS is never standardised: each invoice carries what its client actually deducted.', { type: 'number', min: 1, max: 60 })}
      </div>

      <label className="mt-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input
          type="checkbox"
          checked={draft.incentive_needs_full_payment}
          disabled={!canEdit}
          onChange={(e) => set('incentive_needs_full_payment', e.target.checked)}
          className="mt-0.5 size-4 accent-emerald-600"
        />
        <span>
          No incentive until the client has paid in full
          <span className="block text-xs text-slate-400">
            An unpaid sale&rsquo;s installments wait, marked &ldquo;awaiting full payment&rdquo;; the moment the
            invoice is settled they release themselves as one arrear — no button, no ruling.
          </span>
        </span>
      </label>

      <div className="mt-4">
        <Label>Standard facilities for a new employee</Label>
        <p className="mb-1 text-xs text-slate-400">
          Prefilled on every new salary structure — each is still switchable per person, because some staff
          want only the discussed in-hand salary and take none of them.
        </p>
        <div className="flex flex-wrap gap-1.5">
          {([['pf_default', 'PF'], ['edli_default', 'EDLI'], ['esi_default', 'ESI'], ['welfare_default', 'EWF']] as const).map(([key, label]) => (
            <button
              key={key}
              disabled={!canEdit}
              onClick={() => set(key, !draft[key] as never)}
              className={clsx(
                'rounded-xl border px-3 py-1.5 text-sm transition disabled:opacity-60',
                draft[key]
                  ? 'border-emerald-400 bg-emerald-50 font-medium text-emerald-700 dark:border-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-300'
                  : 'border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
              )}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      <WorkedExample policy={draft} />

      <label className="mt-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input
          type="checkbox"
          checked={draft.encash_unused_leave}
          disabled={!canEdit}
          onChange={(e) => set('encash_unused_leave', e.target.checked)}
          className="mt-0.5 size-4 accent-emerald-600"
        />
        <span>
          Pay out unused leave at year end
          <span className="block text-xs text-slate-400">
            Whatever is left on the last day of the financial year is paid at one day of basic salary, and the
            new year opens at nothing.
          </span>
        </span>
      </label>

      {canEdit && (
        <Button className="mt-4" disabled={save.isPending} onClick={() => { setError(null); save.mutate() }}>
          <Save className="size-4" /> {save.isPending ? 'Saving…' : 'Save HR Policy'}
        </Button>
      )}
    </Card>
  )
}

/**
 * The year's holidays. Uploaded a financial year at a time, because that is
 * how a holiday list is published — and because the punch report needs to
 * know the office was shut before it calls anybody absent.
 */
function HolidayCalendar({ canManage, financialYear }: { canManage: boolean; financialYear: number }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [year, setYear] = useState(financialYear)
  const [paste, setPaste] = useState('')
  const [replace, setReplace] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'holidays', year],
    queryFn: () => crm.hr.holidays(year),
  })

  // "2026-08-15, Independence Day" — one a line, comma or tab between.
  const parsed = paste.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
    const [date, ...rest] = line.split(/[,\t]/)
    return { holiday_date: (date ?? '').trim(), name: rest.join(',').trim() }
  }).filter((h) => h.holiday_date && h.name)

  const upload = useMutation({
    mutationFn: () => crm.hr.saveHolidays(year, parsed, replace),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'holidays'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'punch'] })
      setPaste('')
      toast(res.message, 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: (uuid: string) => crm.hr.deleteHoliday(uuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'holidays'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'punch'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const years = Array.from({ length: 5 }, (_, i) => financialYear - 2 + i)

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <CalendarDays className="size-4 text-emerald-500" /> Holiday calendar
        </h2>
        <Select value={year} onChange={(e) => setYear(Number(e.target.value))}>
          {years.map((y) => <option key={y} value={y}>FY {y}–{String(y + 1).slice(2)}</option>)}
        </Select>
      </div>
      <p className="mt-1 text-xs text-slate-400">
        The days the office is shut. A declared holiday shows as Holiday on the punch report even though
        nobody punched, and counts as a paid day.
      </p>

      <ErrorNote message={error} />

      {isLoading ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : !data || data.holidays.length === 0 ? (
        <div className="mt-3">
          <EmptyState title={`No holidays declared for ${year}–${String(year + 1).slice(2)}`} hint="Paste the year's list below." />
        </div>
      ) : (
        <ul className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
          {data.holidays.map((h) => (
            <li key={h.uuid} className="flex items-center justify-between gap-2 py-2 text-sm">
              <div className="min-w-0">
                <span className={clsx('font-medium', h.past ? 'text-slate-400' : 'text-slate-800 dark:text-slate-100')}>
                  {h.holiday_date}
                </span>
                <span className="ml-2 text-slate-400">{h.day}</span>
                <div className="truncate text-slate-600 dark:text-slate-300">
                  {h.name}
                  {h.is_optional && <span className="ml-1 text-xs text-amber-600 dark:text-amber-400">(optional)</span>}
                </div>
              </div>
              {canManage && (
                <button
                  onClick={() => { if (confirm(`Remove ${h.name}?`)) remove.mutate(h.uuid) }}
                  className="shrink-0 rounded p-1.5 text-slate-400 hover:text-red-500"
                  aria-label="Remove"
                >
                  <Trash2 className="size-4" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {canManage && (
        <div className="mt-4 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
          <Label>Upload the year&rsquo;s list</Label>
          <textarea
            rows={5}
            value={paste}
            onChange={(e) => setPaste(e.target.value)}
            placeholder={'2026-08-15, Independence Day\n2026-10-02, Gandhi Jayanti\n2026-11-08, Diwali'}
            className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 font-mono text-xs outline-none focus:border-emerald-400 dark:border-slate-700 dark:bg-slate-900"
          />
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
              <input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} className="size-4 accent-emerald-600" />
              Replace the year&rsquo;s list rather than adding to it
            </label>
            <Button
              size="sm"
              disabled={parsed.length === 0 || upload.isPending}
              onClick={() => { setError(null); upload.mutate() }}
            >
              <Plus className="size-4" />
              {upload.isPending ? 'Saving…' : `Add ${parsed.length || ''} holiday${parsed.length === 1 ? '' : 's'}`}
            </Button>
          </div>
          <p className="mt-1 text-xs text-slate-400">
            One a line: date, then the name. Dates outside FY {year}–{String(year + 1).slice(2)} are skipped and
            named back to you, because a silent typo in a holiday list is a wrong salary.
          </p>
        </div>
      )}
    </Card>
  )
}

/** Everyone's paid-leave account, and the two jobs that move it. */
function LeaveAccounts({ financialYear }: { financialYear: number }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [year, setYear] = useState(financialYear)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leave-accounts', year],
    queryFn: () => crm.hr.leaveAccounts(year),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'leave-accounts'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'leaves'] })
  }

  const accrual = useMutation({
    mutationFn: () => crm.hr.runAccrual(12),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const yearEnd = useMutation({
    mutationFn: () => crm.hr.runYearEnd(year),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const years = Array.from({ length: 5 }, (_, i) => financialYear - 2 + i)

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <Wallet className="size-4 text-emerald-500" /> Paid-leave accounts
        </h2>
        <Select value={year} onChange={(e) => setYear(Number(e.target.value))}>
          {years.map((y) => <option key={y} value={y}>FY {y}–{String(y + 1).slice(2)}</option>)}
        </Select>
      </div>
      <p className="mt-1 text-xs text-slate-400">
        One day earned per month once probation is behind them, credited on the 1st. Leave taken comes out of
        the balance; what the balance cannot cover is still leave, but unpaid.
      </p>

      {isLoading ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : !data || data.members.length === 0 ? (
        <div className="mt-3"><EmptyState title="No active employees" hint="Accounts appear as people join." /></div>
      ) : (
        <div className="-mx-4 mt-3 overflow-x-auto px-4">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                <th className="py-2 pr-3 font-medium">Employee</th>
                <th className="py-2 pr-3 font-medium">Probation</th>
                <th className="py-2 pr-3 text-right font-medium">Earned</th>
                <th className="py-2 pr-3 text-right font-medium">Taken</th>
                <th className="py-2 pr-3 text-right font-medium">Paid out</th>
                <th className="py-2 text-right font-medium">Balance</th>
              </tr>
            </thead>
            <tbody>
              {data.members.map((m) => (
                <tr key={m.member_uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                  <td className="py-2.5 pr-3">
                    <div className="font-medium text-slate-800 dark:text-slate-100">{m.name}</div>
                    {m.employee_code && <div className="text-xs text-slate-400">{m.employee_code}</div>}
                  </td>
                  <td className="py-2.5 pr-3 text-xs">
                    {m.on_probation ? (
                      <span className="text-amber-600 dark:text-amber-400">
                        until {m.probation_ends_on ?? '—'}
                        {m.accrual_starts_on && <div className="text-slate-400">earns from {m.accrual_starts_on}</div>}
                      </span>
                    ) : <span className="text-slate-400">done</span>}
                  </td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{m.earned || '—'}</td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{m.taken || '—'}</td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{m.encashed || '—'}</td>
                  <td className="py-2.5 text-right font-semibold text-slate-800 dark:text-slate-100">{m.balance}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && (
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <Button variant="secondary" size="sm" disabled={accrual.isPending} onClick={() => accrual.mutate()}>
            {accrual.isPending ? 'Crediting…' : 'Catch up the monthly credits'}
          </Button>
          {data.can_run_year_end && (
            <Button
              variant="secondary"
              size="sm"
              disabled={yearEnd.isPending}
              onClick={() => {
                if (confirm(`Close FY ${year}–${String(year + 1).slice(2)} and pay out every unused day at one day of basic salary?`)) {
                  yearEnd.mutate()
                }
              }}
            >
              {yearEnd.isPending ? 'Closing…' : `Close FY ${year}–${String(year + 1).slice(2)} and pay out`}
            </Button>
          )}
          <span className="text-xs text-slate-400">
            Both run on their own — credits on the 1st, the buy-back on the first day of the new year. These
            buttons are for catching up.
          </span>
        </div>
      )}
    </Card>
  )
}

/**
 * The Admin's own sample, computed live off whatever rates stand above —
 * gross 16,000 = basic 12,000 + HRA 3,000 + others 1,000 — so a rate edit
 * shows its consequences before it is saved.
 */
function WorkedExample({ policy }: { policy: CrmHrPolicy }) {
  const basic = 12000
  const hra = 3000
  const others = 1000
  const gross = basic + hra + others
  const capped = Math.min(basic, policy.pf_wage_cap)

  const pfEmployer = Math.round(capped * policy.pf_employer_rate / 100 * 100) / 100
  const pfEmployee = Math.round(capped * policy.pf_employee_rate / 100 * 100) / 100
  const esiEmployer = Math.ceil(gross * policy.esi_employer_rate / 100)
  const esiEmployee = Math.ceil(gross * policy.esi_employee_rate / 100)
  const edli = Math.round(capped * policy.edli_rate / 100 * 100) / 100
  const ewfEmployee = Math.min(Math.round(gross * policy.welfare_employee_rate / 100 * 100) / 100, policy.welfare_employee_cap)
  const ewfEmployer = ewfEmployee * policy.welfare_employer_multiple

  const ctc = gross + pfEmployer + esiEmployer + edli + ewfEmployer
  const deduction = pfEmployer + pfEmployee + esiEmployer + esiEmployee + edli + ewfEmployer + ewfEmployee
  const net = ctc - deduction

  const fmt = (v: number) => '₹' + Math.round(v).toLocaleString('en-IN')
  const row = (label: string, value: number) => (
    <div className="flex items-baseline justify-between py-0.5">
      <span className="text-slate-500">{label}</span>
      <span className="tabular-nums">{fmt(value)}</span>
    </div>
  )

  return (
    <div className="mt-4 rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/40">
      <div className="mb-2 font-semibold uppercase tracking-wide text-slate-500">
        Worked example, live at these rates — gross ₹16,000 (basic 12,000 + HRA 3,000 + others 1,000), all facilities
      </div>
      <div className="grid gap-x-6 sm:grid-cols-2">
        <div>
          <div className="mb-1 font-medium text-slate-600 dark:text-slate-300">Employer side (into CTC)</div>
          {row(`PF ${policy.pf_employer_rate}% of capped basic`, pfEmployer)}
          {row(`ESI ${policy.esi_employer_rate}% of gross`, esiEmployer)}
          {row(`EDLI ${policy.edli_rate}% of basic`, edli)}
          {row('EWF (employee share x' + policy.welfare_employer_multiple + ')', ewfEmployer)}
          <div className="mt-1 flex items-baseline justify-between border-t border-slate-200 pt-1 font-semibold dark:border-slate-700">
            <span>CTC</span><span className="tabular-nums">{fmt(ctc)}</span>
          </div>
        </div>
        <div>
          <div className="mb-1 font-medium text-slate-600 dark:text-slate-300">Total deduction (both sides)</div>
          {row(`PF employer ${policy.pf_employer_rate}% + employee ${policy.pf_employee_rate}%`, pfEmployer + pfEmployee)}
          {row('ESI employer + employee', esiEmployer + esiEmployee)}
          {row('EDLI (employer)', edli)}
          {row('EWF employer + employee', ewfEmployer + ewfEmployee)}
          <div className="mt-1 flex items-baseline justify-between border-t border-slate-200 pt-1 font-semibold dark:border-slate-700">
            <span>Total deduction</span><span className="tabular-nums">{fmt(deduction)}</span>
          </div>
        </div>
      </div>
      <div className="mt-2 flex items-baseline justify-between rounded-lg bg-white px-3 py-1.5 text-sm font-semibold dark:bg-slate-900">
        <span>Net in hand</span><span className="tabular-nums">{fmt(net)}</span>
      </div>
      <p className="mt-1.5 text-slate-400">Incentives, loans, advances and adjustments are calculated extra, per employee.</p>
    </div>
  )
}
