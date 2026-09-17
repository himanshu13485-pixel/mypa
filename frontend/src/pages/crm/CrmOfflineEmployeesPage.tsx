import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, CheckCircle2, Contact, Download, Eye, FileSpreadsheet, MessageSquarePlus, Pencil, Plus, Search, Trash2, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmNote, type CrmOfflineEmployee, type CrmOfflineSalary } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { MultiSelect } from '../../components/MultiSelect'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'
import { saveBlob } from '../../lib/download'
import { listParam } from '../../lib/multiFilter'
import NotesModal from './NotesModal'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })
const thisMonth = () => new Date().toISOString().slice(0, 7)
const monthLabel = (year: number, month: number) => `${MONTHS[month - 1]?.slice(0, 3)} ${year}`
const split = (ym: string) => ym.split('-').map(Number) as [number, number]
const slug = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
const FILT = 'min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:flex-none sm:basis-auto sm:min-w-[11rem]'

/**
 * Offline Employees: people paid outside the payroll.
 *
 * Each has a salary structure and a slip a month, like anybody on the rolls -
 * prorated by days paid, with additions and other deductions, a payslip PDF
 * and an Excel register. None of it reaches Salary: the nets show up in the
 * P&L as Offline Salary. The Company Admin's alone.
 */
export default function CrmOfflineEmployeesPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [search, setSearch] = useState('')
  const [editingPerson, setEditingPerson] = useState<CrmOfflineEmployee | 'new' | null>(null)

  const [monthFrom, setMonthFrom] = useState(thisMonth())
  const [monthTo, setMonthTo] = useState(thisMonth())
  const [nameFilter, setNameFilter] = useState('')
  const [employees, setEmployees] = useState<string[] | null>(null)
  const [statuses, setStatuses] = useState<string[] | null>(null)
  const [createFor, setCreateFor] = useState(thisMonth())
  const [record, setRecord] = useState<CrmOfflineSalary | 'new' | null>(null)
  const [viewing, setViewing] = useState<CrmOfflineSalary | null>(null)
  const [ticked, setTicked] = useState<string[]>([])
  /*
   * What is being written about: one person's month, the month itself, or a
   * selection of slips at once.
   *
   * Held here rather than in the row so the panel survives the refetch that
   * follows saving - the table is rebuilt from the new register, and a panel
   * owned by a row would shut the moment you saved.
   */
  const [noting, setNoting] = useState<
    { kind: 'person'; slip: CrmOfflineSalary } | { kind: 'month' } | { kind: 'bulk'; uuids: string[] } | null
  >(null)
  const [exporting, setExporting] = useState(false)

  const people = useQuery({
    queryKey: ['crm', 'offline-employees', search],
    queryFn: () => crm.offlineEmployees.list({ search: search.trim() || undefined }),
  })
  const allPeople = useQuery({
    queryKey: ['crm', 'offline-employees', ''],
    queryFn: () => crm.offlineEmployees.list(),
  })
  const rangeOk = !!monthFrom && !!monthTo && monthTo >= monthFrom
  const filters = {
    month_from: monthFrom,
    month_to: monthTo,
    search: nameFilter.trim() || undefined,
    employee: listParam(employees),
    status: listParam(statuses),
  }
  const records = useQuery({
    queryKey: ['crm', 'offline-salaries', monthFrom, monthTo, nameFilter, employees, statuses],
    queryFn: () => crm.offlineEmployees.salaries(filters),
    enabled: rangeOk,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'offline-employees'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'offline-salaries'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'pl'] })
  }
  const done = (message: string) => { refresh(); toast(message, 'success') }

  const removePerson = useMutation({
    mutationFn: (uuid: string) => crm.offlineEmployees.remove(uuid),
    onSuccess: (res) => done(res.message),
    onError: (err) => toastError(errorMessage(err)),
  })
  const generate = useMutation({
    mutationFn: () => crm.offlineEmployees.generate(...split(createFor)),
    onSuccess: (res) => {
      done(res.message)
      // Show the month just created.
      setMonthFrom((f) => (createFor < f ? createFor : f))
      setMonthTo((t) => (createFor > t ? createFor : t))
    },
    onError: (err) => toastError(errorMessage(err)),
  })
  const removeRecord = useMutation({
    mutationFn: (uuid: string) => crm.offlineEmployees.removeSalary(uuid),
    onSuccess: (res) => done(res.message),
    onError: (err) => toastError(errorMessage(err)),
  })
  const markPaid = useMutation({
    mutationFn: (uuids: string[]) => crm.offlineEmployees.markPaid(uuids),
    onSuccess: (res) => { setTicked([]); setViewing(null); done(res.message) },
    onError: (err) => toastError(errorMessage(err)),
  })

  const downloadPdf = async (r: CrmOfflineSalary) => {
    try {
      const blob = await crm.offlineEmployees.pdf(r.uuid)
      saveBlob(blob, `payslip-${slug(r.employee?.name ?? 'offline-employee')}-${r.year}-${String(r.month).padStart(2, '0')}.pdf`)
    } catch (err) {
      toastError(errorMessage(err))
    }
  }
  const downloadExcel = async () => {
    setExporting(true)
    try {
      const blob = await crm.offlineEmployees.exportExcel(filters)
      saveBlob(blob, `offline-salaries-${monthFrom}${monthTo !== monthFrom ? `-to-${monthTo}` : ''}.xlsx`)
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setExporting(false)
    }
  }

  const rows = records.data?.data ?? []
  const shownUuids = rows.map((r) => r.uuid)
  const pendingUuids = rows.filter((r) => r.status !== 'paid').map((r) => r.uuid)
  const tickedShown = ticked.filter((u) => shownUuids.includes(u))
  const tickedPending = ticked.filter((u) => pendingUuids.includes(u))
  const toggle = (uuid: string) => setTicked((t) => (t.includes(uuid) ? t.filter((x) => x !== uuid) : [...t, uuid]))
  const totals = records.data?.totals
  /* One month on screen: a remark about "the month" has a month to mean. */
  const single = monthFrom === monthTo
  const [noteYear, noteMonth] = monthFrom.split('-').map(Number)
  const monthNotes: CrmNote[] = records.data?.notes ?? []

  /** Whatever the panel is open on, read out of the register itself. */
  const notesOpen: CrmNote[] = noting?.kind === 'person'
    ? rows.find((r) => r.uuid === noting.slip.uuid)?.notes ?? []
    : noting?.kind === 'month' ? monthNotes : []

  const writeNote = (body: string) => crm.offlineEmployees.addNote({
    year: noting?.kind === 'person' ? noting.slip.year : noteYear,
    month: noting?.kind === 'person' ? noting.slip.month : noteMonth,
    employee_uuid: noting?.kind === 'person' ? noting.slip.employee?.uuid ?? null : null,
    uuids: noting?.kind === 'bulk' ? noting.uuids : undefined,
    body,
  }).then((res: { message: string }) => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'offline-salaries'] })
    if (noting?.kind === 'bulk') toast(res.message, 'success')
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            <Contact className="size-5 text-emerald-500" /> Offline Employees
          </h1>
          <p className="text-sm text-slate-500">
            People paid outside the payroll, with their own salary structure and payslips. Their nets count in the
            P&amp;L as Offline Salary, never in Salary.
          </p>
        </div>
        <Button onClick={() => setEditingPerson('new')}>
          <Plus className="size-4" /> Add employee
        </Button>
      </div>

      {/* ---- People ---- */}
      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto text-sm font-semibold text-slate-800 dark:text-slate-100">
            Employees
            {people.data && (
              <span className="ml-2 text-xs font-normal text-slate-400">
                {people.data.totals.active} active · {inr(people.data.totals.monthly)} gross a month
              </span>
            )}
          </h2>
          <div className="relative w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or Employee ID…" className="w-full pl-9" />
          </div>
        </div>

        {people.isLoading ? (
          <div className="flex justify-center py-10"><Spinner /></div>
        ) : !people.data?.data.length ? (
          <EmptyState title="No offline employees" hint="Add the people you pay outside the payroll." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Employee ID</th>
                  <th className="py-2 pr-3 font-medium">Name</th>
                  <th className="py-2 pr-3 text-right font-medium">Gross / month</th>
                  <th className="py-2 pr-3 text-right font-medium">Deductions</th>
                  <th className="py-2 pr-3 text-right font-medium">Net / month</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Slips</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {people.data.data.map((p) => (
                  <tr key={p.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="whitespace-nowrap py-2.5 pr-3 font-medium text-emerald-600">{p.employee_code}</td>
                    <td className="py-2.5 pr-3">
                      <div className="font-medium text-slate-800 dark:text-slate-100">{p.name}</div>
                      <div className="max-w-[260px] truncate text-xs text-slate-400" title={p.note ?? ''}>
                        {[p.designation, p.structure.earnings.length ? `${p.structure.earnings.length} earning lines` : null].filter(Boolean).join(' · ') || p.note || '—'}
                      </div>
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(p.monthly_amount)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums text-red-500">
                      {p.monthly_deductions ? '−' + inr(p.monthly_deductions).slice(1) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-semibold tabular-nums">{inr(p.monthly_net)}</td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        p.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                      )}>
                        {p.status === 'active' ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-xs text-slate-500">
                      {p.records}{p.last_month && <> · last {monthLabel(...split(p.last_month))}</>}
                    </td>
                    <td className="whitespace-nowrap py-2.5 text-right">
                      <button onClick={() => setEditingPerson(p)} aria-label={`Edit ${p.name}`} className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Pencil className="size-4" />
                      </button>
                      <button
                        onClick={() => {
                          const more = p.records ? ` and their ${p.records} slip${p.records === 1 ? '' : 's'}` : ''
                          if (confirm(`Delete ${p.name}${more}? The P&L loses those months too.`)) removePerson.mutate(p.uuid)
                        }}
                        aria-label={`Delete ${p.name}`}
                        className="rounded p-1.5 text-slate-400 hover:text-red-500"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* ---- Monthly slips ---- */}
      <Card>
        <div className="mb-3 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Salary slips</h2>
            {totals && (
              <p className="text-xs text-slate-400">
                {totals.count} slip{totals.count === 1 ? '' : 's'} · {inr(totals.amount)} net in view
              </p>
            )}
          </div>
          {/* Creating a month: everybody active, from their structure. */}
          <div className="flex flex-wrap items-end gap-2">
            <div>
              <Label>Create slips for</Label>
              <Input type="month" value={createFor} onChange={(e) => setCreateFor(e.target.value)} className="w-40" />
            </div>
            <Button variant="secondary" disabled={!createFor || generate.isPending} onClick={() => generate.mutate()}>
              <CalendarPlus className="size-4" /> {generate.isPending ? 'Creating…' : 'Create month'}
            </Button>
            <Button variant="secondary" disabled={!allPeople.data?.data.length} onClick={() => setRecord('new')}>
              <Plus className="size-4" /> Add slip
            </Button>
            <Button variant="secondary" disabled={!rows.length || exporting} onClick={downloadExcel}>
              <FileSpreadsheet className="size-4" /> {exporting ? 'Preparing…' : 'Excel'}
            </Button>
          </div>
        </div>

        {totals && totals.count > 0 && (
          <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-5">
            {[
              { label: 'Gross', value: inr(totals.gross + totals.additions) },
              { label: 'Deductions', value: inr(totals.deductions) },
              { label: 'Net payroll', value: inr(totals.amount) },
              { label: 'Paid out', value: inr(totals.paid) },
              { label: 'Pending', value: inr(totals.pending) },
            ].map((t) => (
              <div key={t.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                <div className="text-base font-semibold tabular-nums text-slate-900 dark:text-white">{t.value}</div>
                <div className="text-xs text-slate-500">{t.label}</div>
              </div>
            ))}
          </div>
        )}

        <div className="mb-3 flex flex-wrap items-end gap-2">
          <div className="basis-[calc(50%-0.25rem)] sm:basis-auto">
            <Label>From month</Label>
            <Input type="month" value={monthFrom} onChange={(e) => setMonthFrom(e.target.value)} className="w-full sm:w-40" />
          </div>
          <div className="basis-[calc(50%-0.25rem)] sm:basis-auto">
            <Label>To month</Label>
            <Input type="month" value={monthTo} onChange={(e) => setMonthTo(e.target.value)} className="w-full sm:w-40" />
          </div>
          <div className="w-full sm:w-56">
            <Label>Name or Employee ID</Label>
            <Input value={nameFilter} onChange={(e) => setNameFilter(e.target.value)} placeholder="Filter by name…" className="w-full" />
          </div>
          <MultiSelect
            label="Employee"
            allLabel="Everyone"
            options={(allPeople.data?.data ?? []).map((p) => ({ value: p.uuid, label: `${p.employee_code} · ${p.name}` }))}
            value={employees}
            onChange={setEmployees}
            className={FILT}
          />
          <MultiSelect
            label="Status"
            options={[{ value: 'pending', label: 'Pending' }, { value: 'paid', label: 'Paid' }]}
            value={statuses}
            onChange={setStatuses}
            className={FILT}
          />
        </div>

        {/* The month's own remarks: a late run, a change of account, a week
            nobody was on site. Only when one month is on screen, because over
            a span there is no one month for it to be about. */}
        {single && (
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => setNoting({ kind: 'month' })}>
              <MessageSquarePlus className="size-4" />
              {monthNotes.length
                ? `${monthNotes.length} note${monthNotes.length > 1 ? 's' : ''} on this month`
                : 'Note on this month'}
            </Button>
            {monthNotes.map((n) => (
              <span key={n.id} className="rounded-lg bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                {n.body} <span className="opacity-60">— {n.author}</span>
              </span>
            ))}
          </div>
        )}

        {rows.length > 0 && (
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/50">
            <span className="text-slate-500">
              {tickedShown.length > 0
                ? <>{tickedShown.length} of {shownUuids.length} selected — {inr(rows.filter((r) => tickedShown.includes(r.uuid)).reduce((t, r) => t + r.net, 0))}</>
                : 'Tick salaries to pay them, or to write one remark across all of them.'}
            </span>
            <span className="flex flex-wrap gap-2">
              {/* "Paid from the ICICI account" is true of some of a list and
                  false of the rest, and typing it eleven times is how it ends
                  up typed eight. */}
              <Button
                variant="secondary"
                size="sm"
                disabled={tickedShown.length === 0}
                onClick={() => setNoting({ kind: 'bulk', uuids: tickedShown })}
              >
                <MessageSquarePlus className="size-4" /> Remark
              </Button>
              <Button
                variant="secondary"
                size="sm"
                disabled={tickedPending.length === 0 || markPaid.isPending}
                onClick={() => { if (confirm(`Mark ${tickedPending.length} slip(s) as paid today?`)) markPaid.mutate(tickedPending) }}
              >
                <CheckCircle2 className="size-4" /> {markPaid.isPending ? 'Marking…' : `Mark ${tickedPending.length || ''} paid`.replace('  ', ' ')}
              </Button>
            </span>
          </div>
        )}

        {!rangeOk ? (
          <p className="py-6 text-center text-sm text-slate-400">Pick a From month on or before the To month.</p>
        ) : records.isLoading ? (
          <div className="flex justify-center py-10"><Spinner /></div>
        ) : !rows.length ? (
          <EmptyState title="No slips in this range" hint="Create a month, or add one slip by hand." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[980px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="w-8 py-2 pr-2">
                    {pendingUuids.length > 0 && (
                      <input
                        type="checkbox"
                        aria-label="Select all pending slips"
                        checked={tickedPending.length > 0 && tickedPending.length === pendingUuids.length}
                        onChange={() => setTicked(tickedPending.length === pendingUuids.length ? [] : pendingUuids)}
                        className="size-3.5 accent-emerald-600"
                      />
                    )}
                  </th>
                  <th className="py-2 pr-3 font-medium">Month</th>
                  <th className="py-2 pr-3 font-medium">Employee</th>
                  <th className="py-2 pr-3 text-right font-medium">Days</th>
                  <th className="py-2 pr-3 text-right font-medium">Gross</th>
                  <th className="py-2 pr-3 text-right font-medium">Additions</th>
                  <th className="py-2 pr-3 text-right font-medium">Deductions</th>
                  <th className="py-2 pr-3 text-right font-medium">Net</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2.5 pr-2">
                      {/* Paid ones too: a remark about where the money went
                          is written after it has gone. */}
                      <input
                        type="checkbox"
                        aria-label={`Select ${r.employee?.name ?? 'slip'}`}
                        checked={ticked.includes(r.uuid)}
                        onChange={() => toggle(r.uuid)}
                        className="size-3.5 accent-emerald-600"
                      />
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3">{monthLabel(r.year, r.month)}</td>
                    <td className="py-2.5 pr-3">
                      <button type="button" onClick={() => setViewing(r)} className="text-left">
                        <div className="font-medium text-emerald-600 hover:underline">{r.employee?.name ?? '—'}</div>
                        <div className="text-xs text-slate-400">{r.employee?.employee_code}</div>
                      </button>
                      {/* Read beside the name rather than behind a click. */}
                      {r.notes?.map((n) => (
                        <div key={n.id} className="text-[11px] leading-snug text-amber-600/90 dark:text-amber-400/80">
                          {n.body} <span className="text-slate-400">— {n.author}</span>
                        </div>
                      ))}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-xs text-slate-500" title={`${r.lop_days} without pay`}>
                      {r.payable_days}/{r.month_days}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(r.gross)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums text-emerald-600" title={r.addition_note ?? ''}>
                      {r.additions ? '+' + inr(r.additions).slice(1) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums text-red-500" title={r.other_deduction_note ?? ''}>
                      {r.deductions + r.other_deductions ? '−' + inr(r.deductions + r.other_deductions).slice(1) : '—'}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-semibold tabular-nums">{inr(r.net)}</td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        r.status === 'paid'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                      )}>
                        {r.status === 'paid' ? `Paid ${r.paid_on ?? ''}` : 'Pending'}
                      </span>
                    </td>
                    <td className="whitespace-nowrap py-2.5 text-right">
                      <button
                        onClick={() => setNoting({ kind: 'person', slip: r })}
                        aria-label={`Notes on ${r.employee?.name ?? 'this salary'}`}
                        title="Remarks kept for the office"
                        className={clsx('rounded p-1.5', r.notes?.length ? 'text-amber-500 hover:text-amber-600' : 'text-slate-400 hover:text-emerald-600')}
                      >
                        <MessageSquarePlus className="size-4" />
                      </button>
                      <button onClick={() => setViewing(r)} aria-label="View slip" title="View slip" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Eye className="size-4" />
                      </button>
                      <button onClick={() => downloadPdf(r)} aria-label="Download payslip" title="Download payslip PDF" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Download className="size-4" />
                      </button>
                      <button onClick={() => setRecord(r)} aria-label="Edit slip" title="Edit slip" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Pencil className="size-4" />
                      </button>
                      {r.status !== 'paid' && (
                        <button onClick={() => markPaid.mutate([r.uuid])} aria-label="Mark paid" title="Mark paid" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                          <CheckCircle2 className="size-4" />
                        </button>
                      )}
                      <button
                        onClick={() => { if (confirm(`Delete ${r.employee?.name ?? 'this'}'s slip for ${monthLabel(r.year, r.month)}?`)) removeRecord.mutate(r.uuid) }}
                        aria-label="Delete slip"
                        className="rounded p-1.5 text-slate-400 hover:text-red-500"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
              {totals && (
                <tfoot>
                  <tr className="border-t border-slate-200 font-semibold dark:border-slate-700">
                    <td className="py-2.5 pr-3" colSpan={4}>Total</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(totals.gross)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums text-emerald-600">{inr(totals.additions)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums text-red-500">{inr(totals.deductions)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(totals.amount)}</td>
                    <td colSpan={2} />
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        )}
      </Card>

      {editingPerson && (
        <PersonModal
          person={editingPerson === 'new' ? null : editingPerson}
          onClose={() => setEditingPerson(null)}
          onDone={(message) => { setEditingPerson(null); done(message) }}
        />
      )}
      {record && (
        <RecordModal
          record={record === 'new' ? null : record}
          people={(allPeople.data?.data ?? []).filter((p) => p.status === 'active' || (record !== 'new' && p.uuid === record.employee?.uuid))}
          defaultMonth={monthTo || thisMonth()}
          onClose={() => setRecord(null)}
          onDone={(message) => { setRecord(null); setViewing(null); done(message) }}
        />
      )}
      {noting && (
        <NotesModal
          writeOnly={noting.kind === 'bulk'}
          title={noting.kind === 'person'
            ? noting.slip.employee?.name ?? 'this salary'
            : noting.kind === 'bulk'
              ? `${noting.uuids.length} ${noting.uuids.length === 1 ? 'salary' : 'salaries'}`
              : 'this month'}
          subtitle={noting.kind === 'bulk'
            ? 'The same remark goes onto each of them, on their own month.'
            : `${MONTHS[(noting.kind === 'person' ? noting.slip.month : noteMonth) - 1]} ${noting.kind === 'person' ? noting.slip.year : noteYear}`}
          notes={notesOpen}
          placeholder={noting.kind === 'month'
            ? 'Anything worth remembering about this month…'
            : 'Why this amount — which account it went from, what it covers…'}
          onAdd={writeNote}
          onRemove={(id) => crm.offlineEmployees.deleteNote(id)
            .then(() => queryClient.invalidateQueries({ queryKey: ['crm', 'offline-salaries'] }))}
          onClose={() => { setNoting(null); if (noting.kind === 'bulk') setTicked([]) }}
        />
      )}
      {viewing && (
        <SlipModal
          slip={viewing}
          onClose={() => setViewing(null)}
          onDownload={() => downloadPdf(viewing)}
          onEdit={() => { setRecord(viewing); setViewing(null) }}
          onMarkPaid={() => markPaid.mutate([viewing.uuid])}
          paying={markPaid.isPending}
        />
      )}
    </div>
  )
}

type LineRow = { label: string; amount: string }

const PRESET_EARNINGS = ['Basic', 'HRA', 'Conveyance', 'Special allowance']

/** Lines of a structure: a name and a monthly amount each, added and removed freely. */
function LinesEditor({ title, rows, onChange, addLabel, placeholder, tone }: {
  title: string
  rows: LineRow[]
  onChange: (rows: LineRow[]) => void
  addLabel: string
  placeholder: string
  tone: 'earn' | 'deduct'
}) {
  const set = (i: number, patch: Partial<LineRow>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <div className={clsx('rounded-xl border p-3', tone === 'earn' ? 'border-emerald-200 dark:border-emerald-900/60' : 'border-red-200 dark:border-red-900/60')}>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-[1fr_7rem_auto] gap-2">
            <Input value={r.label} onChange={(e) => set(i, { label: e.target.value })} placeholder={placeholder} maxLength={64} className="w-full" />
            <Input type="number" min="0" step="0.01" value={r.amount} onChange={(e) => set(i, { amount: e.target.value })} placeholder="₹" className="w-full" />
            <button type="button" onClick={() => onChange(rows.filter((_, j) => j !== i))} aria-label="Remove line" className="rounded p-1.5 text-slate-400 hover:text-red-500">
              <X className="size-4" />
            </button>
          </div>
        ))}
        {rows.length === 0 && <p className="text-xs text-slate-400">None.</p>}
      </div>
      <button type="button" onClick={() => onChange([...rows, { label: '', amount: '' }])} className="mt-2 text-xs font-medium text-emerald-600 hover:underline">
        + {addLabel}
      </button>
    </div>
  )
}

function PersonModal({ person, onClose, onDone }: {
  person: CrmOfflineEmployee | null
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [form, setForm] = useState({
    employee_code: person?.employee_code ?? '',
    name: person?.name ?? '',
    designation: person?.designation ?? '',
    joined_on: person?.joined_on ?? '',
    status: person?.status ?? 'active',
    note: person?.note ?? '',
    bank_name: person?.bank_name ?? '',
    account_holder: person?.account_holder ?? '',
    account_no: person?.account_no ?? '',
    ifsc: person?.ifsc ?? '',
  })
  const [earnings, setEarnings] = useState<LineRow[]>(() => {
    if (!person) return PRESET_EARNINGS.map((label) => ({ label, amount: '' }))
    if (person.structure.earnings.length) return person.structure.earnings.map((l) => ({ label: l.label, amount: String(l.amount) }))
    return [{ label: 'Monthly salary', amount: String(person.monthly_amount) }]
  })
  const [deductions, setDeductions] = useState<LineRow[]>(
    () => (person?.structure.deductions ?? []).map((l) => ({ label: l.label, amount: String(l.amount) })),
  )
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  const sum = (rows: LineRow[]) => rows.reduce((t, r) => t + (r.label.trim() ? Number(r.amount) || 0 : 0), 0)
  const clean = (rows: LineRow[]) => rows
    .filter((r) => r.label.trim() && Number(r.amount) > 0)
    .map((r) => ({ label: r.label.trim(), amount: Number(r.amount) }))
  const gross = sum(earnings)
  const fixed = sum(deductions)
  const orNull = (v: string) => v.trim() || null

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        employee_code: form.employee_code.trim(),
        name: form.name.trim(),
        designation: orNull(form.designation),
        joined_on: form.joined_on || null,
        monthly_amount: gross,
        structure: { earnings: clean(earnings), deductions: clean(deductions) },
        bank_name: orNull(form.bank_name),
        account_holder: orNull(form.account_holder),
        account_no: orNull(form.account_no),
        ifsc: orNull(form.ifsc),
        status: form.status as 'active' | 'inactive',
        note: orNull(form.note),
      }
      return person ? crm.offlineEmployees.update(person.uuid, payload) : crm.offlineEmployees.create(payload)
    },
    onSuccess: (res) => onDone(res.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={person ? `Edit ${person.name}` : 'Add offline employee'} onClose={onClose} wide>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Employee ID</Label>
            <Input value={form.employee_code} onChange={(e) => set('employee_code', e.target.value)} placeholder="OFF-101" maxLength={64} className="w-full" />
          </div>
          <div>
            <Label>Employee name</Label>
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={191} className="w-full" />
          </div>
          <div>
            <Label>Designation</Label>
            <Input value={form.designation} onChange={(e) => set('designation', e.target.value)} placeholder="Driver, office help…" maxLength={128} className="w-full" />
          </div>
          <div>
            <Label>Joined on</Label>
            <Input type="date" value={form.joined_on} onChange={(e) => set('joined_on', e.target.value)} className="w-full" />
          </div>
        </div>

        {/* The salary structure, as a slip will print it. */}
        <div className="grid gap-3 lg:grid-cols-2">
          <LinesEditor title="Earnings (monthly)" rows={earnings} onChange={setEarnings} addLabel="Add earning" placeholder="Basic, HRA, allowance…" tone="earn" />
          <LinesEditor title="Fixed deductions (monthly)" rows={deductions} onChange={setDeductions} addLabel="Add deduction" placeholder="Advance recovery, PF…" tone="deduct" />
        </div>
        <div className="grid grid-cols-3 gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
          <div><div className="text-xs text-slate-500">Gross / month</div><div className="font-semibold tabular-nums">{inr(gross)}</div></div>
          <div><div className="text-xs text-slate-500">Deductions</div><div className="font-semibold tabular-nums text-red-500">{inr(fixed)}</div></div>
          <div><div className="text-xs text-slate-500">Net / month</div><div className="font-semibold tabular-nums">{inr(gross - fixed)}</div></div>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Bank name</Label>
            <Input value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} maxLength={128} className="w-full" />
          </div>
          <div>
            <Label>Account holder</Label>
            <Input value={form.account_holder} onChange={(e) => set('account_holder', e.target.value)} maxLength={128} className="w-full" />
          </div>
          <div>
            <Label>Account no.</Label>
            <Input value={form.account_no} onChange={(e) => set('account_no', e.target.value)} maxLength={64} className="w-full" />
          </div>
          <div>
            <Label>IFSC</Label>
            <Input value={form.ifsc} onChange={(e) => set('ifsc', e.target.value)} maxLength={32} className="w-full" />
          </div>
          <div>
            <Label>Status</Label>
            <Select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full">
              <option value="active">Active</option>
              <option value="inactive">Inactive (no new months)</option>
            </Select>
          </div>
          <div>
            <Label>Note</Label>
            <Input value={form.note} onChange={(e) => set('note', e.target.value)} maxLength={500} className="w-full" />
          </div>
        </div>

        <Button
          className="w-full"
          disabled={save.isPending || !form.employee_code.trim() || !form.name.trim() || gross <= 0}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Saving…' : person ? 'Save changes' : 'Add employee'}
        </Button>
      </div>
    </Modal>
  )
}

/** Add a slip, or adjust one: days without pay, additions and other deductions with reasons, paid or not. */
function RecordModal({ record, people, defaultMonth, onClose, onDone }: {
  record: CrmOfflineSalary | null
  people: CrmOfflineEmployee[]
  defaultMonth: string
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [employee, setEmployee] = useState(record?.employee?.uuid ?? people[0]?.uuid ?? '')
  const [month, setMonth] = useState(record ? `${record.year}-${String(record.month).padStart(2, '0')}` : defaultMonth)
  const [lop, setLop] = useState(record?.lop_days ? String(record.lop_days) : '')
  const [additions, setAdditions] = useState(record?.additions ? String(record.additions) : '')
  const [additionNote, setAdditionNote] = useState(record?.addition_note ?? '')
  const [other, setOther] = useState(record?.other_deductions ? String(record.other_deductions) : '')
  const [otherNote, setOtherNote] = useState(record?.other_deduction_note ?? '')
  const [status, setStatus] = useState<'pending' | 'paid'>(record?.status ?? 'pending')
  const [paidOn, setPaidOn] = useState(record?.paid_on ?? '')
  const [mode, setMode] = useState(record?.payment_mode ?? '')
  const [note, setNote] = useState(record?.note ?? '')
  const [error, setError] = useState<string | null>(null)

  // A live net: the month's earnings prorated by the days paid.
  const person = people.find((p) => p.uuid === employee)
  const [y, m] = split(month || thisMonth())
  const monthDays = record?.month_days ?? (new Date(y, m, 0).getDate() || 30)
  const fullGross = record
    ? (record.payable_days > 0 ? (record.gross * record.month_days) / record.payable_days : person?.monthly_amount ?? 0)
    : person?.monthly_amount ?? 0
  const fixed = record ? record.deductions : person?.monthly_deductions ?? 0
  const payable = Math.max(0, monthDays - (Number(lop) || 0))
  const grossNow = monthDays > 0 ? Math.round(((fullGross * payable) / monthDays) * 100) / 100 : fullGross
  const net = grossNow + (Number(additions) || 0) - fixed - (Number(other) || 0)

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        lop_days: Number(lop) || 0,
        additions: Number(additions) || 0,
        addition_note: additionNote.trim() || null,
        other_deductions: Number(other) || 0,
        other_deduction_note: otherNote.trim() || null,
        status,
        paid_on: paidOn || null,
        payment_mode: mode.trim() || null,
        note: note.trim() || null,
      }
      if (record) return crm.offlineEmployees.updateSalary(record.uuid, payload)
      const [year, mm] = split(month)
      return crm.offlineEmployees.addSalary({ offline_employee_uuid: employee, year, month: mm, ...payload })
    },
    onSuccess: (res) => onDone(res.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      title={record ? `${record.employee?.name ?? 'Slip'} — ${MONTHS[record.month - 1]} ${record.year}` : 'Add salary slip'}
      onClose={onClose}
      wide
    >
      <div className="space-y-3">
        <ErrorNote message={error} />
        {!record && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Employee</Label>
              <Select value={employee} onChange={(e) => setEmployee(e.target.value)} className="w-full">
                {people.map((p) => <option key={p.uuid} value={p.uuid}>{p.employee_code} · {p.name}</option>)}
              </Select>
            </div>
            <div>
              <Label>Month</Label>
              <Input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="w-full" />
            </div>
          </div>
        )}
        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <Label>Days without pay</Label>
            <Input type="number" min="0" max={monthDays} step="0.5" value={lop} onChange={(e) => setLop(e.target.value)} placeholder="0" className="w-full" />
            <p className="mt-1 text-[11px] text-slate-400">{payable} payable of {monthDays} days</p>
          </div>
          <div>
            <Label>Status</Label>
            <Select value={status} onChange={(e) => setStatus(e.target.value as 'pending' | 'paid')} className="w-full">
              <option value="pending">Pending</option>
              <option value="paid">Paid</option>
            </Select>
          </div>
          <div>
            <Label>Paid on</Label>
            <Input type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} className="w-full" />
          </div>
        </div>
        <div className="rounded-xl border border-emerald-200 p-3 dark:border-emerald-900/60">
          <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
            <div>
              <Label>Additions (₹)</Label>
              <Input type="number" min="0" value={additions} onChange={(e) => setAdditions(e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Addition note</Label>
              <Input value={additionNote} onChange={(e) => setAdditionNote(e.target.value)} placeholder="Bonus, extra days…" maxLength={500} className="w-full" />
            </div>
          </div>
        </div>
        <div className="rounded-xl border border-red-200 p-3 dark:border-red-900/60">
          <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
            <div>
              <Label>Other deductions (₹)</Label>
              <Input type="number" min="0" value={other} onChange={(e) => setOther(e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Other deduction note</Label>
              <Input value={otherNote} onChange={(e) => setOtherNote(e.target.value)} placeholder="Advance, damage…" maxLength={500} className="w-full" />
            </div>
          </div>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Payment mode</Label>
            <Input value={mode} onChange={(e) => setMode(e.target.value)} placeholder="Cash, UPI, NEFT…" maxLength={64} className="w-full" />
          </div>
          <div>
            <Label>Note</Label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} className="w-full" />
          </div>
        </div>
        <div className="grid grid-cols-3 gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
          <div><div className="text-xs text-slate-500">Gross for {payable} days</div><div className="font-semibold tabular-nums">{inr(grossNow + (Number(additions) || 0))}</div></div>
          <div><div className="text-xs text-slate-500">Deductions</div><div className="font-semibold tabular-nums text-red-500">{inr(fixed + (Number(other) || 0))}</div></div>
          <div><div className="text-xs text-slate-500">Net salary</div><div className="font-semibold tabular-nums">{inr(net)}</div></div>
        </div>
        <Button className="w-full" disabled={save.isPending || (!record && (!employee || !month))} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : record ? 'Save slip' : 'Add slip'}
        </Button>
      </div>
    </Modal>
  )
}

/** The slip, line by line - earnings to net, the way the payslip prints it. */
function SlipModal({ slip, onClose, onDownload, onEdit, onMarkPaid, paying }: {
  slip: CrmOfflineSalary
  onClose: () => void
  onDownload: () => void
  onEdit: () => void
  onMarkPaid: () => void
  paying: boolean
}) {
  const line = (label: string, amount: number, tone?: string) => (
    <div className="flex items-baseline justify-between gap-2 py-1 text-sm">
      <span className="text-slate-500">{label}</span>
      <span className={clsx('tabular-nums', tone ?? 'text-slate-800 dark:text-slate-100')}>{inr(amount)}</span>
    </div>
  )
  const noted = (label: string, amount: number, reason: string | null, tone: string) => (
    <div>
      {line(label, amount, tone)}
      {reason && <p className="-mt-0.5 break-words pb-1 text-xs italic text-slate-400">{reason}</p>}
    </div>
  )

  return (
    <Modal title={`${slip.employee?.name ?? 'Slip'} — ${MONTHS[slip.month - 1]} ${slip.year}`} onClose={onClose} wide>
      <div className="space-y-4">
        <p className="text-xs text-slate-400">
          {slip.employee?.employee_code}{slip.employee?.designation && <> · {slip.employee.designation}</>} ·{' '}
          {slip.payable_days} payable of {slip.month_days} days
          {slip.lop_days > 0 && <> · {slip.lop_days} without pay</>}
        </p>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Earnings</h3>
            {slip.earnings.map((l, i) => <div key={`${l.key}-${i}`}>{line(l.label, l.amount)}</div>)}
            {slip.additions > 0 && noted('Additions', slip.additions, slip.addition_note, 'text-emerald-600')}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Gross payable', slip.gross + slip.additions, 'font-semibold')}
            </div>
          </div>
          <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Deductions</h3>
            {slip.deduction_lines.map((l, i) => <div key={`${l.key}-${i}`}>{line(l.label, l.amount, 'text-red-500')}</div>)}
            {slip.other_deductions > 0 && noted('Other deductions', slip.other_deductions, slip.other_deduction_note, 'text-red-500')}
            {slip.deduction_lines.length === 0 && slip.other_deductions <= 0 && <p className="py-1 text-sm text-slate-400">None.</p>}
            <div className="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
              {line('Total deductions', slip.deductions + slip.other_deductions, 'font-semibold text-red-500')}
            </div>
          </div>
        </div>

        <div className="rounded-xl bg-slate-100 px-4 py-2.5 dark:bg-slate-800/60">
          <div className="flex items-baseline justify-between">
            <span className="text-sm text-slate-500">Net salary</span>
            <span className="text-lg font-semibold tabular-nums">{inr(slip.net)}</span>
          </div>
          <div className="text-xs text-slate-400">
            {slip.status === 'paid'
              ? `Paid${slip.paid_on ? ` on ${slip.paid_on}` : ''}${slip.payment_mode ? ` · ${slip.payment_mode}` : ''}`
              : 'Pending'}
          </div>
        </div>

        <div className="flex flex-col gap-2 sm:flex-row">
          <Button className="flex-1" variant="secondary" onClick={onDownload}>
            <Download className="size-4" /> Download payslip
          </Button>
          <Button className="flex-1" variant="secondary" onClick={onEdit}>
            <Pencil className="size-4" /> Adjust this slip
          </Button>
          {slip.status !== 'paid' && (
            <Button className="flex-1" disabled={paying} onClick={onMarkPaid}>
              <CheckCircle2 className="size-4" /> {paying ? 'Marking…' : 'Mark paid'}
            </Button>
          )}
        </div>
      </div>
    </Modal>
  )
}
