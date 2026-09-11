import { useEffect, useMemo, useState } from 'react'
import { useOutletContext, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlarmClock, Check, CheckSquare, FileText, MessageSquare, Pencil, Play, Plus,
  Search, Send, Trash2, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe, type CrmTask, type CrmTaskDocument } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { CHART_COLORS, DonutChart } from './charts'

const STATUS_LABELS: Record<string, string> = {
  open: 'Open', in_progress: 'In progress', submitted: 'Awaiting approval', done: 'Done', reopened: 'Sent back',
}

const STATUS_COLORS: Record<string, string> = {
  open: '#64748b', in_progress: CHART_COLORS[1], submitted: CHART_COLORS[2], done: CHART_COLORS[0], reopened: CHART_COLORS[4],
}

function statusBadge(status: string) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    status === 'done' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    status === 'submitted' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    status === 'in_progress' && 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    status === 'reopened' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
    status === 'open' && 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  )
}

/** The empty form, and the shape both the assign and the edit dialog use. */
const emptyForm = {
  title: '',
  description: '',
  assigned_member_uuid: '',
  kind: 'task' as 'task' | 'pendency',
  invoice_uuid: '',
  due_at: '',
  start_at: '',
  end_at: '',
  priority: 'normal',
  remind_at: '',
  remind_every_days: '',
}

type TaskForm = typeof emptyForm

/** datetime-local wants 'YYYY-MM-DDTHH:mm'; the server speaks in spaces. */
const forInput = (value: string | null) => (value ? value.slice(0, 16).replace(' ', 'T') : '')
const forServer = (value: string) => (value ? value.replace('T', ' ') : null)

export default function CrmTasksPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const manages = crmCan(me, 'tasks', 'edit') || crmCan(me, 'tasks', 'create')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [params, setParams] = useSearchParams()

  const [status, setStatus] = useState(params.get('status') ?? '')
  const [member, setMember] = useState('')
  const [kind, setKind] = useState('')
  const [mine, setMine] = useState(false)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmTask | null>(null)
  const [form, setForm] = useState<TaskForm>(emptyForm)
  const [error, setError] = useState<string | null>(null)
  /* The task being read. A notification links straight to one. */
  const [openTask, setOpenTask] = useState<string | null>(params.get('task'))

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'tasks', status, member, kind, mine, query, page],
    queryFn: () => crm.tasks.list({
      status: status || undefined,
      member: member || undefined,
      kind: kind || undefined,
      mine: mine || undefined,
      search: query || undefined,
      page,
    }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'tasks'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'task-reminders'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] })
  }

  const payloadOf = (f: TaskForm) => ({
    title: f.title,
    description: f.description || null,
    assigned_member_uuid: f.assigned_member_uuid,
    kind: f.kind,
    invoice_uuid: f.invoice_uuid || null,
    due_at: forServer(f.due_at),
    start_at: forServer(f.start_at),
    end_at: forServer(f.end_at),
    priority: f.priority,
    remind_at: forServer(f.remind_at),
    remind_every_days: f.remind_every_days ? Number(f.remind_every_days) : null,
  })

  const saveMutation = useMutation({
    mutationFn: () => editing
      ? crm.tasks.update(editing.uuid, payloadOf(form))
      : crm.tasks.create(payloadOf(form)),
    onSuccess: (res: { message?: string }) => {
      refresh()
      setShowForm(false)
      setEditing(null)
      setForm(emptyForm)
      toast(res.message ?? 'Saved.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const progressMutation = useMutation({
    mutationFn: ({ uuid, to, note }: { uuid: string; to: 'in_progress' | 'submitted'; note?: string }) =>
      crm.tasks.progress(uuid, to, note),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const reviewMutation = useMutation({
    mutationFn: ({ uuid, verdict, note }: { uuid: string; verdict: 'approve' | 'reject'; note?: string }) =>
      crm.tasks.review(uuid, verdict, note),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.tasks.remove(uuid),
    onSuccess: () => { refresh(); setOpenTask(null) },
    onError: (err) => toastError(errorMessage(err)),
  })

  const openAssign = (asPendency: boolean) => {
    setError(null)
    setEditing(null)
    setForm({ ...emptyForm, kind: asPendency ? 'pendency' : 'task' })
    setShowForm(true)
  }

  const openEdit = (task: CrmTask) => {
    setError(null)
    setEditing(task)
    setForm({
      title: task.title,
      description: task.description ?? '',
      assigned_member_uuid: task.assignee?.uuid ?? '',
      kind: task.kind,
      invoice_uuid: task.invoice?.uuid ?? '',
      due_at: forInput(task.due_at),
      start_at: forInput(task.start_at),
      end_at: forInput(task.end_at),
      priority: task.priority,
      remind_at: forInput(task.remind_at),
      remind_every_days: task.remind_every_days ? String(task.remind_every_days) : '',
    })
    setShowForm(true)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Tasks</h1>
          <p className="text-sm text-slate-500">
            {data
              ? <>{data.summary.awaiting_review} awaiting approval · {data.summary.overdue} overdue · {data.summary.pendencies} open pendencies</>
              : 'Assigned work with an approval loop.'}
          </p>
        </div>
        {manages && (
          <div className="flex flex-wrap gap-2">
            {/* A pendency is the same object with a different word on it -
                but it is raised in a different frame of mind, so it gets its
                own way in. */}
            <Button variant="secondary" onClick={() => openAssign(true)}>
              <AlarmClock className="size-4" /> Raise pendency
            </Button>
            <Button onClick={() => openAssign(false)}>
              <Plus className="size-4" /> Assign task
            </Button>
          </div>
        )}
      </div>

      {data && data.summary.by_status.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Board status</h2>
            <DonutChart
              data={data.summary.by_status.map((s) => ({
                label: STATUS_LABELS[s.status] ?? s.status,
                value: s.count,
                color: STATUS_COLORS[s.status],
              }))}
              centerLabel="tasks"
            />
          </Card>
          <Card className="flex flex-col justify-center gap-3">
            <div className="flex items-center justify-between rounded-xl bg-amber-50 px-4 py-3 dark:bg-amber-500/10">
              <span className="text-sm font-medium text-amber-700 dark:text-amber-400">Awaiting approval</span>
              <span className="text-xl font-semibold text-amber-700 dark:text-amber-400">{data.summary.awaiting_review}</span>
            </div>
            <div className="flex items-center justify-between rounded-xl bg-red-50 px-4 py-3 dark:bg-red-500/10">
              <span className="text-sm font-medium text-red-600 dark:text-red-400">Overdue</span>
              <span className="text-xl font-semibold text-red-600 dark:text-red-400">{data.summary.overdue}</span>
            </div>
            <div className="flex items-center justify-between rounded-xl bg-sky-50 px-4 py-3 dark:bg-sky-500/10">
              <span className="text-sm font-medium text-sky-700 dark:text-sky-300">Pendencies open</span>
              <span className="text-xl font-semibold text-sky-700 dark:text-sky-300">{data.summary.pendencies}</span>
            </div>
          </Card>
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <CheckSquare className="size-4 text-emerald-500" /> {manages ? 'All tasks' : 'My tasks'}
          </h2>
          <label className="flex items-center gap-1.5 text-xs text-slate-500">
            <input type="checkbox" checked={mine} onChange={(e) => { setMine(e.target.checked); setPage(1) }} />
            Only mine
          </label>
          <Select value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }}>
            <option value="">Tasks and pendencies</option>
            <option value="task">Tasks only</option>
            <option value="pendency">Pendencies only</option>
          </Select>
          {manages && (
            <Select value={member} onChange={(e) => { setMember(e.target.value); setPage(1) }}>
              <option value="">Everyone</option>
              {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
          )}
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {Object.entries(STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <div className="flex items-center gap-1">
            <Input
              className="w-44"
              placeholder="Title or invoice no…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { setQuery(search); setPage(1) } }}
            />
            <Button variant="secondary" onClick={() => { setQuery(search); setPage(1) }}>
              <Search className="size-4" />
            </Button>
          </div>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No tasks" hint={manages ? 'Assign the first task.' : 'Tasks assigned to you appear here.'} />
        ) : (
          <div className="space-y-2">
            {data.data.map((t: CrmTask) => (
              <div key={t.uuid} className="rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                <div className="flex flex-wrap items-center gap-2">
                  <span className={clsx(
                    'size-2 shrink-0 rounded-full',
                    t.priority === 'urgent' ? 'bg-red-500' : t.priority === 'high' ? 'bg-amber-500' : t.priority === 'low' ? 'bg-slate-300' : 'bg-sky-400',
                  )} title={`Priority: ${t.priority}`} />
                  <button
                    className="min-w-0 flex-1 truncate text-left font-medium text-slate-800 hover:underline dark:text-slate-100"
                    onClick={() => setOpenTask(t.uuid)}
                  >
                    {t.title}
                  </button>
                  {t.kind === 'pendency' && (
                    <span className="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                      Pendency
                    </span>
                  )}
                  <span className={statusBadge(t.status)}>{STATUS_LABELS[t.status]}</span>
                  {t.overdue && <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-500/15 dark:text-red-400">Overdue</span>}
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                  <span>{t.assignee?.name} · assigned by {t.assigned_by ?? '—'}</span>
                  {t.due_at && <span>· due {t.due_at.slice(0, 16)}</span>}
                  {(t.start_at || t.end_at) && (
                    <span>· expected {t.start_at?.slice(0, 10) ?? '…'} → {t.end_at?.slice(0, 10) ?? '…'}</span>
                  )}
                  {/* Said out loud: a task that changed after it was handed
                      over should leave nobody wondering. */}
                  {t.edited_at && (
                    <span className="text-amber-500">· edited by {t.edited_by} on {t.edited_at.slice(0, 16)}</span>
                  )}
                  {t.comments_count > 0 && (
                    <span className="flex items-center gap-1 text-slate-500">
                      · <MessageSquare className="size-3" /> {t.comments_count}
                    </span>
                  )}
                  {t.review_note && <span className="text-red-400">· "{t.review_note}"</span>}
                </div>

                {t.invoice && (
                  <a
                    href={`/crm/invoices/${t.invoice.uuid}`}
                    className="mt-1.5 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 hover:border-brand-300 hover:text-brand-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                  >
                    <FileText className="size-3" />
                    {t.invoice.number}
                    {t.invoice.client && <span className="text-slate-400">· {t.invoice.client}</span>}
                  </a>
                )}

                {t.description && <p className="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{t.description}</p>}

                <div className="mt-2 flex flex-wrap gap-1.5">
                  <Button size="sm" variant="secondary" onClick={() => setOpenTask(t.uuid)}>
                    <MessageSquare className="size-3.5" /> Open{t.comments_count > 0 ? ` (${t.comments_count})` : ''}
                  </Button>
                  {t.is_mine && ['open', 'reopened'].includes(t.status) && (
                    <Button size="sm" variant="secondary" onClick={() => progressMutation.mutate({ uuid: t.uuid, to: 'in_progress' })}>
                      <Play className="size-3.5" /> Start
                    </Button>
                  )}
                  {t.is_mine && ['open', 'reopened', 'in_progress'].includes(t.status) && (
                    <Button size="sm" onClick={() => {
                      const note = prompt('What was done? (goes to the approver)') ?? undefined
                      progressMutation.mutate({ uuid: t.uuid, to: 'submitted', note })
                    }}>
                      <Send className="size-3.5" /> Submit
                    </Button>
                  )}
                  {crmCan(me, 'tasks', 'edit') && t.status === 'submitted' && (
                    <>
                      <Button size="sm" onClick={() => reviewMutation.mutate({ uuid: t.uuid, verdict: 'approve' })}>
                        <Check className="size-3.5" /> Approve
                      </Button>
                      <Button size="sm" variant="secondary" onClick={() => {
                        const note = prompt('What needs fixing?') ?? undefined
                        reviewMutation.mutate({ uuid: t.uuid, verdict: 'reject', note })
                      }}>
                        <X className="size-3.5" /> Send back
                      </Button>
                      {t.progress_note && <span className="self-center text-xs text-slate-500">"{t.progress_note}"</span>}
                    </>
                  )}
                  {crmCan(me, 'tasks', 'edit') && t.status !== 'done' && (
                    <button onClick={() => openEdit(t)} aria-label="Edit" title="Edit" className="ml-auto rounded p-1.5 text-slate-400 hover:text-brand-600">
                      <Pencil className="size-4" />
                    </button>
                  )}
                  {crmCan(me, 'tasks', 'delete') && t.status !== 'done' && (
                    <button onClick={() => { if (confirm('Delete this task?')) deleteMutation.mutate(t.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <TaskFormModal
          form={form}
          setForm={setForm}
          editing={editing}
          error={error}
          members={masters?.members ?? []}
          saving={saveMutation.isPending}
          onSave={() => saveMutation.mutate()}
          onClose={() => { setShowForm(false); setEditing(null) }}
        />
      )}

      {openTask && (
        <TaskThread
          uuid={openTask}
          me={me}
          onClose={() => {
            setOpenTask(null)
            if (params.get('task')) setParams(new URLSearchParams(), { replace: true })
          }}
          onChanged={refresh}
          onEdit={(task) => { setOpenTask(null); openEdit(task) }}
        />
      )}
    </div>
  )
}

/**
 * Assigning, and changing what was assigned.
 *
 * One dialog for both, because they ask the same questions - and because an
 * edit that offered fewer fields than the original would be a quiet way of
 * losing what was set at the start.
 */
function TaskFormModal({ form, setForm, editing, error, members, saving, onSave, onClose }: {
  form: TaskForm
  setForm: React.Dispatch<React.SetStateAction<TaskForm>>
  editing: CrmTask | null
  error: string | null
  members: { uuid: string; name: string | null }[]
  saving: boolean
  onSave: () => void
  onClose: () => void
}) {
  const isPendency = form.kind === 'pendency'

  return (
    <Modal
      title={editing ? 'Edit task' : isPendency ? 'Raise a pendency' : 'Assign task'}
      onClose={onClose}
      wide
    >
      <div className="space-y-3">
        <ErrorNote message={error} />

        {editing && (
          <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
            Whoever this is assigned to will be told it changed, and the task will say so on its face.
          </p>
        )}

        <div className="grid gap-3 sm:grid-cols-3">
          <div className="sm:col-span-2">
            <Label>Title</Label>
            <Input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Kind</Label>
            <Select value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value as 'task' | 'pendency' }))} className="w-full">
              <option value="task">Task</option>
              <option value="pendency">Pendency</option>
            </Select>
          </div>
        </div>

        <div>
          <Label>{isPendency ? 'What is pending, and from whom' : 'Description'}</Label>
          <Textarea
            rows={3}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            placeholder={isPendency ? 'The client will not release payment without the PO on file…' : ''}
            className="w-full"
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Assign to</Label>
            <Select value={form.assigned_member_uuid} onChange={(e) => setForm((f) => ({ ...f, assigned_member_uuid: e.target.value }))} className="w-full">
              <option value="">Select</option>
              {members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
          </div>
          <div>
            <Label>Priority</Label>
            <Select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} className="w-full">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </Select>
          </div>
        </div>

        <DocumentPicker
          value={form.invoice_uuid}
          currentLabel={editing?.invoice ? `${editing.invoice.number} · ${editing.invoice.client ?? ''}` : null}
          onPick={(uuid) => setForm((f) => ({ ...f, invoice_uuid: uuid }))}
        />

        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <Label>Expected start</Label>
            <Input type="datetime-local" value={form.start_at} onChange={(e) => setForm((f) => ({ ...f, start_at: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Expected finish</Label>
            <Input type="datetime-local" value={form.end_at} onChange={(e) => setForm((f) => ({ ...f, end_at: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Due</Label>
            <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm((f) => ({ ...f, due_at: e.target.value }))} className="w-full" />
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
          <p className="mb-2 text-xs text-slate-500">
            {/* The whole point of the date: work due in three months should
                not be popping up this morning. */}
            Remind whoever owes the next word. Left blank it starts asking straight away;
            for something months off, name the date it should start.
          </p>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Start reminding on</Label>
              <Input type="datetime-local" value={form.remind_at} onChange={(e) => setForm((f) => ({ ...f, remind_at: e.target.value }))} className="w-full" />
            </div>
            <div>
              <Label>Then every (days)</Label>
              <Input
                type="number"
                min="1"
                max="365"
                value={form.remind_every_days}
                onChange={(e) => setForm((f) => ({ ...f, remind_every_days: e.target.value }))}
                placeholder="1"
                className="w-full"
              />
            </div>
          </div>
        </div>

        <Button
          className="w-full"
          disabled={!form.title || !form.assigned_member_uuid || saving}
          onClick={onSave}
        >
          {saving ? 'Saving…' : editing ? 'Save changes' : isPendency ? 'Raise pendency' : 'Assign task'}
        </Button>
      </div>
    </Modal>
  )
}

/**
 * Finding the invoice or proforma a task is about.
 *
 * By anything somebody would have in front of them - the number, the
 * company, who they spoke to, the address they wrote from, the number they
 * rang. Asking them to know which of those the search wants is asking them
 * to do the computer's job.
 */
function DocumentPicker({ value, currentLabel, onPick }: {
  value: string
  currentLabel: string | null
  onPick: (uuid: string) => void
}) {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [chosen, setChosen] = useState<CrmTaskDocument | null>(null)

  // Typed, then left alone for a moment - not on every keystroke.
  useEffect(() => {
    const timer = setTimeout(() => setQuery(search.trim()), 350)
    return () => clearTimeout(timer)
  }, [search])

  const { data, isFetching } = useQuery({
    queryKey: ['crm', 'task-documents', query],
    queryFn: () => crm.tasks.documents(query),
    enabled: query.length >= 2,
  })

  const label = chosen ? `${chosen.number} · ${chosen.client ?? ''}` : (value ? currentLabel : null)

  return (
    <div>
      <Label>About which invoice or proforma (optional)</Label>

      {label ? (
        <div className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800/60">
          <span className="flex min-w-0 items-center gap-1.5">
            <FileText className="size-3.5 shrink-0 text-slate-400" />
            <span className="truncate">{label}</span>
          </span>
          <button
            onClick={() => { setChosen(null); onPick('') }}
            aria-label="Unlink the document"
            className="shrink-0 rounded p-1 text-slate-400 hover:text-red-500"
          >
            <X className="size-3.5" />
          </button>
        </div>
      ) : (
        <>
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Invoice no, company, contact person, e-mail or mobile…"
            className="w-full"
          />
          {query.length >= 2 && (
            <div className="mt-1 max-h-52 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
              {isFetching ? (
                <p className="px-3 py-2 text-xs text-slate-400">Looking…</p>
              ) : (data?.length ?? 0) === 0 ? (
                <p className="px-3 py-2 text-xs text-slate-400">Nothing matching “{query}”.</p>
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data!.map((doc) => (
                    <li key={doc.uuid}>
                      <button
                        className="w-full px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800"
                        onClick={() => { setChosen(doc); onPick(doc.uuid); setSearch('') }}
                      >
                        <div className="flex flex-wrap items-baseline gap-x-2 text-sm">
                          <span className="font-medium text-slate-800 dark:text-slate-100">{doc.number}</span>
                          <span className="text-xs text-slate-400">
                            {doc.kind === 'proforma' ? 'Proforma' : 'Invoice'} · {doc.date}
                          </span>
                        </div>
                        <div className="truncate text-xs text-slate-500">
                          {[doc.client, doc.contact_person, doc.email, doc.mobile].filter(Boolean).join(' · ')}
                        </div>
                        {doc.salesperson && (
                          <div className="text-[11px] text-slate-400">raised by {doc.salesperson}</div>
                        )}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

/**
 * The task itself, and everything said about it.
 *
 * A pendency is a conversation with a subject line: accounts ask, the
 * salesperson answers, and both need to read what was said last week
 * without going to look for the e-mail.
 */
function TaskThread({ uuid, me, onClose, onChanged, onEdit }: {
  uuid: string
  me: CrmMe | undefined
  onClose: () => void
  onChanged: () => void
  onEdit: (task: CrmTask) => void
}) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [body, setBody] = useState('')

  const { data: task, isLoading } = useQuery({
    queryKey: ['crm', 'task', uuid],
    queryFn: () => crm.tasks.get(uuid),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'task', uuid] })
    onChanged()
  }

  const commentMutation = useMutation({
    mutationFn: () => crm.tasks.comment(uuid, body.trim()),
    onSuccess: () => { setBody(''); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const snoozeMutation = useMutation({
    mutationFn: () => crm.tasks.snooze(uuid, 24),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const turn = useMemo(() => {
    if (!task || task.status === 'done') return null
    if (task.awaiting === 'assignee') return `Waiting on ${task.is_mine ? 'you' : task.assignee?.name}`
    if (task.awaiting === 'assigner') return `Back with ${task.is_my_pendency ? 'you' : task.assigned_by}`
    return null
  }, [task])

  return (
    <Modal title={task?.title ?? 'Task'} onClose={onClose} wide>
      {isLoading || !task ? (
        <div className="flex justify-center py-16"><Spinner /></div>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className={statusBadge(task.status)}>{STATUS_LABELS[task.status]}</span>
            {task.kind === 'pendency' && (
              <span className="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                Pendency
              </span>
            )}
            {turn && <span className="text-xs text-slate-500">{turn}</span>}
            {task.overdue && <span className="text-xs font-medium text-red-500">Overdue</span>}
          </div>

          <dl className="grid gap-x-6 text-xs sm:grid-cols-2">
            <Detail label="Assigned to" value={task.assignee?.name} />
            <Detail label="Raised by" value={task.assigned_by} />
            <Detail
              label="Expected"
              value={task.start_at || task.end_at
                ? `${task.start_at?.slice(0, 16) ?? '…'} → ${task.end_at?.slice(0, 16) ?? '…'}`
                : null}
            />
            <Detail label="Due" value={task.due_at?.slice(0, 16)} />
            <Detail
              label="Reminders"
              value={task.remind_at
                ? `from ${task.remind_at.slice(0, 16)}${task.remind_every_days ? `, every ${task.remind_every_days} day(s)` : ''}`
                : 'off'}
            />
            <Detail label="Edited" value={task.edited_at ? `${task.edited_at.slice(0, 16)} by ${task.edited_by}` : null} />
          </dl>

          {task.description && (
            <p className="whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
              {task.description}
            </p>
          )}

          {task.invoice && (
            <a
              href={`/crm/invoices/${task.invoice.uuid}`}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:border-brand-300 hover:text-brand-600 dark:border-slate-700 dark:text-slate-300"
            >
              <FileText className="size-3.5" />
              {task.invoice.number}
              {task.invoice.client && <span className="text-slate-400">· {task.invoice.client}</span>}
            </a>
          )}

          {/* The conversation. Nobody edits one and nobody deletes one -
              being able to say later what was asked and what was answered is
              the whole use of it. */}
          <div>
            <h3 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <MessageSquare className="size-4 text-emerald-500" /> Discussion
            </h3>
            {(task.comments?.length ?? 0) === 0 ? (
              <p className="text-xs text-slate-400">Nothing said yet.</p>
            ) : (
              <ul className="space-y-2">
                {task.comments!.map((c) => (
                  <li key={c.uuid} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                      <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                        {c.by}{c.by_uuid === me?.member?.uuid && ' (you)'}
                      </span>
                      <span className="text-[11px] text-slate-400">{c.at}</span>
                    </div>
                    <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{c.body}</p>
                  </li>
                ))}
              </ul>
            )}

            {task.status !== 'done' && (
              <div className="mt-2 flex items-end gap-2">
                <Textarea
                  rows={2}
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  placeholder="Asked the client this morning, they are sending it today…"
                  className="min-w-0 flex-1"
                />
                <Button disabled={!body.trim() || commentMutation.isPending} onClick={() => commentMutation.mutate()}>
                  <Send className="size-4" /> Reply
                </Button>
              </div>
            )}
          </div>

          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            {task.status !== 'done' && task.remind_at && (
              <Button variant="secondary" disabled={snoozeMutation.isPending} onClick={() => snoozeMutation.mutate()}>
                <AlarmClock className="size-4" /> Remind me tomorrow
              </Button>
            )}
            {crmCan(me, 'tasks', 'edit') && task.status !== 'done' && (
              <Button variant="secondary" onClick={() => onEdit(task)}>
                <Pencil className="size-4" /> Edit
              </Button>
            )}
            <Button variant="secondary" onClick={onClose}>Close</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

function Detail({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value) return null

  return (
    <div className="flex justify-between gap-3 border-b border-slate-50 py-1 dark:border-slate-800/60">
      <dt className="text-slate-400">{label}</dt>
      <dd className="text-right font-medium text-slate-700 dark:text-slate-200">{value}</dd>
    </div>
  )
}
