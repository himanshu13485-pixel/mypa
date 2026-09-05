import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote, Download, Paperclip, Pencil, Plus, Search, Trash2, Undo2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmExpense } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, ColumnChart, DonutChart } from './charts'
import { StatusChip } from './CrmVendorsPage'
import { crmPath } from '../../lib/crmPath'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

const EMPTY = {
  expense_date: new Date().toISOString().slice(0, 10),
  due_date: '',
  issuing_company_id: '', vendor_uuid: '', category: '',
  description: '', base_amount: '',
  cgst_amount: '', sgst_amount: '', igst_amount: '',
  cgst_rate: '', sgst_rate: '', igst_rate: '',
  other_tax_label: '', other_tax_rate: '', other_tax_amount: '',
  bill_available: false, gst_claimed: false, payment_mode: '', note: '',
}

/** The lines a bill can carry, in the order they are read off it. */
const TAX_KEYS = ['cgst', 'sgst', 'igst', 'other_tax'] as const

/** What a rate comes to on this base — two decimals, like the paper. */
const atRate = (base: string, rate: string) =>
  rate === '' ? '' : String(Math.round((Number(base) || 0) * (Number(rate) || 0)) / 100)

export default function CrmExpensesPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [category, setCategory] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [gstFilter, setGstFilter] = useState('')
  const [payFilter, setPayFilter] = useState('')
  const [paying, setPaying] = useState<CrmExpense | null>(null)
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmExpense | null>(null)
  const [form, setForm] = useState({ ...EMPTY })
  const [billFile, setBillFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  // A bill can only name a registered vendor, so the register is the list.
  const { data: vendorOptions } = useQuery({ queryKey: ['crm', 'vendor-options'], queryFn: crm.vendors.options })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'expenses', applied, category, dateFrom, dateTo, gstFilter, payFilter, page],
    queryFn: () =>
      crm.expenses.list({
        search: applied || undefined,
        category: category || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        gst_claimed: gstFilter === '' ? undefined : gstFilter,
        payment_status: payFilter || undefined,
        page,
      }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'expenses'] })
    // What a vendor is owed is read from these bills, so it moves too.
    queryClient.invalidateQueries({ queryKey: ['crm', 'vendors'] })
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY, expense_date: new Date().toISOString().slice(0, 10) })
    setBillFile(null)
    setError(null)
    setShowForm(true)
  }

  const openEdit = (e: CrmExpense) => {
    setEditing(e)
    setForm({
      expense_date: e.expense_date,
      due_date: e.due_date ?? '',
      issuing_company_id: e.issuing_company_id ? String(e.issuing_company_id) : '',
      vendor_uuid: e.vendor_uuid ?? '',
      category: e.category ?? '',
      description: e.description ?? '',
      base_amount: String(Number(e.base_amount)),
      cgst_amount: Number(e.cgst_amount) ? String(Number(e.cgst_amount)) : '',
      sgst_amount: Number(e.sgst_amount) ? String(Number(e.sgst_amount)) : '',
      igst_amount: Number(e.igst_amount) ? String(Number(e.igst_amount)) : '',
      cgst_rate: e.cgst_rate ? String(Number(e.cgst_rate)) : '',
      sgst_rate: e.sgst_rate ? String(Number(e.sgst_rate)) : '',
      igst_rate: e.igst_rate ? String(Number(e.igst_rate)) : '',
      other_tax_label: e.other_tax_label ?? '',
      other_tax_rate: e.other_tax_rate ? String(Number(e.other_tax_rate)) : '',
      other_tax_amount: Number(e.other_tax_amount) ? String(Number(e.other_tax_amount)) : '',
      bill_available: e.bill_available,
      gst_claimed: e.gst_claimed,
      payment_mode: e.payment_mode ?? '',
      note: e.note ?? '',
    })
    setBillFile(null)
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        expense_date: form.expense_date,
        due_date: form.due_date || null,
        issuing_company_id: form.issuing_company_id ? Number(form.issuing_company_id) : null,
        vendor_uuid: form.vendor_uuid,
        category: form.category || null,
        description: form.description || null,
        base_amount: Number(form.base_amount) || 0,
        cgst_amount: form.cgst_amount ? Number(form.cgst_amount) : 0,
        sgst_amount: form.sgst_amount ? Number(form.sgst_amount) : 0,
        igst_amount: form.igst_amount ? Number(form.igst_amount) : 0,
        cgst_rate: form.cgst_rate === '' ? null : Number(form.cgst_rate),
        sgst_rate: form.sgst_rate === '' ? null : Number(form.sgst_rate),
        igst_rate: form.igst_rate === '' ? null : Number(form.igst_rate),
        other_tax_label: form.other_tax_label || null,
        other_tax_rate: form.other_tax_rate === '' ? null : Number(form.other_tax_rate),
        other_tax_amount: form.other_tax_amount ? Number(form.other_tax_amount) : 0,
        bill_available: form.bill_available,
        gst_claimed: form.gst_claimed,
        payment_mode: form.payment_mode || null,
        note: form.note || null,
      }
      const res = editing
        ? await crm.expenses.update(editing.uuid, payload)
        : await crm.expenses.create(payload)
      const uuid = editing?.uuid ?? (res as { data?: { uuid?: string } }).data?.uuid
      if (billFile && uuid) {
        await crm.expenses.uploadBill(uuid, billFile)
      }
      return res
    },
    onSuccess: () => {
      refresh()
      setShowForm(false)
      toast('Expense saved.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.expenses.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const downloadBill = async (e: CrmExpense) => {
    const doc = e.documents[0]
    if (!doc) return
    try {
      const blob = await crm.expenses.downloadBill(e.uuid, doc.uuid)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = doc.name
      a.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const set = (key: keyof typeof EMPTY, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }))

  // A rate typed on any line drives its amount off the base; a base that
  // changes drags every rated line with it. An amount typed straight in
  // stands on its own, so a bill that rounds its own way still goes in as
  // the paper reads — and the rate steps aside.
  const setBase = (value: string) => setForm((f) => {
    const next: Record<string, unknown> = { ...f, base_amount: value }
    TAX_KEYS.forEach((k) => {
      const rate = String(next[`${k}_rate`] ?? '')
      if (rate !== '') next[`${k}_amount`] = atRate(value, rate)
    })
    return next as typeof f
  })

  const setRate = (key: (typeof TAX_KEYS)[number], value: string) => setForm((f) => ({
    ...f,
    [`${key}_rate`]: value,
    [`${key}_amount`]: atRate(f.base_amount, value),
  }))

  const setAmount = (key: (typeof TAX_KEYS)[number], value: string) => setForm((f) => ({
    ...f,
    [`${key}_amount`]: value,
    [`${key}_rate`]: '',
  }))

  const taxTotal = TAX_KEYS.reduce(
    (sum, k) => sum + (Number((form as Record<string, unknown>)[`${k}_amount`]) || 0), 0,
  )
  const grandTotal = (Number(form.base_amount) || 0) + taxTotal

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Expenses</h1>
          <p className="text-sm text-slate-500">
            {data
              ? <>{data.summary.count} entries · {inr(data.summary.total)} billed · {inr(data.summary.outstanding)} still owed</>
              : 'Office spend register.'}
          </p>
        </div>
        <Button onClick={openCreate}><Plus className="size-4" /> Add expense</Button>
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Billed', value: inr(data.summary.total) },
            { label: 'Paid out', value: inr(data.summary.paid) },
            {
              label: `Outstanding · ${data.summary.unpaid_bills} bill${data.summary.unpaid_bills === 1 ? '' : 's'}`,
              value: inr(data.summary.outstanding),
              tone: data.summary.outstanding > 0 ? 'text-amber-600 dark:text-amber-400' : '',
            },
            {
              label: `Overdue · ${data.summary.overdue_bills} bill${data.summary.overdue_bills === 1 ? '' : 's'}`,
              value: inr(data.summary.overdue),
              tone: data.summary.overdue > 0 ? 'text-red-500' : '',
            },
          ].map((c) => (
            <Card key={c.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
              <div className="text-xs text-slate-500">{c.label}</div>
            </Card>
          ))}
        </div>
      )}

      {data && data.summary.by_category.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Spend by category</h2>
            <DonutChart
              data={data.summary.by_category.slice(0, 5).map((c, i) => ({ label: c.category, value: c.amount, color: CHART_COLORS[i % CHART_COLORS.length] }))}
              centerLabel="₹ spent"
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Spend by month</h2>
            <ColumnChart data={data.summary.by_month.map((m) => ({ label: m.month.slice(2), value: m.amount }))} color={CHART_COLORS[2]} />
          </Card>
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Vendor, description, GSTIN…" className="w-full pl-9" />
          </div>
          <Select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1) }}>
            <option value="">All categories</option>
            {masters?.expense_categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
          <Select value={payFilter} onChange={(e) => { setPayFilter(e.target.value); setPage(1) }}>
            <option value="">Payment: all</option>
            <option value="unpaid">Unpaid</option>
            <option value="part">Part paid</option>
            <option value="paid">Paid</option>
            <option value="overdue">Overdue</option>
          </Select>
          <Select value={gstFilter} onChange={(e) => { setGstFilter(e.target.value); setPage(1) }}>
            <option value="">GST: all</option>
            <option value="1">GST claimed</option>
            <option value="0">GST not claimed</option>
          </Select>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No expenses found" hint="Record office spend to see it here." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[1080px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Date</th>
                  <th className="py-2 pr-3 font-medium">Vendor</th>
                  <th className="py-2 pr-3 font-medium">Category</th>
                  <th className="py-2 pr-3 text-right font-medium">Base</th>
                  <th className="py-2 pr-3 text-right font-medium">Tax</th>
                  <th className="py-2 pr-3 text-right font-medium">Total</th>
                  <th className="py-2 pr-3 text-right font-medium">Paid</th>
                  <th className="py-2 pr-3 text-right font-medium">Balance</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Bill</th>
                  <th className="py-2 pr-3 font-medium">GST</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((e) => {
                  const gst = Number(e.cgst_amount) + Number(e.sgst_amount) + Number(e.igst_amount)
                  // Whatever else the bill charged rides in the same column.
                  const tax = gst + Number(e.other_tax_amount)
                  return (
                    <tr key={e.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                      <td className="whitespace-nowrap py-2.5 pr-3">
                        {e.expense_date}
                        {e.due_date && (
                          <div className={clsx('text-xs', e.overdue ? 'text-red-500' : 'text-slate-400')}>
                            due {e.due_date}
                          </div>
                        )}
                      </td>
                      <td className="max-w-[220px] py-2.5 pr-3">
                        <div className="truncate font-medium text-slate-800 dark:text-slate-100">{e.vendor_name}</div>
                        <div className="truncate text-xs text-slate-400">{e.description ?? e.issuing_company ?? ''}</div>
                      </td>
                      <td className="py-2.5 pr-3">{e.category ?? '—'}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(e.base_amount)}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">
                        {tax ? inr(tax) : '—'}
                        {Number(e.other_tax_amount) > 0 && (
                          <div className="text-[10px] text-slate-400">incl. {e.other_tax_label ?? 'other'}</div>
                        )}
                      </td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{inr(e.total_amount)}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">
                        {Number(e.amount_paid) ? inr(e.amount_paid) : '—'}
                      </td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                        {e.balance > 0
                          ? <span className={clsx('font-medium', e.overdue ? 'text-red-500' : 'text-amber-600 dark:text-amber-400')}>{inr(e.balance)}</span>
                          : <span className="text-slate-400">—</span>}
                      </td>
                      <td className="py-2.5 pr-3">
                        <StatusChip status={e.payment_status} overdue={e.overdue} />
                      </td>
                      <td className="py-2.5 pr-3">
                        {e.documents.length > 0 ? (
                          <button onClick={() => downloadBill(e)} className="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 hover:underline">
                            <Download className="size-3.5" /> Bill
                          </button>
                        ) : e.bill_available ? (
                          <span className="text-xs text-slate-500">Yes</span>
                        ) : (
                          <span className="text-xs text-red-400">No</span>
                        )}
                      </td>
                      <td className="py-2.5 pr-3">
                        <span className={clsx(
                          'rounded-full px-2 py-0.5 text-[11px] font-medium',
                          e.gst_claimed
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                            : gst > 0
                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'
                              : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                        )}>
                          {e.gst_claimed ? 'Claimed' : gst > 0 ? 'Pending' : 'N/A'}
                        </span>
                      </td>
                      <td className="whitespace-nowrap py-2.5 text-right">
                        {e.balance > 0 && (
                          <button
                            onClick={() => setPaying(e)}
                            aria-label="Record payment"
                            title="Record a payment"
                            className="rounded p-1.5 text-slate-400 hover:text-emerald-600"
                          >
                            <Banknote className="size-4" />
                          </button>
                        )}
                        <button onClick={() => openEdit(e)} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                          <Pencil className="size-4" />
                        </button>
                        <button onClick={() => { if (confirm(`Delete the ${e.vendor_name} expense?`)) deleteMutation.mutate(e.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                          <Trash2 className="size-4" />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {paying && (
        <PayBillDialog
          expense={paying}
          modes={masters?.payment_modes ?? []}
          onClose={() => setPaying(null)}
          onDone={(next) => { refresh(); setPaying(next) }}
        />
      )}

      {showForm && (
        <Modal title={editing ? `Edit ${editing.vendor_name}` : 'Add expense'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Expense date</Label>
                <Input type="date" value={form.expense_date} onChange={(e) => set('expense_date', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Paid by (issuing company)</Label>
                <Select value={form.issuing_company_id} onChange={(e) => set('issuing_company_id', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.issuing_companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
              </div>
              <div>
                <Label>Vendor</Label>
                <Select
                  value={form.vendor_uuid}
                  onChange={(e) => {
                    const picked = vendorOptions?.data.find((v) => v.uuid === e.target.value)
                    setForm((f) => ({
                      ...f,
                      vendor_uuid: e.target.value,
                      // Their agreed terms set the due date unless one is typed.
                      due_date: f.due_date || (picked?.payment_terms_days != null
                        ? new Date(new Date(f.expense_date).getTime() + picked.payment_terms_days * 86400000)
                          .toISOString().slice(0, 10)
                        : ''),
                    }))
                  }}
                  className="w-full"
                >
                  <option value="">Select a registered vendor</option>
                  {vendorOptions?.data.map((v) => (
                    <option key={v.uuid} value={v.uuid}>{v.company_name}{v.gst_no ? ` · ${v.gst_no}` : ''}</option>
                  ))}
                </Select>
                <p className="mt-1 text-xs text-slate-400">
                  Not listed?{' '}
                  <Link to={crmPath('/crm/vendors')} className="font-medium text-emerald-600 hover:underline">Register the vendor</Link>{' '}
                  first — their name, GSTIN and bank details come from that record.
                </p>
              </div>
              <div>
                <Label>Payment due</Label>
                <Input type="date" value={form.due_date} onChange={(e) => set('due_date', e.target.value)} className="w-full" />
                <p className="mt-1 text-xs text-slate-400">Leave blank if the bill was settled on the spot.</p>
              </div>
              <div>
                <Label>Category</Label>
                <Select value={form.category} onChange={(e) => set('category', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.expense_categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
              </div>
              <div>
                <Label>Payment mode</Label>
                <Select value={form.payment_mode} onChange={(e) => set('payment_mode', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.payment_modes.map((m) => <option key={m} value={m}>{m}</option>)}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Label>Product / service description</Label>
                <Input value={form.description} onChange={(e) => set('description', e.target.value)} className="w-full" />
              </div>
              <div className="sm:col-span-2">
                <Label>Base amount (₹)</Label>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.base_amount}
                  onChange={(e) => setBase(e.target.value)}
                  className="w-full sm:max-w-[240px]"
                />
              </div>

              <div className="sm:col-span-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="mb-1.5 grid grid-cols-[1fr_4.5rem_7rem] items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                  <span>Tax</span>
                  <span className="text-right">Rate %</span>
                  <span className="text-right">Amount ₹</span>
                </div>

                {([['cgst', 'CGST'], ['sgst', 'SGST'], ['igst', 'IGST']] as const).map(([key, label]) => (
                  <div key={key} className="mb-1.5 grid grid-cols-[1fr_4.5rem_7rem] items-center gap-2">
                    <span className="text-sm text-slate-600 dark:text-slate-300">{label}</span>
                    <Input
                      type="number"
                      min="0"
                      max="100"
                      step="0.01"
                      value={form[`${key}_rate`]}
                      onChange={(e) => setRate(key, e.target.value)}
                      className="w-full text-right"
                      placeholder="—"
                    />
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={form[`${key}_amount`]}
                      onChange={(e) => setAmount(key, e.target.value)}
                      className="w-full text-right"
                      placeholder="0"
                    />
                  </div>
                ))}

                {/* Whatever else the bill carries — a cess, a levy — under
                    the name the bill gives it. */}
                <div className="grid grid-cols-[1fr_4.5rem_7rem] items-center gap-2">
                  <Input
                    value={form.other_tax_label}
                    onChange={(e) => set('other_tax_label', e.target.value)}
                    className="w-full"
                    placeholder="Other tax (name it)"
                  />
                  <Input
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    value={form.other_tax_rate}
                    onChange={(e) => setRate('other_tax', e.target.value)}
                    className="w-full text-right"
                    placeholder="—"
                  />
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    value={form.other_tax_amount}
                    onChange={(e) => setAmount('other_tax', e.target.value)}
                    className="w-full text-right"
                    placeholder="0"
                  />
                </div>

                <p className="mt-2 text-xs text-slate-400">
                  Type a rate and the amount follows the base; type an amount straight in and the rate steps aside.
                </p>
              </div>
              <div className="flex items-center gap-4">
                <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                  <input type="checkbox" checked={form.bill_available} onChange={(e) => set('bill_available', e.target.checked)} className="size-4 accent-emerald-600" />
                  Bill available
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                  <input type="checkbox" checked={form.gst_claimed} onChange={(e) => set('gst_claimed', e.target.checked)} className="size-4 accent-emerald-600" />
                  GST claimed
                </label>
              </div>
              <div>
                <Label><Paperclip className="mr-1 inline size-3.5" />Attach bill (max 10 MB)</Label>
                <input
                  type="file"
                  onChange={(e) => setBillFile(e.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-medium file:text-slate-700 dark:file:bg-slate-800 dark:file:text-slate-200"
                />
              </div>
            </div>
            <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-2.5 text-sm dark:bg-slate-800/60">
              <span className="text-slate-500">
                Total (base + tax)
                {taxTotal > 0 && <span className="ml-1 text-xs">· {inr(taxTotal)} tax</span>}
              </span>
              <span className="text-base font-semibold">{inr(grandTotal)}</span>
            </div>
            <Button className="w-full" disabled={!form.vendor_uuid || !form.base_amount || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : 'Save expense'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

/**
 * Paying a bill. The whole balance goes out by default — the one click the
 * register is usually asked for — but a part payment is ordinary, and a
 * payment entered by mistake is removed rather than typed over.
 */
function PayBillDialog({ expense, modes, onClose, onDone }: {
  expense: CrmExpense
  modes: string[]
  onClose: () => void
  onDone: (next: CrmExpense) => void
}) {
  const { toast, toastError } = useToast()
  const [amount, setAmount] = useState(String(expense.balance))
  const [paidOn, setPaidOn] = useState(new Date().toISOString().slice(0, 10))
  const [mode, setMode] = useState(expense.payment_mode ?? '')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const payMutation = useMutation({
    mutationFn: () => crm.expenses.pay(expense.uuid, {
      amount: Number(amount) || undefined,
      paid_on: paidOn,
      payment_mode: mode || null,
      reference_no: reference || null,
      note: note || null,
    }),
    onSuccess: (res) => {
      toast(res.message, 'success')
      setAmount(String(res.data.balance))
      setReference('')
      onDone(res.data)
      if (res.data.balance <= 0) onClose()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const undoMutation = useMutation({
    mutationFn: (paymentUuid: string) => crm.expenses.unpay(expense.uuid, paymentUuid),
    onSuccess: (res) => {
      toast(res.message, 'info')
      setAmount(String(res.data.balance))
      onDone(res.data)
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={`Pay ${expense.vendor_name}`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />

        <div className="grid grid-cols-3 gap-2 text-center">
          {[
            { label: 'Bill', value: inr(expense.total_amount) },
            { label: 'Paid', value: inr(expense.amount_paid) },
            { label: 'Balance', value: inr(expense.balance), tone: expense.balance > 0 ? 'text-amber-600 dark:text-amber-400' : '' },
          ].map((c) => (
            <div key={c.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
              <div className={clsx('text-base font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
              <div className="text-[11px] text-slate-500">{c.label}</div>
            </div>
          ))}
        </div>

        {expense.balance > 0 && (
          <>
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Amount paid (₹)</Label>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  max={expense.balance}
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  className="w-full"
                />
                <p className="mt-1 text-xs text-slate-400">Full balance is filled in — lower it for a part payment.</p>
              </div>
              <div>
                <Label>Paid on</Label>
                <Input type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Payment mode</Label>
                <Select value={mode} onChange={(e) => setMode(e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {modes.map((m) => <option key={m} value={m}>{m}</option>)}
                </Select>
              </div>
              <div>
                <Label>Reference / UTR</Label>
                <Input value={reference} onChange={(e) => setReference(e.target.value)} className="w-full" />
              </div>
              <div className="sm:col-span-2">
                <Label>Note</Label>
                <Input value={note} onChange={(e) => setNote(e.target.value)} className="w-full" />
              </div>
            </div>
            <Button
              className="w-full"
              disabled={!amount || Number(amount) <= 0 || payMutation.isPending}
              onClick={() => { setError(null); payMutation.mutate() }}
            >
              <Banknote className="size-4" />
              {payMutation.isPending
                ? 'Recording…'
                : Number(amount) >= expense.balance ? 'Mark paid in full' : 'Record part payment'}
            </Button>
          </>
        )}

        {expense.payments.length > 0 && (
          <div>
            <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Payments made</h3>
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {expense.payments.map((p) => (
                <li key={p.uuid} className="flex items-center justify-between gap-2 py-2">
                  <div className="min-w-0">
                    <div className="font-medium text-slate-800 dark:text-slate-100">{inr(p.amount)}</div>
                    <div className="truncate text-xs text-slate-400">
                      {[p.paid_on, p.payment_mode, p.reference_no, p.created_by].filter(Boolean).join(' · ')}
                    </div>
                  </div>
                  <button
                    onClick={() => { if (confirm('Remove this payment? The bill goes back to owing it.')) undoMutation.mutate(p.uuid) }}
                    className="shrink-0 rounded p-1.5 text-slate-400 hover:text-red-500"
                    aria-label="Remove payment"
                    title="Entered by mistake? Remove it."
                  >
                    <Undo2 className="size-4" />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  )
}
