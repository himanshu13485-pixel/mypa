import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRightLeft, Flag, PhoneCall, Send, Trash2, Users, RotateCcw } from 'lucide-react'
import { crm, crmAllows, crmCan, CRM_LEAD_STATUS_LABELS, type CrmLeadLogEntry } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, Input, Label, Modal, Select, Spinner, Textarea } from '../../components/ui'
import { DialOnPhoneButton, EmailLink, PhoneLink } from '../../components/ContactLink'
import { leadStatusBadge } from './CrmLeadsPage'

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="shrink-0 text-slate-400">{label}</span>
      <span className="text-right font-medium text-slate-700 dark:text-slate-200">{value}</span>
    </div>
  )
}

/** One trail entry, rendered by what it records. */
export function LogEntry({ log }: { log: CrmLeadLogEntry }) {
  const label = {
    'lead.created': 'Lead created',
    'lead.updated': 'Details updated',
    'lead.followup': 'Follow-up',
    'lead.converted': 'Converted to client',
    'lead.deleted': 'Lead deleted',
  }[log.action] ?? log.action

  return (
    <div className="relative pl-5">
      <span className="absolute left-0 top-1.5 size-2 rounded-full bg-emerald-400" />
      <div className="flex flex-wrap items-baseline gap-x-2">
        <span className="text-sm font-medium text-slate-800 dark:text-slate-100">{label}</span>
        <span className="text-xs text-slate-400">{log.by ?? 'System'} · {log.at}</span>
      </div>
      {log.note && <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{log.note}</p>}
      {(log.status || log.next_follow_up) && (
        <p className="mt-0.5 text-xs text-slate-500">
          {log.status && <>Status → {CRM_LEAD_STATUS_LABELS[log.status] ?? log.status}. </>}
          {log.next_follow_up && <>Next follow-up {log.next_follow_up}.</>}
        </p>
      )}
      {log.client && <p className="mt-0.5 text-xs text-slate-500">Client: {log.client}</p>}
      {log.fields && (
        <p className="mt-0.5 text-xs text-slate-500">
          {Object.entries(log.fields).map(([key, value]) => {
            const v = value as { from?: unknown; to?: unknown } | unknown
            const isDiff = v !== null && typeof v === 'object' && 'to' in (v as object)
            return (
              <span key={key} className="mr-3 inline-block">
                {key.replace(/_/g, ' ')}: {isDiff
                  ? `${String((v as { from?: unknown }).from ?? '—')} → ${String((v as { to?: unknown }).to ?? '—')}`
                  : String(value)}
              </span>
            )
          })}
        </p>
      )}
    </div>
  )
}

export default function CrmLeadDetailPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [note, setNote] = useState('')
  const [status, setStatus] = useState('')
  const [nextAt, setNextAt] = useState('')

  const { data: lead, isLoading } = useQuery({
    queryKey: ['crm', 'lead', uuid],
    queryFn: () => crm.leads.get(uuid!),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'lead', uuid] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] })
  }

  const followUpMutation = useMutation({
    mutationFn: () =>
      crm.leads.followUp(uuid!, {
        note,
        lead_status: status || null,
        follow_up_at: nextAt ? nextAt.replace('T', ' ') : null,
      }),
    onSuccess: () => {
      refresh()
      setNote('')
      setStatus('')
      setNextAt('')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const urgentMutation = useMutation({
    mutationFn: (urgent: boolean) => crm.leads.setUrgent(uuid!, urgent),
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['crm', 'lead', uuid] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const convertMutation = useMutation({
    mutationFn: () => crm.leads.convert(uuid!),
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      navigate(`/crm/clients/${res.data.client_uuid}`)
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  const isManager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const canReopen = crmAllows(me, 'leads.reopen')
  const teamUuids = me?.member?.team_member_uuids ?? null
  const canEditPipeline = crmCan(me, 'leads', 'edit')
  const isOwner = !!lead && lead.assigned_member?.uuid === me?.member?.uuid
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const [moving, setMoving] = useState(false)
  const [moveTo, setMoveTo] = useState('')
  const [moveNote, setMoveNote] = useState('')
  const [shareWith, setShareWith] = useState<string[]>([])
  const [sharing, setSharing] = useState(false)
  // The Admin's quick edit: the pipeline words and the figure.
  const [quickEdit, setQuickEdit] = useState(false)
  const [reopening, setReopening] = useState(false)
  const [reopenNote, setReopenNote] = useState('')
  const [reopenDate, setReopenDate] = useState('')

  const reopenMutation = useMutation({
    mutationFn: () => crm.leads.reopen(uuid!, {
      note: reopenNote.trim(),
      follow_up_at: reopenDate ? reopenDate.replace('T', ' ') : null,
    }),
    onSuccess: (res: { message?: string }) => {
      toast(res.message ?? 'Reopened.', 'success')
      setReopening(false)
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })
  const [quick, setQuick] = useState({ source: '', subject: '', lead_type: 'new', amount: '' })

  const quickMutation = useMutation({
    mutationFn: () => crm.leads.update(uuid!, {
      company_name: lead!.company_name,
      source: quick.source || null,
      subject: quick.subject || null,
      lead_type: quick.lead_type,
      amount: quick.amount ? Number(quick.amount) : 0,
    }),
    onSuccess: (res: { message?: string }) => {
      toast(res.message ?? 'Saved.', 'success')
      setQuickEdit(false)
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const transferMutation = useMutation({
    mutationFn: () => crm.leads.transfer(uuid!, { to_member_uuid: moveTo, note: moveNote || undefined }),
    onSuccess: (res) => { toast(res.message, 'success'); setMoving(false); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const shareMutation = useMutation({
    mutationFn: () => crm.leads.share(uuid!, shareWith),
    onSuccess: (res) => { toast(res.message, 'success'); setSharing(false); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => crm.leads.remove(uuid!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] })
      navigate('/crm/leads')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading || !lead) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate('/crm/leads')} aria-label="Back" className="rounded p-1.5 text-slate-400 hover:bg-slate-200/60 dark:hover:bg-slate-800">
            <ArrowLeft className="size-4" />
          </button>
          <div>
            <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
              Lead #{lead.lead_no}
              <span className={leadStatusBadge(lead.lead_status, lead.follow_up_due)}>
                {CRM_LEAD_STATUS_LABELS[lead.lead_status] ?? lead.lead_status}
              </span>
              {lead.is_urgent && (
                <span className="rounded-full bg-red-500 px-2 py-0.5 text-[11px] font-semibold text-white">URGENT</span>
              )}
            </h1>
            <p className="text-sm text-slate-500">{lead.company_name}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {/* Urgency rides above every scheduled lead — in the list and in
              the follow-up popup alike. Anyone on the lead may flip it. */}
          <Button
            variant="secondary"
            onClick={() => urgentMutation.mutate(!lead.is_urgent)}
            disabled={urgentMutation.isPending}
          >
            <Flag className={lead.is_urgent ? 'size-4 text-red-500' : 'size-4'} />
            {lead.is_urgent ? 'Clear urgent' : 'Mark urgent'}
          </Button>
          {lead.client ? (
            <Link to={`/crm/clients/${lead.client.uuid}`} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30">
              Client: {lead.client.company_name}
            </Link>
          ) : (
            <Button onClick={() => { if (confirm(`Convert lead #${lead.lead_no} into a client?`)) convertMutation.mutate() }} disabled={convertMutation.isPending}>
              <ArrowRightLeft className="size-4" /> Convert to client
            </Button>
          )}
          {/* A closed lead whose person came back. The owner picks it up;
              anyone else needs the grant. */}
          {['closed', 'not_interested'].includes(lead.lead_status) && (canReopen || isOwner) && (
            <Button variant="secondary" onClick={() => { setReopenNote(''); setReopening(true) }}>
              <RotateCcw className="size-4" /> Reopen
            </Button>
          )}
          {isManager && (
            <>
              <Button variant="secondary" onClick={() => { setMoveTo(''); setMoveNote(''); setMoving(true) }}>
                <ArrowRightLeft className="size-4" /> Transfer
              </Button>
              <Button variant="secondary" onClick={() => { setShareWith(lead.shared_with?.map((m) => m.uuid) ?? []); setSharing(true) }}>
                <Users className="size-4" /> Share
              </Button>
            </>
          )}
          <Button variant="danger" onClick={() => { if (confirm(`Delete lead #${lead.lead_no}? The log keeps its record.`)) deleteMutation.mutate() }}>
            <Trash2 className="size-4" /> Delete
          </Button>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Contact</h2>
          <Row label="Company" value={lead.company_name} />
          <Row label="Person" value={lead.contact_person} />
          {/* Tap to ring, rather than reading the number off the screen and
              typing it into the dialler — which is what a sales team on a
              phone was doing every time. */}
          {/* The number rings on a phone; the button beside it rings on the
              laptop user's own phone, since a tel: link there mostly does
              nothing at all. */}
          <Row label="Mobile" value={<span className="inline-flex items-center gap-1.5">
            <PhoneLink value={lead.mobile} />
            <DialOnPhoneButton value={lead.mobile} label={lead.company_name} />
          </span>} />
          <Row label="Phone" value={<span className="inline-flex items-center gap-1.5">
            <PhoneLink value={lead.phone} />
            <DialOnPhoneButton value={lead.phone} label={lead.company_name} />
          </span>} />
          <Row label="Email" value={<EmailLink value={lead.email} />} />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Pipeline</h2>
          <Row label="Allocated to" value={lead.assigned_member?.name} />
          <Row label="Shared with" value={(lead.shared_with ?? []).map((m) => m.name).filter(Boolean).join(', ') || undefined} />
          <Row label="Created by" value={lead.created_by} />
          <Row label="Source" value={lead.source} />
          <Row label="Subject" value={lead.subject} />
          <Row label="Type" value={lead.lead_type === 'new' ? 'New' : 'Existing'} />
          <Row label="Expected amount" value={Number(lead.amount) ? '₹' + Number(lead.amount).toLocaleString('en-IN') : undefined} />
          {(isManager || canEditPipeline) && (
            <button
              onClick={() => {
                setQuick({
                  source: lead.source ?? '',
                  subject: lead.subject ?? '',
                  lead_type: lead.lead_type,
                  amount: Number(lead.amount) ? String(lead.amount) : '',
                })
                setQuickEdit(true)
              }}
              className="mt-2 text-xs font-medium text-emerald-600 hover:underline"
            >
              {isManager ? 'Edit source, subject, type or amount' : 'Edit subject or amount'}
            </button>
          )}
          <Row label="Amount" value={Number(lead.amount) ? '₹' + Number(lead.amount).toLocaleString('en-IN') : null} />
          <Row
            label="Follow up"
            value={lead.follow_up_at && (
              <span className={lead.follow_up_due ? 'text-red-500' : undefined}>{lead.follow_up_at.slice(0, 16)}</span>
            )}
          />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Requirement</h2>
          {lead.requirement
            ? <p className="whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{lead.requirement}</p>
            : <p className="text-sm text-slate-400">Nothing noted yet.</p>}
        </Card>
      </div>

      <Card>
        <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <PhoneCall className="size-4 text-emerald-500" /> Record a follow-up
        </h2>
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-0 flex-1 basis-full sm:basis-auto">
            <Label>What happened</Label>
            <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Call not picked, retry tomorrow…" className="w-full" />
          </div>
          <div>
            <Label>New status</Label>
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              {/* The blank choice names the status the lead actually holds,
                  so "unchanged" reads as what it stays — not a mystery. */}
              <option value="">
                {CRM_LEAD_STATUS_LABELS[lead.lead_status] ?? lead.lead_status} (current)
              </option>
              {Object.entries(CRM_LEAD_STATUS_LABELS)
                .filter(([v]) => v !== lead.lead_status)
                .map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </div>
          <div>
            <Label>Next follow-up</Label>
            <Input type="datetime-local" value={nextAt} onChange={(e) => setNextAt(e.target.value)} />
          </div>
          <Button disabled={!note.trim() || followUpMutation.isPending} onClick={() => followUpMutation.mutate()}>
            <Send className="size-4" /> Save
          </Button>
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Lead history</h2>
        {!lead.logs || lead.logs.length === 0 ? (
          <p className="text-sm text-slate-400">No activity recorded.</p>
        ) : (
          <div className="space-y-4 border-l border-slate-100 pl-1 dark:border-slate-800">
            {lead.logs.map((log) => <LogEntry key={log.id} log={log} />)}
          </div>
        )}
      </Card>

      {moving && (
        <Modal title={`Transfer lead #${lead.lead_no}`} onClose={() => setMoving(false)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Currently with <span className="font-medium text-slate-700 dark:text-slate-200">{lead.assigned_member?.name ?? 'nobody'}</span>.
              The trail stays with the lead; only the desk changes.
            </p>
            <div>
              <Label>Transfer to</Label>
              <Select value={moveTo} onChange={(e) => setMoveTo(e.target.value)} className="w-full">
                <option value="">Select employee</option>
                {/* Inside the team only, unless you run the company. */}
                {(masters?.members ?? [])
                  .filter((m) => m.uuid !== lead.assigned_member?.uuid)
                  .filter((m) => teamUuids === null || teamUuids.includes(m.uuid))
                  .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
              </Select>
            </div>
            <div>
              <Label>Note (optional)</Label>
              <Input value={moveNote} onChange={(e) => setMoveNote(e.target.value)} placeholder="Why it is moving…" className="w-full" />
            </div>
            <Button className="w-full" disabled={!moveTo || transferMutation.isPending} onClick={() => transferMutation.mutate()}>
              {transferMutation.isPending ? 'Transferring…' : 'Transfer lead'}
            </Button>
          </div>
        </Modal>
      )}

      {reopening && (
        <Modal title={`Reopen lead #${lead.lead_no}`} onClose={() => setReopening(false)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Nothing is erased: the old discussion stays in the trail, and this note is stamped on top.
              If they became a client, that client is marked a repeat.
            </p>
            <div>
              <Label>Why are they back?</Label>
              <Textarea rows={3} value={reopenNote} onChange={(e) => setReopenNote(e.target.value)}
                placeholder="Called back — budget approved for this quarter." className="w-full" />
            </div>
            <div>
              <Label>Next follow-up (optional)</Label>
              <Input type="datetime-local" value={reopenDate} onChange={(e) => setReopenDate(e.target.value)} className="w-full sm:w-64" />
            </div>
            <Button className="w-full" disabled={!reopenNote.trim() || reopenMutation.isPending} onClick={() => reopenMutation.mutate()}>
              {reopenMutation.isPending ? 'Reopening…' : 'Reopen lead'}
            </Button>
          </div>
        </Modal>
      )}

      {quickEdit && (
        <Modal title={`Lead #${lead.lead_no} — pipeline details`} onClose={() => setQuickEdit(false)}>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              {/* Where a lead came from and what kind it is shape the
                  reports, so those stay the Admin's; the subject and the
                  figure are the salesperson's own working notes. */}
              {isManager && (
                <>
                  <div>
                    <Label>Source</Label>
                    <Select value={quick.source} onChange={(e) => setQuick((q) => ({ ...q, source: e.target.value }))} className="w-full">
                      <option value="">Select</option>
                      {masters?.lead_sources.map((v) => <option key={v} value={v}>{v}</option>)}
                    </Select>
                  </div>
                  <div>
                    <Label>Type</Label>
                    <Select value={quick.lead_type} onChange={(e) => setQuick((q) => ({ ...q, lead_type: e.target.value }))} className="w-full">
                      <option value="new">New</option>
                      <option value="existing">Existing</option>
                    </Select>
                  </div>
                </>
              )}
              <div className="col-span-2">
                <Label>Lead subject</Label>
                <Select value={quick.subject} onChange={(e) => setQuick((q) => ({ ...q, subject: e.target.value }))} className="w-full">
                  <option value="">Select</option>
                  {masters?.lead_subjects.map((v) => <option key={v} value={v}>{v}</option>)}
                </Select>
              </div>
              <div className="col-span-2">
                <Label>Expected amount (₹)</Label>
                <Input type="number" min="0" value={quick.amount} onChange={(e) => setQuick((q) => ({ ...q, amount: e.target.value }))} className="w-full" />
              </div>
            </div>
            <Button className="w-full" disabled={quickMutation.isPending} onClick={() => quickMutation.mutate()}>
              {quickMutation.isPending ? 'Saving…' : 'Save details'}
            </Button>
          </div>
        </Modal>
      )}

      {sharing && (
        <Modal title={`Share lead #${lead.lead_no}`} onClose={() => setSharing(false)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              {lead.assigned_member?.name ?? 'The owner'} keeps the lead; whoever you pick sees it too.
            </p>
            <div className="flex flex-wrap gap-2 rounded-xl bg-slate-50 p-2 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
              {(masters?.members ?? [])
                .filter((m) => m.uuid !== lead.assigned_member?.uuid)
                .map((m) => {
                  const on = shareWith.includes(m.uuid)
                  return (
                    <button
                      key={m.uuid}
                      type="button"
                      onClick={() => setShareWith((v) => (on ? v.filter((x) => x !== m.uuid) : [...v, m.uuid]))}
                      className={
                        on
                          ? 'rounded-full bg-emerald-600 px-3 py-1 text-xs font-medium text-white'
                          : 'rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200 hover:ring-emerald-400 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700'
                      }
                    >
                      {m.name}
                    </button>
                  )
                })}
            </div>
            <Button className="w-full" disabled={shareWith.length === 0 || shareMutation.isPending} onClick={() => shareMutation.mutate()}>
              {shareMutation.isPending ? 'Sharing…' : 'Share lead'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
