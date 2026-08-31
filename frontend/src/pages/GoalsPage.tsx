import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Target, Trash2 } from 'lucide-react'
import { format } from 'date-fns'
import { clsx } from 'clsx'
import { badges as badgesApi, goals as goalsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorNote,
  Input,
  Label,
  Modal,
  Select,
  SkeletonCards,
  Textarea,
} from '../components/ui'
import { GOAL_TYPES, type GoalItem } from '../types'

export default function GoalsPage() {
  const queryClient = useQueryClient()

  // Being here is the reminder answered.
  useEffect(() => {
    badgesApi.readKinds(['goal_reminder']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const { data, isLoading } = useQuery({ queryKey: ['goals'], queryFn: goalsApi.list })
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    title: '', description: '', type: 'personal', target_date: '', motivation: '',
    milestones: [] as { title: string }[],
  })
  const [milestoneDrafts, setMilestoneDrafts] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['goals'] })

  const createMutation = useMutation({
    mutationFn: () =>
      goalsApi.create({
        ...form,
        target_date: form.target_date || null,
        milestones: form.milestones.filter((m) => m.title.trim()),
      }),
    onSuccess: () => {
      invalidate()
      setShowForm(false)
      setForm({ title: '', description: '', type: 'personal', target_date: '', motivation: '', milestones: [] })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const active = data?.filter((g) => g.status === 'active') ?? []
  const finished = data?.filter((g) => g.status !== 'active') ?? []

  const goalCard = (goal: GoalItem) => (
    <Card key={goal.uuid}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{goal.title}</h3>
          <p className="mt-0.5 text-xs text-slate-400">
            <Badge value={goal.status} className="mr-1.5" />
            <span className="capitalize">{goal.type}</span>
            {goal.target_date ? ` · by ${format(new Date(goal.target_date), 'd MMM yyyy')}` : ''}
            {goal.group ? ` · ${goal.group.name}` : ''}
          </p>
        </div>
        {goal.is_own && (
          <button
            className="rounded p-1.5 text-slate-400 hover:text-red-600"
            onClick={() => {
              if (confirm(`Delete goal "${goal.title}"?`)) goalsApi.remove(goal.uuid).then(invalidate)
            }}
          >
            <Trash2 className="size-4" />
          </button>
        )}
      </div>

      {goal.motivation && <p className="mt-2 text-xs italic text-slate-500">“{goal.motivation}”</p>}

      {/* Progress */}
      <div className="mt-3 flex items-center gap-2">
        <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            className={clsx('h-full rounded-full', goal.status === 'completed' ? 'bg-emerald-500' : 'bg-brand-500')}
            style={{ width: `${goal.progress}%` }}
          />
        </div>
        <span className="text-xs font-medium tabular-nums text-slate-500">{goal.progress}%</span>
      </div>

      {/* Milestones */}
      <div className="mt-3 space-y-1">
        {goal.milestones.map((m) => (
          <label key={m.id} className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={m.is_done}
              disabled={!goal.is_own}
              onChange={() => goalsApi.toggleMilestone(goal.uuid, m.id).then(invalidate)}
            />
            <span className={clsx(m.is_done && 'text-slate-400 line-through')}>{m.title}</span>
            {m.due_on && <span className="text-[11px] text-slate-400">({format(new Date(m.due_on), 'd MMM')})</span>}
            {goal.is_own && (
              <button
                type="button"
                className="ml-auto text-slate-300 hover:text-red-500"
                onClick={() => goalsApi.removeMilestone(goal.uuid, m.id).then(invalidate)}
              >
                <Trash2 className="size-3" />
              </button>
            )}
          </label>
        ))}
        {goal.is_own && goal.status === 'active' && (
          <Input
            className="mt-1"
            placeholder="Add a milestone and press Enter…"
            value={milestoneDrafts[goal.uuid] ?? ''}
            onChange={(e) => setMilestoneDrafts({ ...milestoneDrafts, [goal.uuid]: e.target.value })}
            onKeyDown={(e) => {
              const draft = milestoneDrafts[goal.uuid]?.trim()
              if (e.key === 'Enter' && draft) {
                e.preventDefault()
                goalsApi.addMilestone(goal.uuid, draft).then(() => {
                  setMilestoneDrafts({ ...milestoneDrafts, [goal.uuid]: '' })
                  invalidate()
                })
              }
            }}
          />
        )}
      </div>
    </Card>
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Target className="size-5 text-brand-600" /> Goals
        </h1>
        <Button onClick={() => { setError(null); setShowForm(true) }}>
          <Plus className="size-4" /> New goal
        </Button>
      </div>

      {isLoading ? (
        <SkeletonCards count={4} />
      ) : !data?.length ? (
        <Card>
          <EmptyState title="No goals yet" hint="Set a goal, break it into milestones, and track your progress." />
        </Card>
      ) : (
        <>
          <div className="grid gap-3 lg:grid-cols-2">{active.map(goalCard)}</div>
          {finished.length > 0 && (
            <section>
              <h2 className="mb-2 text-sm font-semibold text-slate-500">Completed & past goals</h2>
              <div className="grid gap-3 lg:grid-cols-2">{finished.map(goalCard)}</div>
            </section>
          )}
        </>
      )}

      {showForm && (
        <Modal title="New goal" onClose={() => setShowForm(false)} wide>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              createMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Goal title</Label>
              <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Run a half marathon" required autoFocus />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Type</Label>
                <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                  {GOAL_TYPES.map((t) => (
                    <option key={t} value={t}>{t}</option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Target date</Label>
                <Input type="date" value={form.target_date} onChange={(e) => setForm({ ...form, target_date: e.target.value })} />
              </div>
            </div>
            <div>
              <Label>Why this goal? (motivation)</Label>
              <Input value={form.motivation} onChange={(e) => setForm({ ...form, motivation: e.target.value })} />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div>
              <Label>Milestones</Label>
              <div className="space-y-2">
                {form.milestones.map((m, i) => (
                  <div key={i} className="flex gap-2">
                    <Input
                      value={m.title}
                      onChange={(e) => {
                        const next = [...form.milestones]
                        next[i] = { title: e.target.value }
                        setForm({ ...form, milestones: next })
                      }}
                    />
                    <Button type="button" variant="ghost" size="sm" onClick={() => setForm({ ...form, milestones: form.milestones.filter((_, j) => j !== i) })}>
                      <Trash2 className="size-4" />
                    </Button>
                  </div>
                ))}
                <Button type="button" variant="secondary" size="sm" onClick={() => setForm({ ...form, milestones: [...form.milestones, { title: '' }] })}>
                  <Plus className="size-3.5" /> Add milestone
                </Button>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
              <Button type="submit" disabled={createMutation.isPending}>Create goal</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
