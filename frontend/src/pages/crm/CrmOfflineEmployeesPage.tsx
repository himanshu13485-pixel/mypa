import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, Contact, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmOfflineEmployee, type CrmOfflineSalary } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })
const thisMonth = () => new Date().toISOString().slice(0, 7)
const monthLabel = (year: number, month: number) => `${MONTHS[month - 1]?.slice(0, 3)} ${year}`
const split = (ym: string) => ym.split('-').map(Number) as [number, number]

/**
 * Offline Employees: people paid outside the payroll.
 *
 * An Employee ID, a name and a monthly amount; a record for each month paid.
 * Nothing here reaches Salary - the months show up in the P&L as Offline
 * Salary, beside the payroll's own line. The Company Admin's alone.
 */
export default function CrmOfflineEmployeesPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [search, setSearch] = useState('')
  const [editingPerson, setEditingPerson] = useState<CrmOfflineEmployee | 'new' | null>(null)

  const [monthFrom, setMonthFrom] = useState(thisMonth())
  const [monthTo, setMonthTo] = useState(thisMonth())
  const [nameFilter, setNameFilter] = useState('')
  const [createFor, setCreateFor] = useState(thisMonth())
  const [record, setRecord] = useState<CrmOfflineSalary | 'new' | null>(null)

  const people = useQuery({
    queryKey: ['crm', 'offline-employees', search],
    queryFn: () => crm.offlineEmployees.list({ search: search.trim() || undefined }),
  })
  const allPeople = useQuery({
    queryKey: ['crm', 'offline-employees', ''],
    queryFn: () => crm.offlineEmployees.list(),
  })
  const rangeOk = !!monthFrom && !!monthTo && monthTo >= monthFrom
  const records = useQuery({
    queryKey: ['crm', 'offline-salaries', monthFrom, monthTo, nameFilter],
    queryFn: () => crm.offlineEmployees.salaries({ month_from: monthFrom, month_to: monthTo, search: nameFilter.trim() || undefined }),
    enabled: rangeOk,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'offline-employees'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'offline-salaries'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'pl'] })
  }

  const removePerson = useMutation({
    mutationFn: (uuid: string) => crm.offlineEmployees.remove(uuid),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })
  const generate = useMutation({
    mutationFn: () => crm.offlineEmployees.generate(...split(createFor)),
    onSuccess: (res) => {
      refresh()
      toast(res.message, 'success')
      // Show the month just created.
      setMonthFrom((f) => (createFor < f ? createFor : f))
      setMonthTo((t) => (createFor > t ? createFor : t))
    },
    onError: (err) => toastError(errorMessage(err)),
  })
  const removeRecord = useMutation({
    mutationFn: (uuid: string) => crm.offlineEmployees.removeSalary(uuid),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            <Contact className="size-5 text-emerald-500" /> Offline Employees
          </h1>
          <p className="text-sm text-slate-500">
            People paid outside the payroll. Their monthly amounts count in the P&amp;L as Offline Salary, never in Salary.
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
                {people.data.totals.active} active · {inr(people.data.totals.monthly)} a month
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
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Employee ID</th>
                  <th className="py-2 pr-3 font-medium">Name</th>
                  <th className="py-2 pr-3 text-right font-medium">Monthly amount</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Records</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {people.data.data.map((p) => (
                  <tr key={p.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="whitespace-nowrap py-2.5 pr-3 font-medium text-emerald-600">{p.employee_code}</td>
                    <td className="py-2.5 pr-3">
                      <div className="font-medium text-slate-800 dark:text-slate-100">{p.name}</div>
                      {p.note && <div className="max-w-[260px] truncate text-xs text-slate-400" title={p.note}>{p.note}</div>}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(p.monthly_amount)}</td>
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
                          const more = p.records ? ` and their ${p.records} monthly record${p.records === 1 ? '' : 's'}` : ''
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

      {/* ---- Monthly records ---- */}
      <Card>
        <div className="mb-3 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Monthly records</h2>
            {records.data && (
              <p className="text-xs text-slate-400">
                {records.data.totals.count} record{records.data.totals.count === 1 ? '' : 's'} · {inr(records.data.totals.amount)} in view
              </p>
            )}
          </div>
          {/* Creating a month: everybody active, at their monthly amount. */}
          <div className="flex flex-wrap items-end gap-2">
            <div>
              <Label>Create records for</Label>
              <Input type="month" value={createFor} onChange={(e) => setCreateFor(e.target.value)} className="w-40" />
            </div>
            <Button variant="secondary" disabled={!createFor || generate.isPending} onClick={() => generate.mutate()}>
              <CalendarPlus className="size-4" /> {generate.isPending ? 'Creating…' : 'Create month'}
            </Button>
            <Button variant="secondary" disabled={!allPeople.data?.data.length} onClick={() => setRecord('new')}>
              <Plus className="size-4" /> Add record
            </Button>
          </div>
        </div>

        <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-[10rem_10rem_1fr]">
          <div>
            <Label>From month</Label>
            <Input type="month" value={monthFrom} onChange={(e) => setMonthFrom(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>To month</Label>
            <Input type="month" value={monthTo} onChange={(e) => setMonthTo(e.target.value)} className="w-full" />
          </div>
          <div className="col-span-2 sm:col-span-1">
            <Label>Name or Employee ID</Label>
            <Input value={nameFilter} onChange={(e) => setNameFilter(e.target.value)} placeholder="Filter by name…" className="w-full" />
          </div>
        </div>

        {!rangeOk ? (
          <p className="py-6 text-center text-sm text-slate-400">Pick a From month on or before the To month.</p>
        ) : records.isLoading ? (
          <div className="flex justify-center py-10"><Spinner /></div>
        ) : !records.data?.data.length ? (
          <EmptyState title="No records in this range" hint="Create a month, or add one record by hand." />
        ) : (
          <>
            {records.data.totals.by_month.length > 1 && (
              <div className="mb-3 flex flex-wrap gap-2">
                {records.data.totals.by_month.map((m) => (
                  <span key={m.month} className="rounded-lg bg-slate-50 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                    {monthLabel(...split(m.month))}: <span className="font-medium tabular-nums">{inr(m.amount)}</span>
                  </span>
                ))}
              </div>
            )}
            <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Month</th>
                    <th className="py-2 pr-3 font-medium">Employee ID</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 text-right font-medium">Amount</th>
                    <th className="py-2 pr-3 font-medium">Paid on</th>
                    <th className="py-2 pr-3 font-medium">Note</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {records.data.data.map((r) => (
                    <tr key={r.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="whitespace-nowrap py-2.5 pr-3">{monthLabel(r.year, r.month)}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-emerald-600">{r.employee?.employee_code ?? '—'}</td>
                      <td className="py-2.5 pr-3 font-medium text-slate-800 dark:text-slate-100">{r.employee?.name ?? '—'}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium tabular-nums">{inr(r.amount)}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{r.paid_on ?? '—'}</td>
                      <td className="max-w-[220px] truncate py-2.5 pr-3 text-slate-500" title={r.note ?? ''}>{r.note ?? '—'}</td>
                      <td className="whitespace-nowrap py-2.5 text-right">
                        <button onClick={() => setRecord(r)} aria-label="Edit record" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                          <Pencil className="size-4" />
                        </button>
                        <button
                          onClick={() => { if (confirm(`Delete ${r.employee?.name ?? 'this'}'s record for ${monthLabel(r.year, r.month)}?`)) removeRecord.mutate(r.uuid) }}
                          aria-label="Delete record"
                          className="rounded p-1.5 text-slate-400 hover:text-red-500"
                        >
                          <Trash2 className="size-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t border-slate-200 font-semibold dark:border-slate-700">
                    <td className="py-2.5 pr-3" colSpan={3}>Total</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right tabular-nums">{inr(records.data.totals.amount)}</td>
                    <td colSpan={3} />
                  </tr>
                </tfoot>
              </table>
            </div>
          </>
        )}
      </Card>

      {editingPerson && (
        <PersonModal
          person={editingPerson === 'new' ? null : editingPerson}
          onClose={() => setEditingPerson(null)}
          onDone={(message) => { setEditingPerson(null); refresh(); toast(message, 'success') }}
        />
      )}
      {record && (
        <RecordModal
          record={record === 'new' ? null : record}
          people={(allPeople.data?.data ?? []).filter((p) => p.status === 'active' || (record !== 'new' && p.uuid === record.employee?.uuid))}
          defaultMonth={monthTo || thisMonth()}
          onClose={() => setRecord(null)}
          onDone={(message) => { setRecord(null); refresh(); toast(message, 'success') }}
        />
      )}
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
    monthly_amount: person ? String(person.monthly_amount) : '',
    status: person?.status ?? 'active',
    note: person?.note ?? '',
  })
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        employee_code: form.employee_code.trim(),
        name: form.name.trim(),
        monthly_amount: Number(form.monthly_amount) || 0,
        status: form.status as 'active' | 'inactive',
        note: form.note.trim() || null,
      }
      return person ? crm.offlineEmployees.update(person.uuid, payload) : crm.offlineEmployees.create(payload)
    },
    onSuccess: (res) => onDone(res.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={person ? `Edit ${person.name}` : 'Add offline employee'} onClose={onClose}>
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
            <Label>Monthly amount (₹)</Label>
            <Input type="number" min="0" step="0.01" value={form.monthly_amount} onChange={(e) => set('monthly_amount', e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Status</Label>
            <Select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full">
              <option value="active">Active</option>
              <option value="inactive">Inactive (no new months)</option>
            </Select>
          </div>
        </div>
        <div>
          <Label>Note</Label>
          <Input value={form.note} onChange={(e) => set('note', e.target.value)} placeholder="Driver, part-time cleaner…" maxLength={500} className="w-full" />
        </div>
        <Button
          className="w-full"
          disabled={save.isPending || !form.employee_code.trim() || !form.name.trim() || form.monthly_amount === ''}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Saving…' : person ? 'Save changes' : 'Add employee'}
        </Button>
      </div>
    </Modal>
  )
}

function RecordModal({ record, people, defaultMonth, onClose, onDone }: {
  record: CrmOfflineSalary | null
  people: CrmOfflineEmployee[]
  defaultMonth: string
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [employee, setEmployee] = useState(record?.employee?.uuid ?? people[0]?.uuid ?? '')
  const [month, setMonth] = useState(record ? `${record.year}-${String(record.month).padStart(2, '0')}` : defaultMonth)
  const [amount, setAmount] = useState(record ? String(record.amount) : String(people[0]?.monthly_amount ?? ''))
  const [paidOn, setPaidOn] = useState(record?.paid_on ?? '')
  const [note, setNote] = useState(record?.note ?? '')
  const [error, setError] = useState<string | null>(null)

  const pick = (uuid: string) => {
    setEmployee(uuid)
    const person = people.find((p) => p.uuid === uuid)
    if (person && !record) setAmount(String(person.monthly_amount))
  }

  const save = useMutation({
    mutationFn: () => {
      if (record) {
        return crm.offlineEmployees.updateSalary(record.uuid, { amount: Number(amount) || 0, paid_on: paidOn || null, note: note.trim() || null })
      }
      const [year, m] = split(month)
      return crm.offlineEmployees.addSalary({
        offline_employee_uuid: employee, year, month: m, amount: Number(amount) || 0, paid_on: paidOn || null, note: note.trim() || null,
      })
    },
    onSuccess: (res) => onDone(res.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      title={record ? `${record.employee?.name ?? 'Record'} — ${monthLabel(record.year, record.month)}` : 'Add monthly record'}
      onClose={onClose}
    >
      <div className="space-y-3">
        <ErrorNote message={error} />
        {!record && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Employee</Label>
              <Select value={employee} onChange={(e) => pick(e.target.value)} className="w-full">
                {people.map((p) => <option key={p.uuid} value={p.uuid}>{p.employee_code} · {p.name}</option>)}
              </Select>
            </div>
            <div>
              <Label>Month</Label>
              <Input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="w-full" />
            </div>
          </div>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Amount (₹)</Label>
            <Input type="number" min="0" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Paid on</Label>
            <Input type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} className="w-full" />
          </div>
        </div>
        <div>
          <Label>Note</Label>
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Cash, UPI, extra days…" maxLength={500} className="w-full" />
        </div>
        <Button
          className="w-full"
          disabled={save.isPending || amount === '' || (!record && (!employee || !month))}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Saving…' : record ? 'Save record' : 'Add record'}
        </Button>
      </div>
    </Modal>
  )
}
