import { useState } from 'react'
import { Link, useNavigate, useOutletContext, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, Building2, Clock, Download, Lock, Paperclip, Send, Trash2, UserCheck,
} from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmComplaintError, type CrmComplaintReply, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'
import { ErrorPill, StatusPill } from './CrmComplaintsPage'
import { useMediaQuery } from '../../lib/useMediaQuery'

export default function CrmComplaintDetailPage() {
  const { uuid = '' } = useParams()
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const canEdit = crmCan(me, 'complaints', 'edit')
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [audience, setAudience] = useState<'client' | 'internal'>('client')
  const [draft, setDraft] = useState('')
  const [closing, setClosing] = useState(false)
  const [allocating, setAllocating] = useState(false)

  const { data: complaint, isLoading } = useQuery({
    queryKey: ['crm', 'complaint', uuid],
    queryFn: () => crm.complaints.show(uuid),
  })
  const { data: options } = useQuery({ queryKey: ['crm', 'complaint-options'], queryFn: crm.complaints.options })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'complaint', uuid] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'complaints'] })
  }

  const replyMutation = useMutation({
    mutationFn: () => crm.complaints.reply(uuid, audience, draft),
    onSuccess: (res) => {
      queryClient.setQueryData(['crm', 'complaint', uuid], res.data)
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaints'] })
      setDraft('')
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteReplyMutation = useMutation({
    mutationFn: (replyUuid: string) => crm.complaints.deleteReply(uuid, replyUuid),
    onSuccess: (res) => queryClient.setQueryData(['crm', 'complaint', uuid], res.data),
    onError: (err) => toastError(errorMessage(err)),
  })

  const startMutation = useMutation({
    mutationFn: () => crm.complaints.status(uuid, { status: 'in_progress' }),
    onSuccess: (res) => { queryClient.setQueryData(['crm', 'complaint', uuid], res.data); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading || !complaint) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  const clientThread = (complaint.replies ?? []).filter((r) => r.audience === 'client')
  const officeThread = (complaint.replies ?? []).filter((r) => r.audience === 'internal')
  const closed = complaint.status.startsWith('closed')

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <button onClick={() => navigate('/crm/complaints')} className="mb-1 flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
            <ArrowLeft className="size-3.5" /> All complaints
          </button>
          <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            {complaint.cms_no}
            <StatusPill status={complaint.status} overdue={complaint.overdue} label={complaint.status_label} />
            {complaint.final_error_type && (
              <ErrorPill type={complaint.final_error_type} label={complaint.final_error_label ?? ''} />
            )}
          </h1>
          <p className="text-sm text-slate-500">
            {complaint.company_name}
            {complaint.subject && <> · {complaint.subject}</>}
          </p>
        </div>
        {canEdit && (
          <div className="flex flex-wrap items-center gap-2">
            {options?.can_allocate && (
              <Button variant="secondary" onClick={() => setAllocating(true)}>
                <UserCheck className="size-4" /> {complaint.allocated_to ? 'Reallocate' : 'Allocate'}
              </Button>
            )}
            {complaint.status === 'unattended' && (
              <Button variant="secondary" onClick={() => startMutation.mutate()} disabled={startMutation.isPending}>
                Start work
              </Button>
            )}
            {closed ? (
              <Button variant="secondary" onClick={() => startMutation.mutate()} disabled={startMutation.isPending}>
                Reopen
              </Button>
            ) : (
              <Button onClick={() => setClosing(true)}>Close complaint</Button>
            )}
          </div>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {/* The subject sits above the description, because it is what the
              complaint IS; the description is only the detail of it. */}
          <Card>
            <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Subject</div>
            <h2 className="text-base font-semibold text-slate-900 dark:text-white">{complaint.subject ?? '—'}</h2>
            {complaint.details && (
              <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{complaint.details}</p>
            )}
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
              {[
                ['Source', complaint.source], ['Type', complaint.complaint_type],
                ['Mode', complaint.mode], ['Invoice', complaint.invoice_no],
                ['Logged by', complaint.raised_by],
              ].filter(([, v]) => v).map(([k, v]) => <span key={k as string}>{k}: <span className="text-slate-600 dark:text-slate-300">{v}</span></span>)}
            </div>
          </Card>

          <Thread
            title="With the client"
            hint="What the client is told. Keep it to what you would be happy for them to read back."
            tone="client"
            replies={clientThread}
            me={me}
            onDelete={(r) => { if (confirm('Remove this line?')) deleteReplyMutation.mutate(r) }}
          />

          <Thread
            title="Inside the office"
            hint="Never shown to the client — the working-out, the blame, the awkward bits."
            tone="internal"
            replies={officeThread}
            me={me}
            onDelete={(r) => { if (confirm('Remove this note?')) deleteReplyMutation.mutate(r) }}
          />

          <Card>
            <div className="mb-2 flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
              {([['client', 'Reply to the client'], ['internal', 'Internal note']] as const).map(([key, label]) => (
                <button
                  key={key}
                  onClick={() => setAudience(key)}
                  className={clsx(
                    'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
                    audience === key
                      ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                      : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
                  )}
                >
                  {key === 'internal' && <Lock className="mr-1 inline size-3.5" />}
                  {label}
                </button>
              ))}
            </div>
            <textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              rows={3}
              placeholder={audience === 'client'
                ? 'What are we telling them?'
                : 'What actually happened, for the office only.'}
              className="w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-400 dark:border-slate-700 dark:bg-slate-900"
            />
            <div className="mt-2 flex items-center justify-between gap-2">
              <p className="text-xs text-slate-400">
                {audience === 'client'
                  ? 'The first reply to the client stops the response clock.'
                  : 'This never leaves the building.'}
              </p>
              <Button disabled={!draft.trim() || replyMutation.isPending} onClick={() => replyMutation.mutate()}>
                <Send className="size-4" /> {replyMutation.isPending ? 'Saving…' : 'Post'}
              </Button>
            </div>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <Clock className="size-4 text-slate-400" /> The clock
            </h2>
            <dl className="space-y-1.5 text-sm">
              {[
                ['Complained on', complaint.complained_on],
                ['Answer by', complaint.due_at?.slice(0, 16) ?? '—'],
                ['Work started', complaint.in_progress_at?.slice(0, 16) ?? 'not yet'],
                ['Client first answered', complaint.first_response_at?.slice(0, 16) ?? 'not yet'],
                ['Closed', complaint.closed_at?.slice(0, 16) ?? '—'],
              ].map(([k, v]) => (
                <div key={k} className="flex items-baseline justify-between gap-2">
                  <dt className="text-xs text-slate-400">{k}</dt>
                  <dd className={clsx(
                    'text-right text-slate-700 dark:text-slate-200',
                    k === 'Answer by' && complaint.overdue && 'font-medium text-red-500',
                  )}>{v}</dd>
                </div>
              ))}
            </dl>
          </Card>

          <Card>
            <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <UserCheck className="size-4 text-slate-400" /> Whose desk
            </h2>
            <dl className="space-y-1.5 text-sm">
              {[
                ['On', complaint.allocated_to ?? 'nobody yet'],
                ['Given by', complaint.allocated_by ?? '—'],
                ['Key responsible', complaint.key_responsible ?? '—'],
                ['Priority', complaint.priority],
              ].map(([k, v]) => (
                <div key={k} className="flex items-baseline justify-between gap-2">
                  <dt className="text-xs text-slate-400">{k}</dt>
                  <dd className="text-right capitalize text-slate-700 dark:text-slate-200">{v}</dd>
                </div>
              ))}
            </dl>
          </Card>

          <Card>
            <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <Building2 className="size-4 text-slate-400" /> Who complained
            </h2>
            <dl className="space-y-1.5 text-sm">
              {[
                ['Company', complaint.company_name],
                ['Contact', complaint.contact_person],
                ['Mobile', complaint.mobile],
                ['Phone', complaint.phone],
                ['Email', complaint.email],
                ['Alt. contact', complaint.alt_contact_person],
                ['Alt. mobile', complaint.alt_mobile],
                ['Alt. phone', complaint.alt_phone],
                ['Alt. email', complaint.alt_email],
              ].filter(([, v]) => v).map(([k, v]) => (
                <div key={k as string} className="flex items-baseline justify-between gap-2">
                  <dt className="shrink-0 text-xs text-slate-400">{k}</dt>
                  <dd className="min-w-0 break-words text-right text-slate-700 dark:text-slate-200">{v}</dd>
                </div>
              ))}
            </dl>
            {complaint.client_uuid && (
              <Link to={`/crm/clients/${complaint.client_uuid}`} className="mt-2 inline-block text-xs font-medium text-emerald-600 hover:underline">
                Open the client record →
              </Link>
            )}
          </Card>

          {closed && (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">How it ended</h2>
              <p className="whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{complaint.resolution}</p>
              <div className="mt-3 space-y-1 text-xs text-slate-400">
                {complaint.final_error_label && (
                  <div>
                    Final error: <span className="text-slate-600 dark:text-slate-300">{complaint.final_error_label}</span>
                    {complaint.final_error_member && <> — {complaint.final_error_member}</>}
                  </div>
                )}
                {complaint.final_error_note && <div>{complaint.final_error_note}</div>}
                {complaint.closed_by && <div>Closed by {complaint.closed_by}</div>}
              </div>
            </Card>
          )}

          <Attachments uuid={uuid} complaint={complaint} canEdit={canEdit} onChange={refresh} />
        </div>
      </div>

      {closing && options && (
        <CloseDialog
          uuid={uuid}
          errorTypes={options.error_types}
          members={options.members}
          onClose={() => setClosing(false)}
          onDone={(next) => {
            queryClient.setQueryData(['crm', 'complaint', uuid], next)
            refresh()
            setClosing(false)
          }}
        />
      )}

      {allocating && options && (
        <AllocateDialog
          uuid={uuid}
          current={complaint.allocated_to_uuid}
          currentKey={complaint.key_responsible_uuid}
          members={options.members}
          priorities={options.priorities}
          onClose={() => setAllocating(false)}
          onDone={(next) => {
            queryClient.setQueryData(['crm', 'complaint', uuid], next)
            refresh()
            setAllocating(false)
          }}
        />
      )}
    </div>
  )
}

function Thread({ title, hint, tone, replies, me, onDelete }: {
  title: string
  hint: string
  tone: 'client' | 'internal'
  replies: CrmComplaintReply[]
  me: CrmMe | undefined
  onDelete: (replyUuid: string) => void
}) {
  const manager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  /*
   * On a touchscreen the remove button cannot fade in on hover, because there
   * is no hover — and unlike the calendar's add button, nothing else here
   * deletes a reply, so on a tablet or a phone it simply could not be done.
   */
  const noHover = useMediaQuery('(hover: none)')

  return (
    <Card>
      <div className="mb-2 flex items-baseline justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
          {tone === 'internal' && <Lock className="size-3.5 text-amber-500" />}
          {title}
        </h2>
        <span className="text-xs text-slate-400">{replies.length}</span>
      </div>
      <p className="mb-3 text-xs text-slate-400">{hint}</p>

      {replies.length === 0 ? (
        <p className="py-4 text-center text-sm text-slate-400">Nothing said here yet.</p>
      ) : (
        <ul className="space-y-2">
          {replies.map((r) => (
            <li
              key={r.uuid}
              className={clsx(
                'group rounded-xl px-3 py-2',
                tone === 'internal'
                  ? 'bg-amber-50/60 dark:bg-amber-500/5'
                  : 'bg-slate-50 dark:bg-slate-800/40',
              )}
            >
              <div className="flex items-baseline justify-between gap-2">
                <span className="text-xs font-medium text-slate-600 dark:text-slate-300">{r.author ?? 'Someone'}</span>
                <span className="flex items-center gap-1.5 text-[11px] text-slate-400">
                  {r.created_at?.slice(0, 16)}
                  {(manager || r.author_uuid === me?.member?.uuid) && (
                    <button
                      onClick={() => onDelete(r.uuid)}
                      className={clsx('transition', noHover ? 'opacity-100' : 'opacity-0 group-hover:opacity-100')}
                      aria-label="Remove"
                    >
                      <Trash2 className="size-3.5 text-slate-400 hover:text-red-500" />
                    </button>
                  )}
                </span>
              </div>
              <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">{r.body}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

/**
 * Closing asks the two questions the register exists for: what was actually
 * done, and whose mistake it was. An executive error names the executive.
 */
function CloseDialog({ uuid, errorTypes, members, onClose, onDone }: {
  uuid: string
  errorTypes: Record<CrmComplaintError, string>
  members: { uuid: string; name: string | null }[]
  onClose: () => void
  onDone: (next: Parameters<typeof onClose> extends never ? never : any) => void
}) {
  const [status, setStatus] = useState<'closed_satisfied' | 'closed_dissatisfied'>('closed_satisfied')
  const [resolution, setResolution] = useState('')
  const [errorType, setErrorType] = useState<CrmComplaintError | ''>('')
  const [errorMember, setErrorMember] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => crm.complaints.status(uuid, {
      status,
      resolution,
      final_error_type: errorType || null,
      final_error_member: errorMember || null,
      final_error_note: note || null,
    }),
    onSuccess: (res) => onDone(res.data),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title="Close the complaint" onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />

        <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
          {([['closed_satisfied', 'With satisfaction'], ['closed_dissatisfied', 'With dissatisfaction']] as const).map(([key, label]) => (
            <button
              key={key}
              onClick={() => setStatus(key)}
              className={clsx(
                'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
                status === key
                  ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                  : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
              )}
            >
              {label}
            </button>
          ))}
        </div>

        <div>
          <Label>What was actually done</Label>
          <textarea
            value={resolution}
            onChange={(e) => setResolution(e.target.value)}
            rows={3}
            className="w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-400 dark:border-slate-700 dark:bg-slate-900"
          />
        </div>

        <div>
          <Label>Final error type</Label>
          <Select value={errorType} onChange={(e) => setErrorType(e.target.value as CrmComplaintError | '')} className="w-full">
            <option value="">Select</option>
            {Object.entries(errorTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </Select>
          <p className="mt-1 text-xs text-slate-400">Who it happened because of — not who fixed it.</p>
        </div>

        {(errorType === 'executive' || errorType === 'backend' || errorType === 'common') && (
          <div>
            <Label>{errorType === 'executive' ? 'Which executive' : 'Person answerable (optional)'}</Label>
            <Select value={errorMember} onChange={(e) => setErrorMember(e.target.value)} className="w-full">
              <option value="">{errorType === 'executive' ? 'Select the executive' : 'Nobody in particular'}</option>
              {members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
            {errorType === 'executive' && (
              <p className="mt-1 text-xs text-slate-400">They are told, so the record is never a surprise.</p>
            )}
          </div>
        )}

        <div>
          <Label>Note (optional)</Label>
          <Input value={note} onChange={(e) => setNote(e.target.value)} className="w-full" placeholder="What to do differently next time." />
        </div>

        <Button
          className="w-full"
          disabled={!resolution.trim() || !errorType || (errorType === 'executive' && !errorMember) || mutation.isPending}
          onClick={() => { setError(null); mutation.mutate() }}
        >
          {mutation.isPending ? 'Closing…' : 'Close complaint'}
        </Button>
      </div>
    </Modal>
  )
}

function AllocateDialog({ uuid, current, currentKey, members, priorities, onClose, onDone }: {
  uuid: string
  current: string | null
  currentKey: string | null
  members: { uuid: string; name: string | null; allocated: number }[]
  priorities: Record<string, string>
  onClose: () => void
  onDone: (next: any) => void
}) {
  const [to, setTo] = useState(current ?? '')
  const [key, setKey] = useState(currentKey ?? '')
  const [priority, setPriority] = useState('normal')
  const [dueAt, setDueAt] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => crm.complaints.allocate(uuid, {
      allocated_to: to,
      key_responsible: key || null,
      priority,
      due_at: dueAt || null,
    }),
    onSuccess: (res) => onDone(res.data),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title="Put it on a desk" onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <div>
          <Label>Allocate to</Label>
          <Select value={to} onChange={(e) => setTo(e.target.value)} className="w-full">
            <option value="">Select</option>
            {members.map((m) => (
              <option key={m.uuid} value={m.uuid}>{m.name}{m.allocated ? ` — carrying ${m.allocated}` : ''}</option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Key responsible person</Label>
          <Select value={key} onChange={(e) => setKey(e.target.value)} className="w-full">
            <option value="">Nobody</option>
            {members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Priority</Label>
            <Select value={priority} onChange={(e) => setPriority(e.target.value)} className="w-full">
              {Object.entries(priorities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
          </div>
          <div>
            <Label>Answer by</Label>
            <Input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} className="w-full" />
          </div>
        </div>
        <Button className="w-full" disabled={!to || mutation.isPending} onClick={() => { setError(null); mutation.mutate() }}>
          {mutation.isPending ? 'Saving…' : 'Allocate'}
        </Button>
      </div>
    </Modal>
  )
}

function Attachments({ uuid, complaint, canEdit, onChange }: {
  uuid: string
  complaint: { documents?: { uuid: string; name: string; size: number }[] }
  canEdit: boolean
  onChange: () => void
}) {
  const { toast, toastError } = useToast()
  const queryClient = useQueryClient()

  const upload = useMutation({
    mutationFn: (file: File) => crm.complaints.uploadFile(uuid, file),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaint', uuid] })
      onChange()
      toast('Attached.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const download = async (doc: { uuid: string; name: string }) => {
    try {
      const blob = await crm.complaints.downloadFile(uuid, doc.uuid)
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

  return (
    <Card>
      <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Paperclip className="size-4 text-slate-400" /> Evidence
      </h2>
      {(complaint.documents ?? []).length === 0 ? (
        <p className="text-sm text-slate-400">Nothing attached.</p>
      ) : (
        <ul className="space-y-1">
          {complaint.documents!.map((d) => (
            <li key={d.uuid}>
              <button onClick={() => download(d)} className="flex items-center gap-1.5 text-sm text-emerald-600 hover:underline">
                <Download className="size-3.5" /> {d.name}
              </button>
            </li>
          ))}
        </ul>
      )}
      {canEdit && (
        <input
          type="file"
          onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f) }}
          className="mt-3 block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-slate-700 dark:file:bg-slate-800 dark:file:text-slate-200"
        />
      )}
    </Card>
  )
}
