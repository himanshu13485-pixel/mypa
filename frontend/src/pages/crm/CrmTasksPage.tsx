import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, CheckSquare, Play, Plus, Send, Trash2, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe, type CrmTask } from '../../api/crm'
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

export default function CrmTasksPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const manages = crmCan(me, 'tasks', 'edit') || crmCan(me, 'tasks', 'create')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [status, setStatus] = useState('')
  const [member, setMember] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ title: '', description: '', assigned_member_uuid: '', due_at: '', priority: 'normal' })
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'tasks', status, member, page],
    queryFn: () => crm.tasks.list({ status: status || undefined, member: member || undefined, page }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'tasks'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] })
  }

  const createMutation = useMutation({
    mutationFn: () =>
      crm.tasks.create({
        title: form.title,
        description: form.description || null,
        assigned_member_uuid: form.assigned_member_uuid,
        due_at: form.due_at ? form.due_at.replace('T', ' ') : null,
        priority: form.priority,
      }),
    onSuccess: () => {
      refresh()
      setShowForm(false)
      setForm({ title: '', description: '', assigned_member_uuid: '', due_at: '', priority: 'normal' })
      toast('Task assigned.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const progressMutation = useMutation({
    mutationFn: ({ uuid, to }: { uuid: string; to: 'in_progress' | 'submitted' }) => {
      const note = to === 'submitted' ? prompt('What was done? (goes to the approver)') ?? undefined : undefined
      return crm.tasks.progress(uuid, to, note)
    },
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const reviewMutation = useMutation({
    mutationFn: ({ uuid, verdict }: { uuid: string; verdict: 'approve' | 'reject' }) => {
      const note = verdict === 'reject' ? prompt('What needs fixing?') ?? undefined : undefined
      return crm.tasks.review(uuid, verdict, note)
    },
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.tasks.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Tasks</h1>
          <p className="text-sm text-slate-500">
            {data ? <>{data.summary.awaiting_review} awaiting approval · {data.summary.overdue} overdue</> : 'Assigned work with an approval loop.'}
          </p>
        </div>
        {manages && (
          <Button onClick={() => { setError(null); setShowForm(true) }}>
            <Plus className="size-4" /> Assign task
          </Button>
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
          </Card>
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <CheckSquare className="size-4 text-emerald-500" /> {manages ? 'All tasks' : 'My tasks'}
          </h2>
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
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No tasks" hint={manages ? 'Assign the first task.' : 'Tasks assigned to you appear here.'} />
        ) : (
          <div className="space-y-2">
            {data.data.map((t: CrmTask) => {
              const isMine = t.assignee?.uuid === me?.member?.uuid
              return (
                <div key={t.uuid} className="rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={clsx(
                      'size-2 shrink-0 rounded-full',
                      t.priority === 'urgent' ? 'bg-red-500' : t.priority === 'high' ? 'bg-amber-500' : t.priority === 'low' ? 'bg-slate-300' : 'bg-sky-400',
                    )} title={`Priority: ${t.priority}`} />
                    <span className="min-w-0 flex-1 truncate font-medium text-slate-800 dark:text-slate-100">{t.title}</span>
                    <span className={statusBadge(t.status)}>{STATUS_LABELS[t.status]}</span>
                    {t.overdue && <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-500/15 dark:text-red-400">Overdue</span>}
                  </div>
                  <div className="mt-1 text-xs text-slate-400">
                    {t.assignee?.name} · assigned by {t.assigned_by ?? '—'}
                    {t.due_at && <> · due {t.due_at.slice(0, 16)}</>}
                    {t.review_note && <span className="text-red-400"> · "{t.review_note}"</span>}
                  </div>
                  {t.description && <p className="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{t.description}</p>}
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {isMine && ['open', 'reopened'].includes(t.status) && (
                      <>
                        <Button size="sm" variant="secondary" onClick={() => progressMutation.mutate({ uuid: t.uuid, to: 'in_progress' })}>
                          <Play className="size-3.5" /> Start
                        </Button>
                        <Button size="sm" onClick={() => progressMutation.mutate({ uuid: t.uuid, to: 'submitted' })}>
                          <Send className="size-3.5" /> Submit
                        </Button>
                      </>
                    )}
                    {isMine && t.status === 'in_progress' && (
                      <Button size="sm" onClick={() => progressMutation.mutate({ uuid: t.uuid, to: 'submitted' })}>
                        <Send className="size-3.5" /> Submit for approval
                      </Button>
                    )}
                    {crmCan(me, 'tasks', 'edit') && t.status === 'submitted' && (
                      <>
                        <Button size="sm" onClick={() => reviewMutation.mutate({ uuid: t.uuid, verdict: 'approve' })}>
                          <Check className="size-3.5" /> Approve
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => reviewMutation.mutate({ uuid: t.uuid, verdict: 'reject' })}>
                          <X className="size-3.5" /> Send back
                        </Button>
                        {t.progress_note && <span className="self-center text-xs text-slate-500">"{t.progress_note}"</span>}
                      </>
                    )}
                    {crmCan(me, 'tasks', 'delete') && t.status !== 'done' && (
                      <button onClick={() => { if (confirm('Delete this task?')) deleteMutation.mutate(t.uuid) }} aria-label="Delete" className="ml-auto rounded p-1.5 text-slate-400 hover:text-red-500">
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <Modal title="Assign task" onClose={() => setShowForm(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>Title</Label>
              <Input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} className="w-full" />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea rows={3} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} className="w-full" />
            </div>
            <div>
              <Label>Assign to</Label>
              <Select value={form.assigned_member_uuid} onChange={(e) => setForm((f) => ({ ...f, assigned_member_uuid: e.target.value }))} className="w-full">
                <option value="">Select</option>
                {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Due</Label>
                <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm((f) => ({ ...f, due_at: e.target.value }))} className="w-full" />
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
            <Button className="w-full" disabled={!form.title || !form.assigned_member_uuid || createMutation.isPending} onClick={() => createMutation.mutate()}>
              {createMutation.isPending ? 'Assigning…' : 'Assign task'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
