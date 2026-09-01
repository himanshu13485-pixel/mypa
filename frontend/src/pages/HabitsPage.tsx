import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Flame, Plus, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { badges as badgesApi, habits as habitsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, SkeletonCards } from '../components/ui'
import type { HabitItem } from '../types'

export default function HabitsPage() {
  const queryClient = useQueryClient()

  // Being here is the reminder answered.
  useEffect(() => {
    badgesApi.readKinds(['habit_reminder']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const { data, isLoading } = useQuery({ queryKey: ['habits'], queryFn: habitsApi.list })
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<HabitItem | null>(null)
  const [form, setForm] = useState({ name: '', description: '', frequency: 'daily', target_per_period: 1, color: '#22c55e' })
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['habits'] })

  const saveMutation = useMutation({
    mutationFn: () => (editing ? habitsApi.update(editing.uuid, form) : habitsApi.create(form)),
    onSuccess: () => {
      invalidate()
      close()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const logMutation = useMutation({
    mutationFn: ({ uuid, count }: { uuid: string; count?: number }) =>
      habitsApi.log(uuid, count !== undefined ? { count } : {}),
    onSuccess: invalidate,
  })

  const open = (habit?: HabitItem) => {
    setEditing(habit ?? null)
    setForm(habit
      ? {
          name: habit.name,
          description: habit.description ?? '',
          frequency: habit.frequency,
          target_per_period: habit.target_per_period,
          color: habit.color ?? '#22c55e',
        }
      : { name: '', description: '', frequency: 'daily', target_per_period: 1, color: '#22c55e' })
    setError(null)
    setShowForm(true)
  }

  const close = () => {
    setShowForm(false)
    setEditing(null)
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold tracking-tight">Habits</h1>
        <Button onClick={() => open()}>
          <Plus className="size-4" /> New habit
        </Button>
      </div>

      {isLoading ? (
        <SkeletonCards count={6} />
      ) : !data?.length ? (
        <Card>
          <EmptyState title="No habits yet" hint="Build routines — check them off daily and grow your streaks." />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {data.map((habit) => (
            <Card key={habit.uuid}>
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1 cursor-pointer" onClick={() => open(habit)}>
                  <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                    <span className="size-2.5 rounded-full" style={{ backgroundColor: habit.color ?? '#22c55e' }} />
                    {habit.name}
                  </h3>
                  <p className="mt-0.5 text-xs text-slate-400">
                    {habit.frequency}
                    {habit.target_per_period > 1 ? ` · target ${habit.target_per_period}×` : ''}
                    {' · '}{habit.total_completions} total
                  </p>
                </div>
                <div className="flex items-center gap-1 text-amber-500" title={`${habit.streak} ${habit.frequency === 'daily' ? 'day' : habit.frequency} streak`}>
                  <Flame className="size-4" />
                  <span className="text-sm font-bold tabular-nums">{habit.streak}</span>
                </div>
              </div>

              {/* 7-day strip */}
              <div className="mt-3 flex items-center gap-1">
                {habit.week.map((day) => (
                  <div
                    key={day.date}
                    title={`${day.date}: ${day.count}`}
                    className={clsx(
                      'h-6 flex-1 rounded',
                      day.count >= habit.target_per_period
                        ? 'bg-emerald-500'
                        : day.count > 0
                          ? 'bg-emerald-200 dark:bg-emerald-900'
                          : 'bg-slate-100 dark:bg-slate-800',
                    )}
                  />
                ))}
              </div>

              <div className="mt-3 flex items-center justify-between">
                <Button
                  size="sm"
                  variant={habit.done_today ? 'secondary' : 'primary'}
                  onClick={() =>
                    logMutation.mutate(
                      habit.done_today ? { uuid: habit.uuid, count: 0 } : { uuid: habit.uuid },
                    )
                  }
                >
                  <Check className="size-3.5" />
                  {habit.done_today ? 'Done today ✓' : habit.today_count > 0 ? `Log (${habit.today_count}/${habit.target_per_period})` : 'Mark done'}
                </Button>
                <button
                  className="rounded p-1.5 text-slate-400 hover:text-red-600"
                  onClick={() => {
                    if (confirm(`Delete habit "${habit.name}" and its history?`)) {
                      habitsApi.remove(habit.uuid).then(invalidate)
                    }
                  }}
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {showForm && (
        <Modal title={editing ? 'Edit habit' : 'New habit'} onClose={close}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              saveMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Morning yoga, Read 20 minutes…" required autoFocus />
            </div>
            <div>
              <Label>Description</Label>
              <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div>
                <Label>Frequency</Label>
                <Select value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value })}>
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                  <option value="monthly">Monthly</option>
                </Select>
              </div>
              <div>
                <Label>Times per period</Label>
                <Input
                  type="number"
                  min={1}
                  max={100}
                  value={form.target_per_period}
                  onChange={(e) => setForm({ ...form, target_per_period: Number(e.target.value) })}
                />
              </div>
              <div>
                <Label>Color</Label>
                <input
                  type="color"
                  value={form.color}
                  onChange={(e) => setForm({ ...form, color: e.target.value })}
                  className="h-9 w-full cursor-pointer rounded border border-slate-300 dark:border-slate-700"
                />
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={close}>Cancel</Button>
              <Button type="submit" disabled={saveMutation.isPending}>Save habit</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
