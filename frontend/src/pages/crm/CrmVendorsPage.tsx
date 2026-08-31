import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe, type CrmVendor } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner } from '../../components/ui'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

const EMPTY = {
  company_name: '', contact_person: '', designation: '', address: '', city: '', state: '',
  pincode: '', country: '', telephone: '', mobile: '', email: '', website: '',
  gst_no: '', pan_no: '', category: '', payment_terms_days: '',
  bank_name: '', bank_account_no: '', bank_ifsc: '', bank_branch: '',
  status: 'active', notes: '',
}

export default function CrmVendorsPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const canCreate = crmCan(me, 'expenses', 'create')
  const canEdit = crmCan(me, 'expenses', 'edit')
  const canDelete = crmCan(me, 'expenses', 'delete')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmVendor | null>(null)
  const [detail, setDetail] = useState<CrmVendor | null>(null)
  const [form, setForm] = useState({ ...EMPTY })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'vendors', applied, status, category, page],
    queryFn: () => crm.vendors.list({
      search: applied || undefined,
      status: status || undefined,
      category: category || undefined,
      page,
    }),
  })

  const { data: detailData } = useQuery({
    queryKey: ['crm', 'vendors', detail?.uuid],
    queryFn: () => crm.vendors.show(detail!.uuid),
    enabled: !!detail,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'vendors'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'expenses'] })
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY })
    setError(null)
    setShowForm(true)
  }

  const openEdit = (v: CrmVendor) => {
    setEditing(v)
    setForm({
      company_name: v.company_name,
      contact_person: v.contact_person ?? '',
      designation: v.designation ?? '',
      address: v.address ?? '',
      city: v.city ?? '',
      state: v.state ?? '',
      pincode: v.pincode ?? '',
      country: v.country ?? '',
      telephone: v.telephone ?? '',
      mobile: v.mobile ?? '',
      email: v.email ?? '',
      website: v.website ?? '',
      gst_no: v.gst_no ?? '',
      pan_no: v.pan_no ?? '',
      category: v.category ?? '',
      payment_terms_days: v.payment_terms_days === null ? '' : String(v.payment_terms_days),
      bank_name: v.bank_name ?? '',
      bank_account_no: v.bank_account_no ?? '',
      bank_ifsc: v.bank_ifsc ?? '',
      bank_branch: v.bank_branch ?? '',
      status: v.status,
      notes: v.notes ?? '',
    })
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = Object.fromEntries(
        Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]),
      )
      payload.payment_terms_days = form.payment_terms_days === '' ? null : Number(form.payment_terms_days)
      return editing ? crm.vendors.update(editing.uuid, payload) : crm.vendors.create(payload)
    },
    onSuccess: (res) => {
      refresh()
      setShowForm(false)
      toast(res.message, 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.vendors.remove(uuid),
    onSuccess: (res) => {
      refresh()
      toast(res.message, res.retired ? 'info' : 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const set = (key: keyof typeof EMPTY, value: string) => setForm((f) => ({ ...f, [key]: value }))

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Vendors</h1>
          <p className="text-sm text-slate-500">
            {data
              ? <>{data.summary.vendors} registered · {inr(data.summary.billed)} billed · {inr(data.summary.outstanding)} outstanding</>
              : 'Suppliers the company buys from — registered once, billed against.'}
          </p>
        </div>
        {canCreate && <Button onClick={openCreate}><Plus className="size-4" /> Register vendor</Button>}
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Registered vendors', value: String(data.summary.vendors) },
            { label: 'Active', value: String(data.summary.active) },
            { label: 'Total billed', value: inr(data.summary.billed) },
            {
              label: data.summary.overdue_bills
                ? `Outstanding · ${data.summary.overdue_bills} overdue`
                : 'Outstanding',
              value: inr(data.summary.outstanding),
              tone: data.summary.outstanding > 0 ? 'text-red-500' : '',
            },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', s.tone)}>{s.value}</div>
              <div className="text-xs text-slate-500">{s.label}</div>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[240px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, contact, GSTIN, email…" className="w-full pl-9" />
          </div>
          <Select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1) }}>
            <option value="">All categories</option>
            {data?.categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title="No vendors registered"
            hint="Register a supplier before entering a bill against them — the same way a client is registered before an invoice."
          />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[880px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Vendor</th>
                  <th className="py-2 pr-3 font-medium">Category</th>
                  <th className="py-2 pr-3 font-medium">GSTIN</th>
                  <th className="py-2 pr-3 text-right font-medium">Bills</th>
                  <th className="py-2 pr-3 text-right font-medium">Billed</th>
                  <th className="py-2 pr-3 text-right font-medium">Outstanding</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((v) => (
                  <tr key={v.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="max-w-[240px] py-2.5 pr-3">
                      <button onClick={() => setDetail(v)} className="truncate text-left font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100">
                        {v.company_name}
                      </button>
                      <div className="truncate text-xs text-slate-400">
                        {[v.contact_person, v.city].filter(Boolean).join(' · ') || '—'}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3 text-slate-500">{v.category ?? '—'}</td>
                    <td className="py-2.5 pr-3 text-xs text-slate-500">{v.gst_no ?? '—'}</td>
                    <td className="py-2.5 pr-3 text-right text-slate-500">{v.bills || '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{v.billed ? inr(v.billed) : '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                      {v.outstanding > 0 ? (
                        <span className={clsx('font-medium', v.overdue_bills > 0 ? 'text-red-500' : 'text-amber-600 dark:text-amber-400')}>
                          {inr(v.outstanding)}
                          {v.overdue_bills > 0 && <span className="ml-1 text-[10px]">({v.overdue_bills} overdue)</span>}
                        </span>
                      ) : <span className="text-slate-400">—</span>}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        v.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                      )}>
                        {v.status === 'active' ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      {canEdit && (
                        <button onClick={() => openEdit(v)} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                          <Pencil className="size-4" />
                        </button>
                      )}
                      {canDelete && (
                        <button
                          onClick={() => {
                            const msg = v.bills > 0
                              ? `${v.company_name} has ${v.bills} bill(s) on record and will be marked inactive instead of deleted. Continue?`
                              : `Remove ${v.company_name}?`
                            if (confirm(msg)) deleteMutation.mutate(v.uuid)
                          }}
                          aria-label="Remove"
                          className="rounded p-1.5 text-slate-400 hover:text-red-500"
                        >
                          <Trash2 className="size-4" />
                        </button>
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

      {detail && (
        <Modal title={detail.company_name} onClose={() => setDetail(null)} wide>
          {!detailData ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : (
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-3">
                {[
                  { label: 'Billed', value: inr(detailData.billed) },
                  { label: 'Paid', value: inr(detailData.paid) },
                  { label: 'Outstanding', value: inr(detailData.outstanding), tone: detailData.outstanding > 0 ? 'text-red-500' : '' },
                ].map((s) => (
                  <div key={s.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
                    <div className={clsx('text-base font-semibold text-slate-900 dark:text-white', s.tone)}>{s.value}</div>
                    <div className="text-[11px] text-slate-500">{s.label}</div>
                  </div>
                ))}
              </div>

              <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                {[
                  ['Contact', [detailData.contact_person, detailData.designation].filter(Boolean).join(', ')],
                  ['Phone', [detailData.mobile, detailData.telephone].filter(Boolean).join(' / ')],
                  ['Email', detailData.email],
                  ['GSTIN', detailData.gst_no],
                  ['PAN', detailData.pan_no],
                  ['Payment terms', detailData.payment_terms_days === null ? null : `${detailData.payment_terms_days} days`],
                  ['Address', [detailData.address, detailData.city, detailData.state, detailData.pincode].filter(Boolean).join(', ')],
                  ['Bank', [detailData.bank_name, detailData.bank_account_no, detailData.bank_ifsc].filter(Boolean).join(' · ')],
                ].filter(([, v]) => v).map(([k, v]) => (
                  <div key={k as string}>
                    <dt className="text-xs text-slate-400">{k}</dt>
                    <dd className="text-slate-700 dark:text-slate-200">{v}</dd>
                  </div>
                ))}
              </dl>

              <div>
                <h3 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Recent bills</h3>
                {detailData.recent_bills.length === 0 ? (
                  <p className="text-sm text-slate-400">Nothing billed against this vendor yet.</p>
                ) : (
                  <div className="-mx-4 overflow-x-auto px-4">
                    <table className="w-full min-w-[520px] text-sm">
                      <tbody>
                        {detailData.recent_bills.map((b) => (
                          <tr key={b.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                            <td className="whitespace-nowrap py-2 pr-3 text-slate-500">{b.expense_date}</td>
                            <td className="max-w-[200px] truncate py-2 pr-3">{b.description ?? b.category ?? '—'}</td>
                            <td className="whitespace-nowrap py-2 pr-3 text-right font-medium">{inr(b.total_amount)}</td>
                            <td className="whitespace-nowrap py-2 pr-3 text-right text-slate-500">
                              {b.balance > 0 ? `${inr(b.balance)} due` : 'settled'}
                            </td>
                            <td className="py-2 text-right">
                              <StatusChip status={b.payment_status} overdue={b.overdue} />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}
        </Modal>
      )}

      {showForm && (
        <Modal title={editing ? `Edit ${editing.company_name}` : 'Register vendor'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label>Vendor / company name</Label>
                <Input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Contact person</Label>
                <Input value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Designation</Label>
                <Input value={form.designation} onChange={(e) => set('designation', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Mobile</Label>
                <Input value={form.mobile} onChange={(e) => set('mobile', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Telephone</Label>
                <Input value={form.telephone} onChange={(e) => set('telephone', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Email</Label>
                <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Website</Label>
                <Input value={form.website} onChange={(e) => set('website', e.target.value)} className="w-full" />
              </div>
              <div className="sm:col-span-2">
                <Label>Address</Label>
                <Input value={form.address} onChange={(e) => set('address', e.target.value)} className="w-full" />
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>City</Label>
                  <Input value={form.city} onChange={(e) => set('city', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>State</Label>
                  <Input value={form.state} onChange={(e) => set('state', e.target.value)} className="w-full" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Pincode</Label>
                  <Input value={form.pincode} onChange={(e) => set('pincode', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>Country</Label>
                  <Input value={form.country} onChange={(e) => set('country', e.target.value)} className="w-full" />
                </div>
              </div>
              <div>
                <Label>GSTIN</Label>
                <Input value={form.gst_no} onChange={(e) => set('gst_no', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>PAN</Label>
                <Input value={form.pan_no} onChange={(e) => set('pan_no', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>What they supply</Label>
                <Select value={form.category} onChange={(e) => set('category', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {data?.categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
              </div>
              <div>
                <Label>Payment terms (days)</Label>
                <Input
                  type="number"
                  min="0"
                  value={form.payment_terms_days}
                  onChange={(e) => set('payment_terms_days', e.target.value)}
                  className="w-full"
                  placeholder="e.g. 30"
                />
                <p className="mt-1 text-xs text-slate-400">A bill with no due date of its own gets one this many days out.</p>
              </div>
            </div>

            <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
              <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <Building2 className="size-3.5" /> Where their money goes
              </h3>
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <Label>Bank name</Label>
                  <Input value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>Branch</Label>
                  <Input value={form.bank_branch} onChange={(e) => set('bank_branch', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>Account number</Label>
                  <Input value={form.bank_account_no} onChange={(e) => set('bank_account_no', e.target.value)} className="w-full" />
                </div>
                <div>
                  <Label>IFSC</Label>
                  <Input value={form.bank_ifsc} onChange={(e) => set('bank_ifsc', e.target.value)} className="w-full" />
                </div>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Status</Label>
                <Select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </Select>
              </div>
              <div>
                <Label>Notes</Label>
                <Input value={form.notes} onChange={(e) => set('notes', e.target.value)} className="w-full" />
              </div>
            </div>

            <Button className="w-full" disabled={!form.company_name || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : editing ? 'Save vendor' : 'Register vendor'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

export function StatusChip({ status, overdue }: { status: 'unpaid' | 'part' | 'paid'; overdue?: boolean }) {
  const label = status === 'paid' ? 'Paid' : status === 'part' ? 'Part paid' : overdue ? 'Overdue' : 'Unpaid'
  return (
    <span className={clsx(
      'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
      status === 'paid'
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
        : overdue
          ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
          : status === 'part'
            ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'
            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
    )}>
      {label}
    </span>
  )
}
