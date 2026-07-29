import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { CheckCircle2, Circle, Copy, Pin, Plus, Star, Trash2 } from 'lucide-react'
import { format } from 'date-fns'
import { clsx } from 'clsx'
import { categories as categoriesApi, tasks as tasksApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import {
  Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner, Textarea,
} from '../components/ui'
import { TASK_PRIORITIES, TASK_STATUSES, type Task } from '../types'

interface TaskFormState {
  title: string
  description: string
  category_uuid: string
  priority: string
  status: string
  due_at: string
  is_important: boolean
  checklist: { title: string; is_done?: boolean }[]
  tags: string
  assignees: string
  reminder_offset: string
}

const emptyForm: TaskFormState = {
  title: '',
  description: '',
  category_uuid: '',
  priority: 'normal',
  status: 'not_started',
  due_at: '',
  is_important: false,
  checklist: [],
  tags: '',
  assignees: '',
  reminder_offset: '',
}

function formToPayload(form: TaskFormState): Record<string, unknown> {
  return {
    title: form.title,
    description: form.description || null,
    category_uuid: form.category_uuid || null,
    priority: form.priority,
    status: form.status,
    due_at: form.due_at ? form.due_at.replace('T', ' ') + ':00' : null,
    is_important: form.is_important,
    checklist: form.checklist.filter((c) => c.title.trim()),
    tags: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
    assignees: form.assignees.split(',').map((t) => t.trim()).filter(Boolean),
    reminders: form.reminder_offset ? [{ offset_minutes: Number(form.reminder_offset) }] : [],
  }
}

function taskToForm(task: Task): TaskFormState {
  return {
    title: task.title,
    description: task.description ?? '',
    category_uuid: task.category?.uuid ?? '',
    priority: task.priority,
    status: task.status,
    due_at: task.due_at ? format(new Date(task.due_at), "yyyy-MM-dd'T'HH:mm") : '',
    is_important: task.is_important,
    checklist: (task.checklists ?? []).map((c) => ({ title: c.title, is_done: c.is_done })),
    tags: (task.tags ?? []).join(', '),
    assignees: '',
    reminder_offset: '',
  }
}

export default function TasksPage() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<Task | null>(null)
  const [form, setForm] = useState<TaskFormState>(emptyForm)
  const [error, setError] = useState<string | null>(null)

  const filters = useMemo(() => {
    const f: Record<string, string> = {}
    for (const key of ['status', 'priority', 'category', 'important', 'overdue', 'q', 'assigned_to_me']) {
      const v = params.get(key)
      if (v) f[key] = v
    }
    return f
  }, [params])

  const { data, isLoading } = useQuery({
    queryKey: ['tasks', filters],
    queryFn: () => tasksApi.list(filters),
  })

  const { data: cats } = useQuery({ queryKey: ['categories'], queryFn: () => categoriesApi.list() })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tasks'] })
    queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      editing ? tasksApi.update(editing.uuid, payload) : tasksApi.create(payload),
    onSuccess: () => {
      invalidate()
      closeForm()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const statusMutation = useMutation({
    mutationFn: ({ uuid, status }: { uuid: string; status: string }) => tasksApi.setStatus(uuid, status),
    onSuccess: invalidate,
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => tasksApi.remove(uuid),
    onSuccess: invalidate,
  })

  const toggleMutation = useMutation({
    mutationFn: ({ uuid, flag }: { uuid: string; flag: 'pin' | 'favourite' | 'important' }) =>
      tasksApi.toggle(uuid, flag),
    onSuccess: invalidate,
  })

  const duplicateMutation = useMutation({
    mutationFn: (uuid: string) => tasksApi.duplicate(uuid),
    onSuccess: invalidate,
  })

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setError(null)
    setShowForm(true)
  }

  const openEdit = async (task: Task) => {
    const full = await tasksApi.get(task.uuid)
    setEditing(full)
    setForm(taskToForm(full))
    setError(null)
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditing(null)
    setForm(emptyForm)
  }

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  const taskList = data?.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold">My Tasks</h1>
        <Button onClick={openCreate}>
          <Plus className="size-4" /> New task
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Search tasks…"
          className="max-w-52"
          defaultValue={params.get('q') ?? ''}
          onKeyDown={(e) => e.key === 'Enter' && setFilter('q', (e.target as HTMLInputElement).value)}
        />
        <Select className="max-w-40" value={params.get('status') ?? ''} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">All statuses</option>
          {TASK_STATUSES.map((s) => (
            <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>
          ))}
        </Select>
        <Select className="max-w-40" value={params.get('priority') ?? ''} onChange={(e) => setFilter('priority', e.target.value)}>
          <option value="">All priorities</option>
          {TASK_PRIORITIES.map((p) => (
            <option key={p} value={p}>{p}</option>
          ))}
        </Select>
        <Select className="max-w-44" value={params.get('category') ?? ''} onChange={(e) => setFilter('category', e.target.value)}>
          <option value="">All categories</option>
          {cats?.map((c) => (
            <option key={c.uuid} value={c.uuid}>{c.name}</option>
          ))}
        </Select>
        <Button
          variant={params.get('important') ? 'primary' : 'secondary'}
          size="sm"
          onClick={() => setFilter('important', params.get('important') ? '' : '1')}
        >
          <Star className="size-3.5" /> Important
        </Button>
        <Button
          variant={params.get('overdue') ? 'danger' : 'secondary'}
          size="sm"
          onClick={() => setFilter('overdue', params.get('overdue') ? '' : '1')}
        >
          Overdue
        </Button>
      </div>

      {/* Task list */}
      {isLoading ? (
        <Spinner />
      ) : taskList.length === 0 ? (
        <Card>
          <EmptyState title="No tasks found" hint="Create a task or change your filters." />
        </Card>
      ) : (
        <div className="space-y-2">
          {taskList.map((task) => (
            <Card key={task.uuid} className={clsx('flex items-start gap-3 p-3', task.is_pinned && 'ring-1 ring-brand-300')}>
              <button
                className="mt-0.5 shrink-0 text-slate-400 hover:text-emerald-600"
                title={task.status === 'completed' ? 'Mark as not started' : 'Mark completed'}
                onClick={() =>
                  statusMutation.mutate({
                    uuid: task.uuid,
                    status: task.status === 'completed' ? 'not_started' : 'completed',
                  })
                }
              >
                {task.status === 'completed' ? (
                  <CheckCircle2 className="size-5 text-emerald-500" />
                ) : (
                  <Circle className="size-5" />
                )}
              </button>

              <button className="min-w-0 flex-1 text-left" onClick={() => openEdit(task)}>
                <div className="flex flex-wrap items-center gap-2">
                  <span className={clsx('text-sm font-medium', task.status === 'completed' && 'text-slate-400 line-through')}>
                    {task.title}
                  </span>
                  {task.is_important && <Star className="size-3.5 fill-amber-400 text-amber-400" />}
                  <Badge value={task.is_overdue ? 'overdue' : task.status} />
                  <Badge value={task.priority} />
                  {task.category && (
                    <span className="rounded-full px-2 py-0.5 text-[11px]" style={{ backgroundColor: (task.category.color ?? '#e2e8f0') + '22', color: task.category.color ?? undefined }}>
                      {task.category.name}
                    </span>
                  )}
                </div>
                <p className="mt-0.5 text-xs text-slate-400">
                  {task.due_at ? `Due ${format(new Date(task.due_at), 'd MMM yyyy, h:mm a')}` : 'No due date'}
                  {typeof task.progress === 'number' && task.progress > 0 ? ` · ${task.progress}%` : ''}
                  {task.assignees?.length ? ` · assigned to ${task.assignees.map((a) => a.name).join(', ')}` : ''}
                </p>
              </button>

              <div className="flex shrink-0 items-center gap-0.5">
                <button
                  className={clsx('rounded p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800', task.is_pinned ? 'text-brand-600' : 'text-slate-400')}
                  title="Pin"
                  onClick={() => toggleMutation.mutate({ uuid: task.uuid, flag: 'pin' })}
                >
                  <Pin className="size-4" />
                </button>
                <button
                  className="rounded p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                  title="Duplicate"
                  onClick={() => duplicateMutation.mutate(task.uuid)}
                >
                  <Copy className="size-4" />
                </button>
                <button
                  className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950"
                  title="Delete"
                  onClick={() => {
                    if (confirm(`Delete task "${task.title}"?`)) deleteMutation.mutate(task.uuid)
                  }}
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Create / edit modal */}
      {showForm && (
        <Modal title={editing ? 'Edit task' : 'New task'} onClose={closeForm} wide>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              setError(null)
              saveMutation.mutate(formToPayload(form))
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Title</Label>
              <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required autoFocus />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div>
                <Label>Category</Label>
                <Select value={form.category_uuid} onChange={(e) => setForm({ ...form, category_uuid: e.target.value })}>
                  <option value="">None</option>
                  {cats?.map((c) => (
                    <option key={c.uuid} value={c.uuid}>{c.name}</option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Priority</Label>
                <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                  {TASK_PRIORITIES.map((p) => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Status</Label>
                <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  {TASK_STATUSES.map((s) => (
                    <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Due date</Label>
                <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Reminder (minutes before due)</Label>
                <Select value={form.reminder_offset} onChange={(e) => setForm({ ...form, reminder_offset: e.target.value })}>
                  <option value="">No reminder</option>
                  {[5, 10, 15, 30, 60, 120, 1440, 2880, 10080].map((m) => (
                    <option key={m} value={m}>
                      {m < 60 ? `${m} minutes` : m < 1440 ? `${m / 60} hour(s)` : `${m / 1440} day(s)`} before
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Tags (comma separated)</Label>
                <Input value={form.tags} onChange={(e) => setForm({ ...form, tags: e.target.value })} placeholder="work, urgent" />
              </div>
            </div>

            <div>
              <Label>Assign to (App IDs, comma separated)</Label>
              <Input
                value={form.assignees}
                onChange={(e) => setForm({ ...form, assignees: e.target.value })}
                placeholder="MYPA-100005, MYPA-100006"
              />
            </div>

            {/* Checklist */}
            <div>
              <Label>Checklist</Label>
              <div className="space-y-2">
                {form.checklist.map((item, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={item.is_done ?? false}
                      onChange={(e) => {
                        const next = [...form.checklist]
                        next[i] = { ...item, is_done: e.target.checked }
                        setForm({ ...form, checklist: next })
                      }}
                    />
                    <Input
                      value={item.title}
                      onChange={(e) => {
                        const next = [...form.checklist]
                        next[i] = { ...item, title: e.target.value }
                        setForm({ ...form, checklist: next })
                      }}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setForm({ ...form, checklist: form.checklist.filter((_, j) => j !== i) })}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  </div>
                ))}
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => setForm({ ...form, checklist: [...form.checklist, { title: '' }] })}
                >
                  <Plus className="size-3.5" /> Add item
                </Button>
              </div>
            </div>

            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={form.is_important}
                onChange={(e) => setForm({ ...form, is_important: e.target.checked })}
              />
              Mark as important
            </label>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={closeForm}>
                Cancel
              </Button>
              <Button type="submit" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Create task'}
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
