import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Clock, Plus, Save, ScrollText, Trash2, Wallet, CalendarPlus } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmHrPolicy, type CrmLeaveAccount } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Select, Spinner, Modal } from '../../components/ui'

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

      {/*
        * Where the office is.
        *
        * A punch from a phone proves a phone was used, not that anybody was
        * at work. Registering the office lets the register say how far away
        * each punch was made — and, if the company wants, refuse one that
        * will not say. Left empty, none of this is asked or shown, which is
        * the right default for a company whose people work from anywhere.
        */}
      <div className="mt-4">
        <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Where punches are made</h3>
        <p className="mt-0.5 text-xs text-slate-400">
          Optional. With the office registered, every punch records how far from it that person was —
          shown in the report, never acted on by itself. Leave the coordinates empty to ask nothing.
        </p>
        <div className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {field('office_lat', 'Office latitude', 'From any maps app — right-click the office, copy the first number.', { type: 'number', step: 'any' })}
          {field('office_lng', 'Office longitude', 'The second number from the same pair.', { type: 'number', step: 'any' })}
          {field('office_radius_m', 'Office radius (metres)', 'How far from that point still counts as at the office.', { type: 'number', min: 20, max: 20000 })}
        </div>
        <label className="mt-2 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input
            type="checkbox"
            checked={!!draft.punch_needs_location}
            disabled={!canEdit}
            onChange={(e) => set('punch_needs_location', e.target.checked as never)}
            className="mt-0.5 size-4 accent-emerald-600"
          />
          <span>
            Require a location to punch in
            <span className="block text-xs text-slate-400">
              A punch that will not say where it came from is refused. Only worth switching on once the
              coordinates above are right — and it does need everyone to allow location in their browser.
            </span>
          </span>
        </label>
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

      <label className="mt-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input
          type="checkbox"
          checked={draft.cover_absence_from_leave ?? true}
          disabled={!canEdit}
          onChange={(e) => set('cover_absence_from_leave', e.target.checked)}
          className="mt-0.5 size-4 accent-emerald-600"
        />
        <span>
          Pay absent days from the leave balance before cutting salary
          <span className="block text-xs text-slate-400">
            When salaries are made, each absent or unpaid-leave day is first paid from what the account held by
            the end of that month. Only what the balance cannot cover is deducted.
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

const KIND_LABELS: Record<string, string> = {
  credit: 'Earned',
  adjust: 'Adjustment',
  debit: 'Leave taken',
  absence: 'Absence covered',
  overdraft: 'Cut from salary',
  encash: 'Paid out',
}

/** A ledger movement as it moves the balance: in (+) or out (-). */
const signedDays = (kind: string, days: number) => (kind === 'credit' || kind === 'adjust' || kind === 'overdraft' ? days : -days)
const signed = (value: number) => (value > 0 ? `+${value}` : String(value))

/**
 * Everyone's paid-leave account, and the Company Admin's hand on it.
 *
 * Earned months, the Admin's adjustments (an opening balance is one), leave
 * taken, absences the balance paid for at salary time, and the year-end
 * pay-out. Only the Admin adjusts or credits; everybody else reads.
 */
function LeaveAccounts({ financialYear }: { financialYear: number }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [year, setYear] = useState(financialYear)
  const [creditFor, setCreditFor] = useState(new Date().toISOString().slice(0, 7))
  const [adjusting, setAdjusting] = useState<CrmLeaveAccount | null>(null)
  const [viewing, setViewing] = useState<CrmLeaveAccount | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leave-accounts', year],
    queryFn: () => crm.hr.leaveAccounts(year),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'leave-accounts'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'leave-ledger'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'leaves'] })
  }

  const accrual = useMutation({
    mutationFn: () => crm.hr.runAccrual({ month: creditFor }),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const yearEnd = useMutation({
    mutationFn: () => crm.hr.runYearEnd(year),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const years = Array.from({ length: 5 }, (_, i) => financialYear - 2 + i)
  const canEdit = !!data?.can_edit
  const cell = (value: number, withSign = false) => (value ? (withSign ? signed(value) : value) : '—')

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
        One day earned per month once probation is behind them. Approved leave spends the balance, and when
        salaries are made each absent day is paid from what is left before any salary is cut. Leave deducted
        past the balance is cut from that month&rsquo;s salary as days without pay.
        {canEdit ? ' Tap a name for the movements.' : ' Adjusting an account is the Company Admin’s.'}
      </p>

      {isLoading ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : !data || data.members.length === 0 ? (
        <div className="mt-3"><EmptyState title="No active employees" hint="Accounts appear as people join." /></div>
      ) : (
        <div className="-mx-4 mt-3 overflow-x-auto px-4">
          <table className="w-full min-w-[900px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                <th className="py-2 pr-3 font-medium">Employee</th>
                <th className="py-2 pr-3 font-medium">Probation</th>
                <th className="py-2 pr-3 text-right font-medium" title="Opening balances and settlements, entered by the Company Admin">Adjusted</th>
                <th className="py-2 pr-3 text-right font-medium">Earned</th>
                <th className="py-2 pr-3 text-right font-medium" title="Approved leave paid from the balance">Leave taken</th>
                <th className="py-2 pr-3 text-right font-medium" title="Absent days paid from the balance when salaries were made">Absences covered</th>
                <th className="py-2 pr-3 text-right font-medium" title="Leave taken past the balance, cut from salary as days without pay">Cut from salary</th>
                <th className="py-2 pr-3 text-right font-medium" title="Unused days bought back at year end">Paid out</th>
                <th className="py-2 pr-3 text-right font-medium">Balance</th>
                {canEdit && <th className="py-2 font-medium" />}
              </tr>
            </thead>
            <tbody>
              {data.members.map((m) => (
                <tr key={m.member_uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                  <td className="py-2.5 pr-3">
                    <button type="button" onClick={() => setViewing(m)} className="text-left">
                      <div className="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100">{m.name}</div>
                      {m.employee_code && <div className="text-xs text-slate-400">{m.employee_code}</div>}
                    </button>
                  </td>
                  <td className="py-2.5 pr-3 text-xs">
                    {m.on_probation ? (
                      <span className="text-amber-600 dark:text-amber-400">
                        until {m.probation_ends_on ?? '—'}
                        {m.accrual_starts_on && <div className="text-slate-400">earns from {m.accrual_starts_on}</div>}
                      </span>
                    ) : <span className="text-slate-400">done</span>}
                  </td>
                  <td className={clsx('py-2.5 pr-3 text-right', m.adjusted < 0 ? 'text-red-500' : m.adjusted > 0 ? 'text-emerald-600' : 'text-slate-500')}>
                    {cell(m.adjusted, true)}
                  </td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{cell(m.earned)}</td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{cell(m.taken)}</td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{cell(m.absence_covered)}</td>
                  <td className={clsx('py-2.5 pr-3 text-right', m.salary_cut ? 'text-red-500' : 'text-slate-500')}>{cell(m.salary_cut ?? 0)}</td>
                  <td className="py-2.5 pr-3 text-right text-slate-500">{cell(m.encashed)}</td>
                  <td className="py-2.5 pr-3 text-right font-semibold text-slate-800 dark:text-slate-100">{m.balance}</td>
                  {canEdit && (
                    <td className="py-2.5 text-right">
                      <Button size="sm" variant="secondary" onClick={() => setAdjusting(m)}>Adjust</Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && canEdit && (
        <div className="mt-4 flex flex-wrap items-end gap-2">
          <div>
            <Label>Credit month</Label>
            <Input type="month" value={creditFor} onChange={(e) => setCreditFor(e.target.value)} className="w-40" />
          </div>
          <Button variant="secondary" disabled={!creditFor || accrual.isPending} onClick={() => accrual.mutate()}>
            <CalendarPlus className="size-4" /> {accrual.isPending ? 'Crediting…' : 'Credit this month'}
          </Button>
          {data.can_run_year_end && (
            <Button
              variant="secondary"
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
          <span className="basis-full text-xs text-slate-400">
            Starting the account: Adjust each person&rsquo;s opening balance dated the day before it begins (e.g.
            31 Jul 2026), then credit the first month. Credits skip anyone still on probation and never land twice.
          </span>
        </div>
      )}

      {adjusting && (
        <AdjustLeaveModal
          account={adjusting}
          onClose={() => setAdjusting(null)}
          onDone={(message) => { setAdjusting(null); refresh(); toast(message, 'success') }}
        />
      )}
      {viewing && (
        <LeaveLedgerModal
          account={viewing}
          year={year}
          canEdit={canEdit}
          onClose={() => setViewing(null)}
          onChanged={refresh}
        />
      )}
    </Card>
  )
}

/** Add or take away days by hand, with the reason. */
function AdjustLeaveModal({ account, onClose, onDone }: {
  account: CrmLeaveAccount
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [direction, setDirection] = useState<'add' | 'deduct'>('add')
  const [days, setDays] = useState('')
  const [effectiveOn, setEffectiveOn] = useState(new Date().toISOString().slice(0, 10))
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const amount = (direction === 'add' ? 1 : -1) * (Number(days) || 0)

  const save = useMutation({
    mutationFn: () => crm.hr.adjustLeave(account.member_uuid, { days: amount, effective_on: effectiveOn, note: note.trim() }),
    onSuccess: (res) => onDone(res.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Adjust ${account.name ?? 'leave account'}`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <p className="text-sm text-slate-500">
          Balance now <span className="font-semibold text-slate-800 dark:text-slate-100">{account.balance}</span> day(s).
          An opening balance is best dated the day before the account starts.
        </p>
        <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
          {([['add', 'Add days'], ['deduct', 'Deduct days']] as const).map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => setDirection(key)}
              className={clsx(
                'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
                direction === key
                  ? key === 'add' ? 'bg-emerald-600 text-white' : 'bg-red-500 text-white'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400',
              )}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Days</Label>
            <Input type="number" min="0.5" step="0.5" value={days} onChange={(e) => setDays(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Dated</Label>
            <Input type="date" value={effectiveOn} onChange={(e) => setEffectiveOn(e.target.value)} className="w-full" />
          </div>
        </div>
        <div>
          <Label>Reason</Label>
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Opening balance up to 31 Jul 2026" maxLength={255} className="w-full" />
        </div>
        {amount !== 0 && (
          <p className="text-xs text-slate-400">
            {signed(amount)} day(s) → about {Math.round((account.balance + amount) * 100) / 100} after, if dated in this year.
            {account.balance + amount < 0 && ' Days below zero are cut from that month’s salary when it is made or recalculated.'}
          </p>
        )}
        <Button className="w-full" disabled={save.isPending || !amount || !note.trim() || !effectiveOn} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save adjustment'}
        </Button>
      </div>
    </Modal>
  )
}

/** One person's account, movement by movement - and an adjustment taken back. */
function LeaveLedgerModal({ account, year, canEdit, onClose, onChanged }: {
  account: CrmLeaveAccount
  year: number
  canEdit: boolean
  onClose: () => void
  onChanged: () => void
}) {
  const { toast, toastError } = useToast()
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leave-ledger', account.member_uuid, year],
    queryFn: () => crm.hr.ledger(account.member_uuid, year),
  })

  const remove = useMutation({
    mutationFn: (uuid: string) => crm.hr.deleteLeaveEntry(uuid),
    onSuccess: (res) => { onChanged(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={`${account.name ?? 'Leave account'} — FY ${year}–${String(year + 1).slice(2)}`} onClose={onClose} wide>
      {isLoading || !data ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : data.entries.length === 0 ? (
        <EmptyState title="No movements this year" hint="Credits, adjustments, leave and covered absences show here." />
      ) : (
        <div className="-mx-4 overflow-x-auto px-4">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                <th className="py-2 pr-3 font-medium">Date</th>
                <th className="py-2 pr-3 font-medium">Movement</th>
                <th className="py-2 pr-3 text-right font-medium">Days</th>
                <th className="py-2 pr-3 font-medium">Note</th>
                {canEdit && <th className="py-2 font-medium" />}
              </tr>
            </thead>
            <tbody>
              {data.entries.map((e) => {
                const value = signedDays(e.kind, Number(e.days))
                return (
                  <tr key={e.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="whitespace-nowrap py-2 pr-3">{e.effective_on}</td>
                    <td className="py-2 pr-3">{KIND_LABELS[e.kind] ?? e.kind}</td>
                    <td className={clsx('whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums', value < 0 ? 'text-red-500' : 'text-emerald-600')}>
                      {signed(value)}
                    </td>
                    <td className="py-2 pr-3 text-slate-500">{e.note ?? '—'}</td>
                    {canEdit && (
                      <td className="py-2 text-right">
                        {e.kind === 'adjust' && (
                          <button
                            type="button"
                            disabled={remove.isPending}
                            onClick={() => { if (confirm('Remove this adjustment?')) remove.mutate(e.uuid) }}
                            aria-label="Remove adjustment"
                            className="rounded p-1.5 text-slate-400 hover:text-red-500 disabled:opacity-50"
                          >
                            <Trash2 className="size-4" />
                          </button>
                        )}
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
            <tfoot>
              <tr className="border-t border-slate-200 font-semibold dark:border-slate-700">
                <td className="py-2 pr-3" colSpan={2}>Balance</td>
                <td className="py-2 pr-3 text-right tabular-nums">{data.balance}</td>
                <td colSpan={canEdit ? 2 : 1} />
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </Modal>
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
