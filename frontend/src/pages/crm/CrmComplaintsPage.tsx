import { useState } from 'react'
import { Link, useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ChevronDown, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import {
  crm, crmCan,
  type CrmComplaint, type CrmComplaintError, type CrmComplaintStatus, type CrmMe,
} from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner } from '../../components/ui'

const EMPTY = {
  complained_on: new Date().toISOString().slice(0, 10),
  client_uuid: '', company_name: '', contact_person: '', mobile: '', phone: '', email: '',
  alt_contact_person: '', alt_mobile: '', alt_phone: '', alt_email: '',
  invoice_uuid: '', source: '', subject: '', complaint_type: '', mode: '',
  details: '', priority: 'normal', due_at: '', allocated_to: '', key_responsible: '',
}

export function StatusPill({ status, overdue, label }: {
  status: CrmComplaintStatus
  overdue?: boolean
  label: string
}) {
  return (
    <span className={clsx(
      'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
      status === 'closed_satisfied'
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
        : status === 'closed_dissatisfied'
          ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'
          : overdue
            ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
            : status === 'in_progress'
              ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400'
              : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    )}>
      {overdue && !status.startsWith('closed') ? 'Overdue' : label}
    </span>
  )
}

export function ErrorPill({ type, label }: { type: CrmComplaintError; label: string }) {
  return (
    <span className={clsx(
      'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
      type === 'executive'
        ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
        : type === 'client'
          ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300'
          : type === 'backend'
            ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300'
            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    )}>
      {label}
    </span>
  )
}

export default function CrmComplaintsPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const canCreate = crmCan(me, 'complaints', 'create')
  const canDelete = crmCan(me, 'complaints', 'delete')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [advanced, setAdvanced] = useState(false)
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ ...EMPTY })
  const [error, setError] = useState<string | null>(null)

  const { data: options } = useQuery({ queryKey: ['crm', 'complaint-options'], queryFn: crm.complaints.options })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'complaints', applied, filters, page],
    queryFn: () => crm.complaints.list({ ...filters, search: applied || undefined, page }),
  })
  // Only the clients this member can already reach. The list is a page
  // long, so the box narrows it rather than pretending to hold everyone.
  const [clientSearch, setClientSearch] = useState('')
  const { data: clients } = useQuery({
    queryKey: ['crm', 'clients', 'complaint-picker', clientSearch],
    queryFn: () => crm.clients.list({ search: clientSearch || undefined }),
    enabled: showForm,
  })

  const setFilter = (key: string, value: string) => {
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
  }
  const set = (key: keyof typeof EMPTY, value: string) => setForm((f) => ({ ...f, [key]: value }))

  const createMutation = useMutation({
    mutationFn: () => crm.complaints.create({
      ...form,
      client_uuid: form.client_uuid || null,
      invoice_uuid: form.invoice_uuid || null,
      due_at: form.due_at || null,
      allocated_to: form.allocated_to || null,
      key_responsible: form.key_responsible || null,
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaints'] })
      setShowForm(false)
      setForm({ ...EMPTY })
      toast(res.message, 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.complaints.remove(uuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaints'] })
      toast('Complaint removed.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const hours = (v: number | null) => (v === null ? '—' : v < 24 ? `${v} h` : `${Math.round(v / 24 * 10) / 10} d`)

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Complaints</h1>
          <p className="text-sm text-slate-500">
            Client issues and the office&rsquo;s own working-out of them, in one record.
            {data && <> · {data.summary.count} shown{filters.status === 'open' ? ', open only' : ''}</>}
          </p>
        </div>
        {canCreate && <Button onClick={() => { setError(null); setShowForm(true) }}><Plus className="size-4" /> Log complaint</Button>}
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
          {[
            { label: 'Unattended', value: String(data.summary.unattended), tone: data.summary.unattended ? 'text-amber-600 dark:text-amber-400' : '' },
            { label: 'In progress', value: String(data.summary.in_progress) },
            { label: 'Overdue', value: String(data.summary.overdue), tone: data.summary.overdue ? 'text-red-500' : '' },
            { label: 'Closed satisfied', value: String(data.summary.closed_satisfied) },
            { label: 'First reply (avg)', value: hours(data.summary.avg_first_response_hours) },
            { label: 'Time to close (avg)', value: hours(data.summary.avg_resolution_hours) },
          ].map((c) => (
            <Card key={c.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
              <div className="text-xs text-slate-500">{c.label}</div>
            </Card>
          ))}
        </div>
      )}

      {data && data.summary.by_error_type.some((e) => e.count > 0) && (
        <Card className="py-3">
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            Whose error, on the complaints closed so far
          </h2>
          <div className="flex flex-wrap gap-2">
            {data.summary.by_error_type.filter((e) => e.count > 0).map((e) => (
              <button
                key={e.key}
                onClick={() => setFilter('final_error_type', filters.final_error_type === e.key ? '' : e.key)}
                className={clsx(
                  'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition',
                  filters.final_error_type === e.key
                    ? 'border-indigo-400 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-500/10'
                    : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
                )}
              >
                <ErrorPill type={e.key} label={e.label} />
                <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{e.count}</span>
              </button>
            ))}
          </div>
        </Card>
      )}

      <Card>
        <form
          className="mb-3 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[260px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="CMS no., company, subject, mobile…" className="w-full pl-9" />
          </div>
          <Select value={filters.status ?? ''} onChange={(e) => setFilter('status', e.target.value)}>
            <option value="">All complaints</option>
            <option value="open">Still open</option>
            <option value="overdue">Overdue</option>
            <option value="closed">Closed (however it ended)</option>
            {options && Object.entries(options.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </Select>
          <Select value={filters.subject ?? ''} onChange={(e) => setFilter('subject', e.target.value)}>
            <option value="">Any subject</option>
            {options?.subjects.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
          <Select value={filters.allocated_to ?? ''} onChange={(e) => setFilter('allocated_to', e.target.value)}>
            <option value="">Anyone&rsquo;s desk</option>
            {options?.members.map((m) => (
              <option key={m.uuid} value={m.uuid}>{m.name}{m.allocated ? ` (${m.allocated})` : ''}</option>
            ))}
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
          <button
            type="button"
            onClick={() => setAdvanced((v) => !v)}
            className="flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
          >
            <ChevronDown className={clsx('size-3.5 transition', advanced && 'rotate-180')} /> More filters
          </button>
        </form>

        {advanced && (
          <div className="mb-4 grid gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40 sm:grid-cols-3 lg:grid-cols-4">
            {([
              ['date_from', 'Complained from', 'date'],
              ['date_to', 'Complained to', 'date'],
              ['progress_from', 'In progress from', 'date'],
              ['progress_to', 'In progress to', 'date'],
              ['company', 'Company', 'text'],
              ['contact_person', 'Contact person', 'text'],
              ['mobile', 'Mobile', 'text'],
              ['phone', 'Phone', 'text'],
              ['email', 'Email', 'text'],
              ['alt_contact_person', 'Alternative contact', 'text'],
              ['alt_mobile', 'Alternative mobile', 'text'],
              ['alt_email', 'Alternative email', 'text'],
              ['alt_phone', 'Alternative phone', 'text'],
              ['cms_no', 'CMS ID', 'text'],
              ['invoice_no', 'Invoice no.', 'text'],
              // The log's own dates, so a settled complaint can be found
              // here by when it was settled and not only by when it arrived.
              ['closed_from', 'Closed from', 'date'],
              ['closed_to', 'Closed to', 'date'],
            ] as const).map(([key, label, type]) => (
              <div key={key}>
                <Label>{label}</Label>
                <Input type={type} value={filters[key] ?? ''} onChange={(e) => setFilter(key, e.target.value)} className="w-full" />
              </div>
            ))}
            {([
              ['user', 'Logged by', options?.members ?? []],
              ['allocated_by', 'Allocated by', options?.members ?? []],
              ['key_responsible', 'Key responsible person', options?.members ?? []],
              ['error_member', 'Error owner', options?.members ?? []],
            ] as const).map(([key, label, list]) => (
              <div key={key}>
                <Label>{label}</Label>
                <Select value={filters[key] ?? ''} onChange={(e) => setFilter(key, e.target.value)} className="w-full">
                  <option value="">Anyone</option>
                  {list.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
                </Select>
              </div>
            ))}
            {([
              ['source', 'Source', options?.sources ?? []],
              ['complaint_type', 'Type', options?.types ?? []],
              ['mode', 'Mode', options?.modes ?? []],
            ] as const).map(([key, label, list]) => (
              <div key={key}>
                <Label>{label}</Label>
                <Select value={filters[key] ?? ''} onChange={(e) => setFilter(key, e.target.value)} className="w-full">
                  <option value="">Any</option>
                  {list.map((v) => <option key={v} value={v}>{v}</option>)}
                </Select>
              </div>
            ))}
            <div>
              <Label>Final error type</Label>
              <Select value={filters.final_error_type ?? ''} onChange={(e) => setFilter('final_error_type', e.target.value)} className="w-full">
                <option value="">Any</option>
                {options && Object.entries(options.error_types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </Select>
            </div>
            <div className="flex items-end">
              <Button variant="secondary" size="sm" onClick={() => { setFilters({}); setSearch(''); setApplied(''); setPage(1) }}>
                Clear all
              </Button>
            </div>
          </div>
        )}

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No complaints here" hint="Nothing matches these filters — which on this screen is good news." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[1040px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">CMS ID</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  <th className="py-2 pr-3 font-medium">Subject</th>
                  <th className="py-2 pr-3 font-medium">On whose desk</th>
                  <th className="py-2 pr-3 font-medium">Due</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Final error</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((c) => (
                  <ComplaintRow key={c.uuid} complaint={c} canDelete={canDelete} onDelete={() => {
                    if (confirm(`Remove ${c.cms_no}? The conversation goes with it.`)) deleteMutation.mutate(c.uuid)
                  }} />
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <Modal title="Log a complaint" onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Complaint date</Label>
                <Input type="date" value={form.complained_on} onChange={(e) => set('complained_on', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Registered client</Label>
                <Input
                  value={clientSearch}
                  onChange={(e) => setClientSearch(e.target.value)}
                  placeholder="Find the client…"
                  className="mb-1.5 w-full"
                />
                <Select
                  value={form.client_uuid}
                  onChange={(e) => {
                    const picked = clients?.data.find((c) => c.uuid === e.target.value)
                    setForm((f) => ({
                      ...f,
                      client_uuid: e.target.value,
                      company_name: picked?.company_name ?? f.company_name,
                      contact_person: picked?.contact_person ?? f.contact_person,
                      mobile: picked?.mobile ?? f.mobile,
                      phone: picked?.telephone ?? f.phone,
                      email: picked?.email ?? f.email,
                    }))
                  }}
                  className="w-full"
                >
                  <option value="">Not a registered client</option>
                  {clients?.data.map((c) => <option key={c.uuid} value={c.uuid}>{c.company_name}</option>)}
                </Select>
                <p className="mt-1 text-xs text-slate-400">Picking one fills the contact block and links their history.</p>
              </div>
              <div className="sm:col-span-2">
                <Label>Company</Label>
                <Input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} className="w-full" />
              </div>

              {([
                ['contact_person', 'Contact person'], ['mobile', 'Mobile'],
                ['phone', 'Phone'], ['email', 'Email'],
                ['alt_contact_person', 'Alternative contact person'], ['alt_mobile', 'Alternative mobile'],
                ['alt_phone', 'Alternative phone'], ['alt_email', 'Alternative email'],
              ] as const).map(([key, label]) => (
                <div key={key}>
                  <Label>{label}</Label>
                  <Input value={form[key]} onChange={(e) => set(key, e.target.value)} className="w-full" />
                </div>
              ))}

              <div>
                <Label>Source</Label>
                <Select value={form.source} onChange={(e) => set('source', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {options?.sources.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div>
                <Label>Mode</Label>
                <Select value={form.mode} onChange={(e) => set('mode', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {options?.modes.map((m) => <option key={m} value={m}>{m}</option>)}
                </Select>
              </div>
              <div>
                <Label>Type</Label>
                <Select value={form.complaint_type} onChange={(e) => set('complaint_type', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {options?.types.map((t) => <option key={t} value={t}>{t}</option>)}
                </Select>
              </div>
              <div>
                <Label>Priority</Label>
                <Select value={form.priority} onChange={(e) => set('priority', e.target.value)} className="w-full">
                  {options && Object.entries(options.priorities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </Select>
              </div>

              {/* The subject is what the complaint IS — it belongs directly
                  above the description, and comes from the company's list. */}
              <div className="sm:col-span-2">
                <Label>Subject</Label>
                <Select value={form.subject} onChange={(e) => set('subject', e.target.value)} className="w-full">
                  <option value="">Select the subject</option>
                  {options?.subjects.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
                <p className="mt-1 text-xs text-slate-400">
                  Not on the list? Your Company Admin keeps it — ask them to add the wording, so the same problem is
                  always filed under the same name.
                </p>
              </div>
              <div className="sm:col-span-2">
                <Label>Description</Label>
                <textarea
                  value={form.details}
                  onChange={(e) => set('details', e.target.value)}
                  rows={4}
                  className="w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-400 dark:border-slate-700 dark:bg-slate-900"
                  placeholder="What the client actually said."
                />
              </div>

              <div>
                <Label>Answer by</Label>
                <Input type="datetime-local" value={form.due_at} onChange={(e) => set('due_at', e.target.value)} className="w-full" />
                <p className="mt-1 text-xs text-slate-400">
                  Blank uses the company&rsquo;s standing {options?.resolve_hours ?? 48} hours.
                </p>
              </div>
              <div>
                <Label>Key responsible person</Label>
                <Select value={form.key_responsible} onChange={(e) => set('key_responsible', e.target.value)} className="w-full">
                  <option value="">Nobody yet</option>
                  {options?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
                </Select>
              </div>
              {options?.can_allocate && (
                <div className="sm:col-span-2">
                  <Label>Put it on whose desk</Label>
                  <Select value={form.allocated_to} onChange={(e) => set('allocated_to', e.target.value)} className="w-full">
                    <option value="">Leave it unallocated</option>
                    {options.members.map((m) => (
                      <option key={m.uuid} value={m.uuid}>{m.name}{m.allocated ? ` — carrying ${m.allocated}` : ''}</option>
                    ))}
                  </Select>
                </div>
              )}
            </div>

            <Button
              className="w-full"
              disabled={!form.company_name || !form.subject || createMutation.isPending}
              onClick={() => { setError(null); createMutation.mutate() }}
            >
              {createMutation.isPending ? 'Logging…' : 'Log complaint'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

function ComplaintRow({ complaint: c, canDelete, onDelete }: {
  complaint: CrmComplaint
  canDelete: boolean
  onDelete: () => void
}) {
  return (
    <tr className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
      <td className="whitespace-nowrap py-2.5 pr-3">
        <Link to={`/crm/complaints/${c.uuid}`} className="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100">
          {c.cms_no}
        </Link>
        <div className="text-xs text-slate-400">{c.complained_on}</div>
      </td>
      <td className="max-w-[200px] py-2.5 pr-3">
        <div className="truncate font-medium text-slate-800 dark:text-slate-100">{c.company_name}</div>
        <div className="truncate text-xs text-slate-400">{c.contact_person ?? c.mobile ?? '—'}</div>
      </td>
      <td className="max-w-[220px] py-2.5 pr-3">
        <div className="truncate text-slate-700 dark:text-slate-200">{c.subject ?? '—'}</div>
        <div className="truncate text-xs text-slate-400">
          {[c.complaint_type, c.mode, c.replies_count ? `${c.replies_count} notes` : null].filter(Boolean).join(' · ')}
        </div>
      </td>
      <td className="py-2.5 pr-3">
        <div className="text-slate-700 dark:text-slate-200">{c.allocated_to ?? <span className="text-amber-600 dark:text-amber-400">Nobody</span>}</div>
        {c.key_responsible && <div className="text-xs text-slate-400">key: {c.key_responsible}</div>}
      </td>
      <td className="whitespace-nowrap py-2.5 pr-3">
        {c.due_at ? (
          <span className={clsx('text-xs', c.overdue ? 'font-medium text-red-500' : 'text-slate-500')}>
            {c.overdue && <AlertTriangle className="mr-1 inline size-3" />}
            {c.due_at.slice(0, 16)}
          </span>
        ) : <span className="text-slate-400">—</span>}
      </td>
      <td className="py-2.5 pr-3">
        <StatusPill status={c.status} overdue={c.overdue} label={c.status_label} />
      </td>
      <td className="py-2.5 pr-3">
        {c.final_error_type ? (
          <>
            <ErrorPill type={c.final_error_type} label={c.final_error_label ?? ''} />
            {c.final_error_member && <div className="mt-0.5 text-xs text-slate-400">{c.final_error_member}</div>}
          </>
        ) : <span className="text-slate-400">—</span>}
      </td>
      <td className="py-2.5 text-right">
        {canDelete && (
          <button onClick={onDelete} aria-label="Remove" className="rounded p-1.5 text-slate-400 hover:text-red-500">
            <Trash2 className="size-4" />
          </button>
        )}
      </td>
    </tr>
  )
}
