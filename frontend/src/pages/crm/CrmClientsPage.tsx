import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, Check, Pencil, Plus, Search, Trash2, Users, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmAllows, CRM_CLIENT_CATEGORY_LABELS, type CrmClient } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { codeCase, companyCase, emailCase, nameCase } from './textCase'

const TITLES = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.']

const EMPTY = {
  company_name: '', title: '', contact_person: '', designation: '', address: '',
  city: '', state: '', pincode: '', country: 'India', telephone: '', mobile: '',
  email: '', alternate_email: '', website: '', gst_no: '', pan_no: '',
  category: '', status: 'active', notes: '', assigned_member_uuid: '',
}

export default function CrmClientsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [status, setStatus] = useState('active')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<CrmClient | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ ...EMPTY })
  // Colleagues a manager is letting in on this client, by member uuid.
  const [shareWith, setShareWith] = useState<string[]>([])
  // The client being handed to somebody else, and who is getting it.
  const [transferring, setTransferring] = useState<CrmClient | null>(null)
  const [transferTo, setTransferTo] = useState('')
  const [transferNote, setTransferNote] = useState('')
  const [tab, setTab] = useState<'clients' | 'requests'>(
    new URLSearchParams(window.location.search).get('tab') === 'requests' ? 'requests' : 'clients',
  )
  // Dedicated Company Workspace values, keyed by field key.
  const [customValues, setCustomValues] = useState<Record<string, string | boolean>>({})
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'clients', applied, status, page],
    queryFn: () => crm.clients.list({ search: applied || undefined, status: status || undefined, page }),
  })
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })

  // Only Admin/Subadmin hand clients around; everyone else owns what they add.
  const isManager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const canDelete = crmAllows(me, 'clients.delete')
  // What the client is billed as is a granted act; the working fields are
  // everyone's. A new client is always typed in full — the lock is about
  // changing what an existing invoice was raised against.
  const canEditBilling = crmAllows(me, 'clients.edit_details')
  const billingLocked = !!editing && !canEditBilling
  const myName = masters?.members.find((m) => m.uuid === me?.member?.uuid)?.name ?? 'you'

  const { data: requests } = useQuery({
    queryKey: ['crm', 'client-requests'],
    queryFn: () => crm.clients.accessRequests({}),
  })
  const pendingRequests = requests?.data.filter((r) => r.status === 'pending').length ?? 0

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'clients'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'client-requests'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] })
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY })
    setCustomValues(Object.fromEntries(
      (masters?.client_custom_fields ?? []).map((f) => [f.key, f.type === 'checkbox' ? false : '']),
    ))
    setShareWith([])
    setError(null)
    setShowForm(true)
  }

  const openEdit = (c: CrmClient) => {
    setEditing(c)
    setForm({
      company_name: c.company_name,
      title: c.title ?? '',
      contact_person: c.contact_person ?? '',
      designation: c.designation ?? '',
      address: c.address ?? '',
      city: c.city ?? '',
      state: c.state ?? '',
      pincode: c.pincode ?? '',
      country: c.country ?? '',
      telephone: c.telephone ?? '',
      mobile: c.mobile ?? '',
      email: c.email ?? '',
      alternate_email: c.alternate_email ?? '',
      website: c.website ?? '',
      gst_no: c.gst_no ?? '',
      pan_no: c.pan_no ?? '',
      category: c.category ?? '',
      status: c.status,
      notes: c.notes ?? '',
      assigned_member_uuid: c.assigned_member?.uuid ?? '',
    })
    setCustomValues(Object.fromEntries(
      (masters?.client_custom_fields ?? []).map((f) => {
        const v = c.custom_fields?.[f.key]
        return [f.key, f.type === 'checkbox' ? !!v : v === undefined || v === null ? '' : String(v)]
      }),
    ))
    setShareWith(c.shared_with?.map((m) => m.uuid) ?? [])
    setError(null)
    setShowForm(true)
  }

  const payload = () => {
    const p: Record<string, unknown> = { ...form }
    for (const key of Object.keys(p)) {
      if (p[key] === '') p[key] = null
    }
    p.status = form.status
    p.company_name = form.company_name
    // An employee's client is their own; only a manager may pick an owner
    // or share it out.
    if (isManager) {
      p.share_with = shareWith
    } else {
      delete p.assigned_member_uuid
    }
    // Only send what this workspace actually has approved.
    const fields = masters?.client_custom_fields ?? []
    if (fields.length > 0) {
      p.custom_fields = Object.fromEntries(fields.map((f) => {
        const raw = customValues[f.key]
        if (f.type === 'checkbox') return [f.key, !!raw]
        if (raw === '' || raw === undefined) return [f.key, null]
        return [f.key, f.type === 'number' ? Number(raw) : raw]
      }))
    }
    return p
  }

  const saveMutation = useMutation({
    mutationFn: () => (editing ? crm.clients.update(editing.uuid, payload()) : crm.clients.create(payload())),
    onSuccess: (res: { message?: string }) => {
      refresh()
      setShowForm(false)
      toast(res.message ?? 'Saved.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const transferMutation = useMutation({
    mutationFn: () => crm.clients.transfer(transferring!.uuid, {
      to_member_uuid: transferTo,
      note: transferNote || undefined,
    }),
    onSuccess: (res: { message?: string }) => {
      refresh()
      setTransferring(null)
      toast(res.message ?? 'Transferred.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, status }: { uuid: string; status: 'approved' | 'rejected' }) =>
      crm.clients.decideAccessRequest(uuid, { status }),
    onSuccess: (res: { message?: string }) => {
      refresh()
      toast(res.message ?? 'Done.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.clients.remove(uuid),
    onSuccess: (res: { message?: string }) => {
      refresh()
      toast(res.message ?? 'Deleted.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const set = (key: keyof typeof EMPTY, value: string) => setForm((f) => ({ ...f, [key]: value }))
  /* Tidy the field the moment focus leaves it, matching the server's rules. */
  const tidy = (key: keyof typeof EMPTY, style: (v: string) => string) =>
    setForm((f) => ({ ...f, [key]: style(f[key] as string) }))

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Clients</h1>
          <p className="text-sm text-slate-500">{data ? `${data.total} client${data.total === 1 ? '' : 's'}` : 'The companies you bill.'}</p>
        </div>
        <Button onClick={openCreate}><Plus className="size-4" /> Add client</Button>
      </div>

      <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
        {([['clients', 'Client list'], ['requests', isManager ? 'Access requests' : 'My requests']] as const).map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={clsx(
              'flex items-center gap-2 rounded-lg px-3 py-1.5 font-medium transition',
              tab === key
                ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
            )}
          >
            {label}
            {key === 'requests' && pendingRequests > 0 && (
              <span className="rounded-full bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                {pendingRequests}
              </span>
            )}
          </button>
        ))}
      </div>

      {tab === 'clients' && (
      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Company, person, GST, city…" className="w-full pl-9" />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="">All</option>
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No clients found" hint="Add your first client to start invoicing." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Company</th>
                  <th className="py-2 pr-3 font-medium">Contact person</th>
                  <th className="py-2 pr-3 font-medium">Mobile</th>
                  <th className="py-2 pr-3 font-medium">Email</th>
                  <th className="py-2 pr-3 font-medium">GST no.</th>
                  <th className="py-2 pr-3 font-medium">Assigned to</th>
                  <th className="py-2 pr-3 font-medium">Shared with</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((c) => (
                  <tr key={c.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="max-w-[220px] py-2.5 pr-3">
                      <Link to={`/crm/clients/${c.uuid}`} className="block truncate font-medium text-emerald-600 hover:underline">{c.company_name}</Link>
                      <div className="truncate text-xs text-slate-400">
                        {[c.city, c.category ? CRM_CLIENT_CATEGORY_LABELS[c.category] : null].filter(Boolean).join(' · ')}
                        {c.is_repeat && (
                          <span
                            className="ml-1.5 rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"
                            title={`Came back ${c.repeat_count ?? 1}× after a closed lead`}
                          >
                            Repeat client
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3">{[c.title, c.contact_person].filter(Boolean).join(' ') || '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3">{c.mobile ?? '—'}</td>
                    <td className="max-w-[180px] truncate py-2.5 pr-3">{c.email ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3">{c.gst_no ?? '—'}</td>
                    <td className="py-2.5 pr-3">{c.assigned_member?.name ?? '—'}</td>
                    <td className="py-2.5 pr-3">
                      {(c.shared_with ?? []).length === 0 ? (
                        <span className="text-slate-300 dark:text-slate-600">—</span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                          <Users className="size-3" />
                          {c.shared_with.map((m) => m.name).filter(Boolean).join(', ')}
                        </span>
                      )}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        c.status === 'active'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                      )}>
                        {c.status === 'active' ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="py-2.5 text-right">
                      <button onClick={() => openEdit(c)} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Pencil className="size-4" />
                      </button>
                      {isManager && (
                        <button
                          onClick={() => { setTransferring(c); setTransferTo(''); setTransferNote('') }}
                          aria-label="Transfer"
                          title="Transfer to another employee"
                          className="rounded p-1.5 text-slate-400 hover:text-sky-600"
                        >
                          <ArrowLeftRight className="size-4" />
                        </button>
                      )}
                      {/* Deleting is the Admin's, or an employee they grant
                          it to — editing stays everyone's. */}
                      {canDelete && (
                        <button
                          onClick={() => { if (confirm(`Delete ${c.company_name}?`)) deleteMutation.mutate(c.uuid) }}
                          aria-label="Delete"
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
      )}

      {tab === 'requests' && (
        <Card>
          <p className="mb-3 text-sm text-slate-500">
            {isManager
              ? 'Someone tried to add a client the company already has. Approving shares that record with them.'
              : 'Clients you asked to be let in on. Your Company Admin or Subadmin decides.'}
          </p>
          {!requests || requests.data.length === 0 ? (
            <EmptyState title="Nothing waiting" hint="Access requests appear here." />
          ) : (
            <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Client</th>
                    <th className="py-2 pr-3 font-medium">Currently with</th>
                    <th className="py-2 pr-3 font-medium">Requested by</th>
                    <th className="py-2 pr-3 font-medium">Requested on</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {requests.data.map((r) => (
                    <tr key={r.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="py-2.5 pr-3 font-medium text-slate-700 dark:text-slate-200">{r.client?.company_name ?? '—'}</td>
                      <td className="py-2.5 pr-3">{r.owner ?? '—'}</td>
                      <td className="py-2.5 pr-3">{r.requested_by ?? '—'}</td>
                      <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{r.created_at ?? '—'}</td>
                      <td className="py-2.5 pr-3">
                        <span className={clsx(
                          'rounded-full px-2 py-0.5 text-[11px] font-medium',
                          r.status === 'pending' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                          r.status === 'approved' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                          r.status === 'rejected' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                        )}>
                          {r.status === 'pending' ? 'Pending' : r.status === 'approved' ? 'Approved' : 'Rejected'}
                        </span>
                        {r.decided_by && <div className="text-xs text-slate-400">by {r.decided_by} · {r.decided_at}</div>}
                      </td>
                      <td className="py-2.5 text-right">
                        {isManager && r.status === 'pending' && (
                          <div className="flex justify-end gap-2">
                            <Button
                              size="sm"
                              disabled={decideMutation.isPending}
                              onClick={() => decideMutation.mutate({ uuid: r.uuid, status: 'approved' })}
                            >
                              <Check className="size-4" /> Approve
                            </Button>
                            <Button
                              size="sm"
                              variant="secondary"
                              disabled={decideMutation.isPending}
                              onClick={() => decideMutation.mutate({ uuid: r.uuid, status: 'rejected' })}
                            >
                              <X className="size-4" /> Reject
                            </Button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {transferring && (
        <Modal title={`Transfer ${transferring.company_name}`} onClose={() => setTransferring(null)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Currently with <span className="font-medium text-slate-700 dark:text-slate-200">{transferring.assigned_member?.name ?? 'the Company Admin'}</span>.
              Invoices already raised stay credited to them — they keep seeing this client's details on those
              documents, but the client itself leaves their list.
            </p>
            <div>
              <Label>Transfer to</Label>
              <Select value={transferTo} onChange={(e) => setTransferTo(e.target.value)} className="w-full">
                <option value="">Select employee</option>
                {(masters?.members ?? [])
                  .filter((m) => m.uuid !== transferring.assigned_member?.uuid)
                  .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
              </Select>
            </div>
            <div>
              <Label>Note (optional)</Label>
              <Textarea rows={2} value={transferNote} onChange={(e) => setTransferNote(e.target.value)} className="w-full" placeholder="Why the client is moving…" />
            </div>
            <Button className="w-full" disabled={!transferTo || transferMutation.isPending} onClick={() => transferMutation.mutate()}>
              {transferMutation.isPending ? 'Transferring…' : 'Transfer client'}
            </Button>
          </div>
        </Modal>
      )}

      {showForm && (
        <Modal title={editing ? `Edit ${editing.company_name}` : 'Add client'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label>Company name</Label>
                <Input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} onBlur={() => tidy('company_name', companyCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Title</Label>
                <Select value={form.title} onChange={(e) => set('title', e.target.value)} className="w-full" disabled={billingLocked}>
                  <option value="">Select</option>
                  {TITLES.map((t) => <option key={t} value={t}>{t}</option>)}
                </Select>
              </div>
              <div>
                <Label>Contact person</Label>
                <Input value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} onBlur={() => tidy('contact_person', nameCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Mobile</Label>
                <Input value={form.mobile} onChange={(e) => set('mobile', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Telephone</Label>
                <Input value={form.telephone} onChange={(e) => set('telephone', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Email</Label>
                <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} onBlur={() => tidy('email', emailCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Alternate email</Label>
                <Input type="email" value={form.alternate_email} onChange={(e) => set('alternate_email', e.target.value)} onBlur={() => tidy('alternate_email', emailCase)} className="w-full" disabled={billingLocked} />
              </div>
              {billingLocked && (
                <p className="sm:col-span-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60">
                  The name, address, GST and contacts print on every proforma and invoice, so changing them
                  needs the “edit billing details” permission. Category, status and notes are yours to edit.
                </p>
              )}
              <div className="sm:col-span-2">
                <Label>Address</Label>
                <Input value={form.address} onChange={(e) => set('address', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>City</Label>
                <Input value={form.city} onChange={(e) => set('city', e.target.value)} onBlur={() => tidy('city', nameCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>State</Label>
                <Input value={form.state} onChange={(e) => set('state', e.target.value)} onBlur={() => tidy('state', nameCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>PIN code</Label>
                <Input value={form.pincode} onChange={(e) => set('pincode', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Country</Label>
                <Input value={form.country} onChange={(e) => set('country', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>GST number</Label>
                <Input value={form.gst_no} onChange={(e) => set('gst_no', e.target.value)} onBlur={() => tidy('gst_no', codeCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>PAN</Label>
                <Input value={form.pan_no} onChange={(e) => set('pan_no', e.target.value)} onBlur={() => tidy('pan_no', codeCase)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Website</Label>
                <Input value={form.website} onChange={(e) => set('website', e.target.value)} className="w-full" disabled={billingLocked} />
              </div>
              <div>
                <Label>Category</Label>
                <Select value={form.category} onChange={(e) => set('category', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.client_categories.map((c) => (
                    <option key={c} value={c}>{CRM_CLIENT_CATEGORY_LABELS[c] ?? c}</option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Assigned to</Label>
                {isManager ? (
                  <Select value={form.assigned_member_uuid} onChange={(e) => set('assigned_member_uuid', e.target.value)} className="w-full">
                    <option value="">Company Admin (kept in-house)</option>
                    {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
                  </Select>
                ) : (
                  /* An employee's client is their own — there is nobody else
                     to pick, so the field states the fact instead of asking. */
                  <div className="flex h-[38px] items-center rounded-xl bg-slate-100 px-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700">
                    {myName}
                  </div>
                )}
              </div>
              <div>
                <Label>Status</Label>
                <Select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </Select>
              </div>
              {isManager && (
                <div className="sm:col-span-2">
                  <Label>Share with</Label>
                  <div className="flex flex-wrap gap-2 rounded-xl bg-slate-50 p-2 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                    {(masters?.members ?? [])
                      .filter((m) => m.uuid !== form.assigned_member_uuid)
                      .map((m) => {
                        const on = shareWith.includes(m.uuid)
                        return (
                          <button
                            key={m.uuid}
                            type="button"
                            onClick={() => setShareWith((v) => (on ? v.filter((x) => x !== m.uuid) : [...v, m.uuid]))}
                            className={clsx(
                              'rounded-full px-3 py-1 text-xs font-medium transition',
                              on
                                ? 'bg-emerald-600 text-white'
                                : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:ring-emerald-400 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700',
                            )}
                          >
                            {m.name}
                          </button>
                        )
                      })}
                  </div>
                  <p className="mt-1 text-xs text-slate-400">
                    Pick nobody and the client stays with the Company Admin — no employee sees it in their portfolio.
                  </p>
                </div>
              )}
              <div className="sm:col-span-2">
                <Label>Notes</Label>
                <Textarea rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} className="w-full" />
              </div>

              {/* Dedicated Company Workspace: fields this company asked for
                  and the Super Admin approved. Nobody else's form has them. */}
              {(masters?.client_custom_fields.length ?? 0) > 0 && (
                <div className="sm:col-span-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                    {me?.organization?.name ?? 'Workspace'} fields
                  </p>
                  <div className="grid gap-3 sm:grid-cols-2">
                    {masters?.client_custom_fields.map((f) => (
                      <div key={f.key} className={f.type === 'textarea' ? 'sm:col-span-2' : undefined}>
                        {f.type === 'checkbox' ? (
                          <label className="flex items-center gap-2 pt-6 text-sm text-slate-600 dark:text-slate-300">
                            <input
                              type="checkbox"
                              checked={!!customValues[f.key]}
                              onChange={(e) => setCustomValues((v) => ({ ...v, [f.key]: e.target.checked }))}
                              className="size-4 accent-emerald-600"
                            />
                            {f.label}
                          </label>
                        ) : (
                          <>
                            <Label>{f.label}{f.is_required && ' *'}</Label>
                            {f.type === 'select' ? (
                              <Select
                                value={String(customValues[f.key] ?? '')}
                                onChange={(e) => setCustomValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                className="w-full"
                              >
                                <option value="">Select</option>
                                {(f.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
                              </Select>
                            ) : f.type === 'textarea' ? (
                              <Textarea
                                rows={2}
                                value={String(customValues[f.key] ?? '')}
                                onChange={(e) => setCustomValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                className="w-full"
                              />
                            ) : (
                              <Input
                                type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                                value={String(customValues[f.key] ?? '')}
                                onChange={(e) => setCustomValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                className="w-full"
                              />
                            )}
                          </>
                        )}
                        {f.help && <p className="mt-1 text-xs text-slate-400">{f.help}</p>}
                      </div>
                    ))}
                    {/* An odd number of fields would leave a ragged white gap
                        beside the last one. A greyed, inert slot squares the
                        row off so the section reads like the rest of the form
                        (GST no. / PAN sit two-up the same way). A textarea
                        already spans both columns, so it costs two slots. */}
                    {(masters?.client_custom_fields ?? [])
                      .reduce((slots, f) => slots + (f.type === 'textarea' ? 2 : 1), 0) % 2 === 1 && (
                      <div aria-hidden className="hidden sm:block">
                        <Label>&nbsp;</Label>
                        <div className="h-[38px] w-full rounded-xl bg-slate-100 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700" />
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
            <Button className="w-full" disabled={!form.company_name || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Add client'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
