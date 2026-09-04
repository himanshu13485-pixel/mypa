import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlarmClock, Download, Plus, Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmAllows, CRM_LEAD_STATUS_LABELS, type CrmLead } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { PhoneLink } from '../../components/ContactLink'
import { companyCase, emailCase, nameCase } from './textCase'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export function leadStatusBadge(status: string, due: boolean) {
  return clsx(
    'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
    status === 'follow_up' && !due && 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    status === 'follow_up' && due && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
    status === 'unattended' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    status === 'closed' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    status === 'not_interested' && 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
    status === 'transferred' && 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
  )
}

const EMPTY_FORM = {
  company_name: '', contact_person: '', phone: '', mobile: '', email: '',
  amount: '', lead_status: 'unattended', follow_up_at: '', subject: '',
  requirement: '', lead_type: 'new', source: '', assigned_member_uuid: '',
}

export default function CrmLeadsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  /** The export is a stream the server builds; the button says so meanwhile. */
  const [exporting, setExporting] = useState(false)
  const [status, setStatus] = useState('')
  const [source, setSource] = useState('')
  const [assigned, setAssigned] = useState('')
  const [dueOnly, setDueOnly] = useState(false)
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmLead | null>(null)
  const [form, setForm] = useState({ ...EMPTY_FORM })
  const [error, setError] = useState<string | null>(null)
  // Lead Duplication: the existing lead the server pointed at, and whether
  // this member may act on it on the spot.
  const [duplicate, setDuplicate] = useState<{
    uuid?: string; lead_no: number; company_name?: string; owner?: string | null; can_decide: boolean
  } | null>(null)
  // Bulk transfer: the reshuffle a Team Head or Admin does. Selection lives
  // here so the toolbar and the rows agree on what is picked.
  const [picked, setPicked] = useState<string[]>([])
  const [bulkTo, setBulkTo] = useState('')
  const [tab, setTab] = useState<'leads' | 'requests'>(
    new URLSearchParams(window.location.search).get('tab') === 'requests' ? 'requests' : 'leads',
  )

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'leads', applied, status, source, assigned, dueOnly, page],
    queryFn: () =>
      crm.leads.list({
        search: applied || undefined,
        lead_status: status || undefined,
        source: source || undefined,
        assigned_to: assigned || undefined,
        due: dueOnly ? 1 : undefined,
        page,
      }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] })

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY_FORM })
    setError(null)
    setDuplicate(null)
    setShowForm(true)
  }

  const openEdit = (l: CrmLead) => {
    setEditing(l)
    setForm({
      company_name: l.company_name,
      contact_person: l.contact_person ?? '',
      phone: l.phone ?? '',
      mobile: l.mobile ?? '',
      email: l.email ?? '',
      amount: Number(l.amount) ? String(l.amount) : '',
      lead_status: l.lead_status,
      follow_up_at: l.follow_up_at ? l.follow_up_at.slice(0, 16).replace(' ', 'T') : '',
      subject: l.subject ?? '',
      requirement: l.requirement ?? '',
      lead_type: l.lead_type,
      source: l.source ?? '',
      assigned_member_uuid: l.assigned_member?.uuid ?? '',
    })
    setError(null)
    setShowForm(true)
  }

  const set = (key: keyof typeof EMPTY_FORM, value: string) => setForm((f) => ({ ...f, [key]: value }))
  /* Tidy on blur, matching the server's house style. */
  const tidy = (key: keyof typeof EMPTY_FORM, style: (v: string) => string) =>
    setForm((f) => ({ ...f, [key]: style(String(f[key] ?? '')) }))

  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  const isManager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  // A Team Head reshuffles their own desk; anyone else needs the grant.
  const canBulkTransfer = crmAllows(me, 'leads.bulk_transfer') || !!me?.member?.leads_a_team
  const canBulkShare = crmAllows(me, 'leads.share') || !!me?.member?.leads_a_team
  // Who this member may hand leads to: a manager, anyone; anyone else, their
  // own team. The server holds the same line.
  const teamUuids = me?.member?.team_member_uuids ?? null
  const transferTargets = (masters?.members ?? []).filter((m) => teamUuids === null || teamUuids.includes(m.uuid))

  const bulkShareMutation = useMutation({
    mutationFn: () => crm.leads.bulkShare({ lead_uuids: picked, member_uuids: [bulkTo] }),
    onSuccess: (res) => {
      toast(res.message, 'success')
      setPicked([])
      setBulkTo('')
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const bulkMutation = useMutation({
    mutationFn: () => crm.leads.bulkTransfer({ lead_uuids: picked, to_member_uuid: bulkTo }),
    onSuccess: (res) => {
      toast(res.message, 'success')
      setPicked([])
      setBulkTo('')
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const { data: requests } = useQuery({
    queryKey: ['crm', 'lead-requests'],
    queryFn: () => crm.leads.accessRequests({}),
  })
  const pendingRequests = requests?.data.filter((r) => r.status === 'pending').length ?? 0

  // The Admin's gavel on a legacy duplicate row: settled, it opens again.
  const settleDuplicate = useMutation({
    mutationFn: (uuid: string) => crm.leads.settleDuplicate(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: 'share' | 'transfer' | 'reject' }) =>
      crm.leads.decideAccessRequest(uuid, { action }),
    onSuccess: (res) => {
      toast(res.message, 'success')
      refresh()
      queryClient.invalidateQueries({ queryKey: ['crm', 'lead-requests'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  // The Admin acting on a duplication found while allocating: the target is
  // whoever the form was allocating to.
  const resolveMutation = useMutation({
    mutationFn: ({ action }: { action: 'transfer' | 'share' }) => {
      const target = form.assigned_member_uuid
      return action === 'transfer'
        ? crm.leads.transfer(duplicate!.uuid!, { to_member_uuid: target })
        : crm.leads.share(duplicate!.uuid!, [target])
    },
    onSuccess: (res) => {
      toast(res.message, 'success')
      setDuplicate(null)
      setShowForm(false)
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        amount: form.amount ? Number(form.amount) : 0,
        follow_up_at: form.follow_up_at ? form.follow_up_at.replace('T', ' ') : null,
        contact_person: form.contact_person || null,
        phone: form.phone || null,
        mobile: form.mobile || null,
        email: form.email || null,
        subject: form.subject || null,
        requirement: form.requirement || null,
        source: form.source || null,
        assigned_member_uuid: form.assigned_member_uuid || null,
      }
      return editing ? crm.leads.update(editing.uuid, payload) : crm.leads.create(payload)
    },
    onSuccess: (res: { message?: string }) => {
      refresh()
      setShowForm(false)
      toast(res.message ?? 'Saved.', 'success')
    },
    onError: (err) => {
      // Lead Duplication comes back with the existing lead attached, so the
      // office can act on the original instead of reading a bare refusal.
      const dup = (err as { response?: { data?: { duplicate?: { lead_no: number }; can_decide?: boolean; message?: string } } })
        ?.response?.data
      if (dup?.duplicate) {
        setDuplicate({ ...dup.duplicate, can_decide: !!dup.can_decide })
        setError(dup.message ?? errorMessage(err))
      } else {
        setError(errorMessage(err))
      }
    },
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Lead generation</h1>
          <p className="text-sm text-slate-500">
            {data ? <>{data.totals.count} leads · {inr(data.totals.amount)} pipeline</> : 'Your sales pipeline.'}
          </p>
        </div>
        <Button onClick={openCreate}><Plus className="size-4" /> New lead</Button>
      </div>

      <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
        {([['leads', 'Lead list'], ['requests', isManager ? 'Duplication requests' : 'My requests']] as const).map(([key, label]) => (
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

      {tab === 'requests' && (
        <Card>
          <p className="mb-3 text-sm text-slate-500">
            {isManager
              ? 'Someone tried to add a lead whose mobile, phone or e-mail already exists. Share the original, transfer it to them, or reject.'
              : 'Leads you tried to add that already exist. Your Admin decides: share, transfer, or reject.'}
          </p>
          {!requests || requests.data.length === 0 ? (
            <EmptyState title="Nothing waiting" hint="Lead Duplication requests appear here." />
          ) : (
            <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[760px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Lead</th>
                    <th className="py-2 pr-3 font-medium">Contacts</th>
                    <th className="py-2 pr-3 font-medium">Currently with</th>
                    <th className="py-2 pr-3 font-medium">Requested by</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {requests.data.map((r) => (
                    <tr key={r.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="py-2.5 pr-3">
                        {r.lead ? (
                          <Link to={`/crm/leads/${r.lead.uuid}`} className="font-medium text-emerald-600 hover:underline">
                            #{r.lead.lead_no} {r.lead.company_name}
                          </Link>
                        ) : '—'}
                      </td>
                      <td className="max-w-[180px] truncate py-2.5 pr-3 text-xs text-slate-500">
                        {[r.lead?.mobile, r.lead?.email].filter(Boolean).join(' · ') || '—'}
                      </td>
                      <td className="py-2.5 pr-3">{r.owner ?? '—'}</td>
                      <td className="py-2.5 pr-3">{r.requested_by ?? '—'}</td>
                      <td className="py-2.5 pr-3">
                        <span className={clsx(
                          'rounded-full px-2 py-0.5 text-[11px] font-medium capitalize',
                          r.status === 'pending' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                          ['shared', 'transferred'].includes(r.status) && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                          r.status === 'rejected' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                        )}>
                          {r.status}
                        </span>
                        {r.decided_by && <div className="text-xs text-slate-400">by {r.decided_by} · {r.decided_at}</div>}
                      </td>
                      <td className="py-2.5 text-right">
                        {isManager && r.status === 'pending' && (
                          <div className="flex justify-end gap-1.5">
                            <Button size="sm" disabled={decideMutation.isPending}
                              onClick={() => decideMutation.mutate({ uuid: r.uuid, action: 'share' })}>
                              Share
                            </Button>
                            <Button size="sm" variant="secondary" disabled={decideMutation.isPending}
                              onClick={() => decideMutation.mutate({ uuid: r.uuid, action: 'transfer' })}>
                              Transfer
                            </Button>
                            <Button size="sm" variant="secondary" disabled={decideMutation.isPending}
                              onClick={() => decideMutation.mutate({ uuid: r.uuid, action: 'reject' })}>
                              Reject
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

      {tab === 'leads' && (
      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[240px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Company, person, mobile, email…" className="w-full pl-9" />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {Object.entries(CRM_LEAD_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <Select value={source} onChange={(e) => { setSource(e.target.value); setPage(1) }}>
            <option value="">All sources</option>
            {masters?.lead_sources.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
          <Select value={assigned} onChange={(e) => { setAssigned(e.target.value); setPage(1) }}>
            <option value="">Everyone</option>
            {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <button
            type="button"
            onClick={() => { setDueOnly((d) => !d); setPage(1) }}
            className={clsx(
              'flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium ring-1 ring-inset transition-colors',
              dueOnly
                ? 'bg-red-50 text-red-600 ring-red-200 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/30'
                : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
            )}
          >
            <AlarmClock className="size-4" /> Due today
          </button>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
          {/*
            * The whole pipeline as one file — the Company Admin's alone.
            *
            * Not the exports.excel grant the invoice screens use: that one is
            * about the accounting book, and this is every lead with a name, a
            * mobile and an address on it. The filters above ride along, so
            * "download what I am looking at" works as well as "download
            * everything".
            */}
          {me?.member?.crm_role === 'admin' && (
            <Button
              type="button"
              variant="secondary"
              size="sm"
              disabled={exporting}
              onClick={async () => {
                setExporting(true)
                try {
                  const blob = await crm.exports.leadsCsv({
                    search: applied || undefined,
                    status: status || undefined,
                    source: source || undefined,
                    member: assigned || undefined,
                  })
                  const url = URL.createObjectURL(blob)
                  const a = document.createElement('a')
                  a.href = url
                  a.download = `leads-export.csv`
                  a.click()
                  URL.revokeObjectURL(url)
                } catch (err) {
                  toastError(errorMessage(err))
                } finally {
                  setExporting(false)
                }
              }}
            >
              <Download className="size-4" /> {exporting ? 'Preparing…' : 'Excel'}
            </Button>
          )}
        </form>

        {canBulkTransfer && picked.length > 0 && (
          <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30">
            <span className="text-sm font-medium text-emerald-800 dark:text-emerald-200">
              {picked.length} lead{picked.length === 1 ? '' : 's'} picked
            </span>
            {/* A Team Head moves work inside their own team — never upward
                to the Admin, nor across to another team. */}
            <Select value={bulkTo} onChange={(e) => setBulkTo(e.target.value)} className="min-w-[10rem]">
              <option value="">Transfer to…</option>
              {transferTargets.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
            {/* Transfer hands the leads over; share lets someone in on them
                while the owner keeps them. */}
            <Button size="sm" disabled={!bulkTo || bulkMutation.isPending} onClick={() => bulkMutation.mutate()}>
              {bulkMutation.isPending ? 'Moving…' : 'Transfer'}
            </Button>
            {canBulkShare && (
              <Button
                size="sm"
                variant="secondary"
                disabled={!bulkTo || bulkShareMutation.isPending}
                onClick={() => bulkShareMutation.mutate()}
              >
                {bulkShareMutation.isPending ? 'Sharing…' : 'Share'}
              </Button>
            )}
            <button onClick={() => setPicked([])} className="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
              Clear
            </button>
          </div>
        )}

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No leads found" hint="Create a lead or loosen the filters." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  {canBulkTransfer && (
                    <th className="py-2 pr-2 font-medium">
                      <input
                        type="checkbox"
                        aria-label="Pick every lead on this page"
                        checked={(data?.data.length ?? 0) > 0 && picked.length === data?.data.length}
                        onChange={(e) => setPicked(e.target.checked ? (data?.data ?? []).map((l) => l.uuid) : [])}
                        className="size-4 accent-emerald-600"
                      />
                    </th>
                  )}
                  <th className="py-2 pr-3 font-medium">Lead</th>
                  <th className="py-2 pr-3 font-medium">Company</th>
                  {/* Two ways to reach them, in one column. A column each
                      would push the table wider on the screen it is already
                      widest on, and an address is not read at a glance the
                      way a number is — it is copied. */}
                  <th className="py-2 pr-3 font-medium">Contact</th>
                  <th className="py-2 pr-3 font-medium">Allocated to</th>
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 text-right font-medium">Amount</th>
                  <th className="py-2 pr-3 font-medium">Follow up</th>
                  <th className="py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((l) => (
                  <tr key={l.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    {canBulkTransfer && (
                      <td className="py-2.5 pr-2">
                        <input
                          type="checkbox"
                          aria-label={`Pick lead ${l.lead_no}`}
                          checked={picked.includes(l.uuid)}
                          onChange={(e) => setPicked((prev) => (e.target.checked
                            ? [...prev, l.uuid]
                            : prev.filter((u) => u !== l.uuid)))}
                          className="size-4 accent-emerald-600"
                        />
                      </td>
                    )}
                    <td className="py-2.5 pr-3">
                      <Link to={`/crm/leads/${l.uuid}`} className="font-medium text-emerald-600 hover:underline">#{l.lead_no}</Link>
                      <div className="text-[11px] uppercase text-slate-400">
                        {l.lead_type}
                        {l.is_urgent && (
                          <span className="ml-1 rounded-full bg-red-500 px-1.5 text-[10px] font-semibold normal-case text-white">
                            URGENT
                          </span>
                        )}
                        {/* A person who came back — the office should know. */}
                        {(l.reopen_count ?? 0) > 0 && (
                          <span className="ml-1 rounded-full bg-violet-100 px-1.5 text-[10px] font-medium normal-case text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">
                            Reopened {l.reopen_count}×
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="max-w-[220px] py-2.5 pr-3">
                      <button onClick={() => openEdit(l)} className="block max-w-full truncate text-left font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100" title="Edit lead">
                        {l.company_name}
                      </button>
                      <div className="truncate text-xs text-slate-400">{l.contact_person}</div>
                    </td>
                    <td className="max-w-[200px] py-2.5 pr-3">
                      {/* The row opens the lead; the number rings it. Both
                          on one line, so PhoneLink stops its own click from
                          also navigating. */}
                      <div className="whitespace-nowrap">
                        {(l.mobile ?? l.phone)
                          ? <PhoneLink value={l.mobile ?? l.phone} label={l.company_name} icon />
                          : '—'}
                      </div>
                      {/* title, because an address that has been truncated is
                          an address nobody can read off the screen. */}
                      {l.email && (
                        <a
                          href={`mailto:${l.email}`}
                          title={l.email}
                          className="block truncate text-xs text-slate-400 hover:text-emerald-600"
                        >
                          {l.email}
                        </a>
                      )}
                    </td>
                    <td className="py-2.5 pr-3">{l.assigned_member?.name ?? '—'}</td>
                    <td className="py-2.5 pr-3">{l.source ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{Number(l.amount) ? inr(l.amount) : '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      <span className={clsx(l.follow_up_due && 'font-medium text-red-500')}>
                        {l.follow_up_at ? l.follow_up_at.slice(0, 16) : '—'}
                      </span>
                    </td>
                    <td className="py-2.5">
                      {/* Duplication outranks the working status: a pair
                          sharing a number must not hide behind "Unattended". */}
                      {l.is_duplicate || l.has_pending_request ? (
                        <span
                          className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-500/15 dark:text-red-400"
                          title={`${CRM_LEAD_STATUS_LABELS[l.lead_status] ?? l.lead_status} — ${
                            l.has_pending_request
                              ? 'a duplication request is waiting'
                              : `same mobile, phone or e-mail as lead #${l.duplicate_of ?? '?'}`}`}
                        >
                          Duplicate{l.duplicate_of ? ` of #${l.duplicate_of}` : ''}
                        </span>
                      ) : null}
                      {(l.is_duplicate || l.has_pending_request) ? (
                        isManager ? (
                          <button
                            onClick={() => settleDuplicate.mutate(l.uuid)}
                            disabled={settleDuplicate.isPending}
                            className="ml-1 rounded-full border border-emerald-300 px-2 py-0.5 text-[11px] font-medium text-emerald-600 hover:bg-emerald-50 dark:border-emerald-500/40 dark:hover:bg-emerald-500/10"
                            title="Mark this duplicate sorted - it opens normally again"
                          >
                            Settle
                          </button>
                        ) : (
                          <span className="ml-1 text-[10px] text-slate-400" title="A duplicate stays sealed until the Admin settles it">
                            locked until settled
                          </span>
                        )
                      ) : (
                        <span className={leadStatusBadge(l.lead_status, l.follow_up_due)}>
                          {CRM_LEAD_STATUS_LABELS[l.lead_status] ?? l.lead_status}
                        </span>
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

      {showForm && (
        <Modal title={editing ? `Edit lead #${editing.lead_no}` : 'New lead'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label>Company name</Label>
                <Input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} onBlur={() => tidy('company_name', companyCase)} className="w-full" />
              </div>
              <div>
                <Label>Contact person</Label>
                <Input value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} onBlur={() => tidy('contact_person', nameCase)} className="w-full" />
              </div>
              <div>
                <Label>Mobile</Label>
                <Input value={form.mobile} onChange={(e) => set('mobile', e.target.value)} className="w-full"
                  disabled={!!editing && !isManager} title={!!editing && !isManager ? 'Contact changes need your Admin' : undefined} />
              </div>
              <div>
                <Label>Phone</Label>
                <Input value={form.phone} onChange={(e) => set('phone', e.target.value)} className="w-full"
                  disabled={!!editing && !isManager} title={!!editing && !isManager ? 'Contact changes need your Admin' : undefined} />
              </div>
              <div>
                <Label>Email</Label>
                <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} onBlur={() => tidy('email', emailCase)} className="w-full"
                  disabled={!!editing && !isManager} title={!!editing && !isManager ? 'Contact changes need your Admin' : undefined} />
                {!!editing && !isManager && (
                  <p className="mt-1 text-xs text-slate-400">Mobile, phone and e-mail identify the lead — ask your Admin to change them.</p>
                )}
              </div>
              <div>
                <Label>Allocated to</Label>
                <Select value={form.assigned_member_uuid} onChange={(e) => set('assigned_member_uuid', e.target.value)} className="w-full">
                  <option value="">Nobody yet</option>
                  {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
                </Select>
              </div>
              <div>
                <Label>Expected amount (₹)</Label>
                <Input type="number" min="0" value={form.amount} onChange={(e) => set('amount', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Lead status</Label>
                <Select value={form.lead_status} onChange={(e) => set('lead_status', e.target.value)} className="w-full">
                  {Object.entries(CRM_LEAD_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </div>
              <div>
                <Label>Follow-up date & time</Label>
                <Input type="datetime-local" value={form.follow_up_at} onChange={(e) => set('follow_up_at', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Lead subject</Label>
                <Select value={form.subject} onChange={(e) => set('subject', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.lead_subjects.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div>
                <Label>Lead type</Label>
                <Select value={form.lead_type} onChange={(e) => set('lead_type', e.target.value)} className="w-full">
                  <option value="new">New</option>
                  <option value="existing">Existing</option>
                </Select>
              </div>
              <div>
                <Label>Lead source</Label>
                <Select value={form.source} onChange={(e) => set('source', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.lead_sources.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Label>Requirement</Label>
                <Textarea rows={2} value={form.requirement} onChange={(e) => set('requirement', e.target.value)} className="w-full" />
              </div>
            </div>
            {/* Lead Duplication: the original, and the ways forward. */}
            {duplicate && (
              <div className="rounded-xl bg-amber-50 p-3 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                <p className="text-sm font-medium text-amber-800 dark:text-amber-200">
                  Lead Duplication — this person is already lead #{duplicate.lead_no}
                  {duplicate.owner ? `, with ${duplicate.owner}` : ''}.
                </p>
                {duplicate.can_decide && duplicate.uuid ? (
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Link
                      to={`/crm/leads/${duplicate.uuid}`}
                      className="text-sm font-medium text-emerald-600 hover:underline"
                    >
                      Open lead #{duplicate.lead_no}
                    </Link>
                    {form.assigned_member_uuid && (
                      <>
                        <Button size="sm" disabled={resolveMutation.isPending}
                          onClick={() => resolveMutation.mutate({ action: 'transfer' })}>
                          Transfer it to {masters?.members.find((m) => m.uuid === form.assigned_member_uuid)?.name ?? 'them'}
                        </Button>
                        <Button size="sm" variant="secondary" disabled={resolveMutation.isPending}
                          onClick={() => resolveMutation.mutate({ action: 'share' })}>
                          Share it with them
                        </Button>
                      </>
                    )}
                    {!form.assigned_member_uuid && (
                      <span className="text-xs text-amber-700 dark:text-amber-300">
                        Pick “Assigned to” above to transfer or share it in one click.
                      </span>
                    )}
                  </div>
                ) : (
                  <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    Your Admin has been asked whether to share it with you, transfer it, or not.
                  </p>
                )}
              </div>
            )}
            <Button className="w-full" disabled={!form.company_name || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Create lead'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
