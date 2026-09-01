import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Download, Plus, Trash2 } from 'lucide-react'
import {
  addMonths, eachDayOfInterval, endOfMonth, endOfWeek, format, isSameMonth,
  isToday, startOfMonth, startOfWeek,
} from 'date-fns'
import { clsx } from 'clsx'
import { Link } from 'react-router-dom'
import { badges as badgesApi, events as eventsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { toInstant } from '../lib/localTime'
import UserSuggest from '../components/UserSuggest'
import {
  Button,
  Card,
  ErrorNote,
  Input,
  Label,
  Modal,
  Select,
  Skeleton,
  SkeletonList,
  Textarea,
} from '../components/ui'
import { useIsPhone } from '../lib/useMediaQuery'
import { EVENT_TYPES, type CalendarEvent, type CalendarFeedTask } from '../types'

interface EventFormState {
  title: string
  description: string
  type: string
  starts_at: string
  ends_at: string
  all_day: boolean
  location: string
  meeting_link: string
  participants: string
}

const emptyEvent = (date?: Date): EventFormState => ({
  title: '',
  description: '',
  type: 'event',
  starts_at: date ? format(date, "yyyy-MM-dd'T'09:00") : '',
  ends_at: '',
  all_day: false,
  location: '',
  meeting_link: '',
  participants: '',
})

export default function CalendarPage() {
  const queryClient = useQueryClient()

  // Attending the calendar clears its invitations and replies.
  useEffect(() => {
    badgesApi.readKinds(['event_invite', 'event_response']).then(() => {
      queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
    }).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const isPhone = useIsPhone()
  const [month, setMonth] = useState(() => startOfMonth(new Date()))
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CalendarEvent | null>(null)
  const [form, setForm] = useState<EventFormState>(emptyEvent())
  const [error, setError] = useState<string | null>(null)

  const range = useMemo(
    () => ({
      from: format(startOfWeek(startOfMonth(month), { weekStartsOn: 1 }), 'yyyy-MM-dd'),
      to: format(endOfWeek(endOfMonth(month), { weekStartsOn: 1 }), 'yyyy-MM-dd'),
    }),
    [month],
  )

  const { data, isLoading } = useQuery({
    queryKey: ['calendar-feed', range],
    queryFn: () => eventsApi.feed(range.from, range.to),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['calendar-feed'] })

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        description: form.description || null,
        type: form.type,
        // An unambiguous instant, so the server never has to guess which
        // 14:00 was meant from a timezone saved on the account.
        starts_at: toInstant(form.starts_at),
        ends_at: toInstant(form.ends_at),
        all_day: form.all_day,
        location: form.location || null,
        meeting_link: form.meeting_link || null,
        participants: form.participants.split(',').map((p) => p.trim()).filter(Boolean),
      }
      return editing ? eventsApi.update(editing.uuid, payload) : eventsApi.create(payload)
    },
    onSuccess: () => {
      invalidate()
      close()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => eventsApi.remove(uuid),
    onSuccess: () => {
      invalidate()
      close()
    },
  })

  const openCreate = (date?: Date) => {
    setEditing(null)
    setForm(emptyEvent(date))
    setError(null)
    setShowForm(true)
  }

  const openEdit = (event: CalendarEvent) => {
    setEditing(event)
    setForm({
      title: event.title,
      description: event.description ?? '',
      type: event.type,
      starts_at: format(new Date(event.starts_at), "yyyy-MM-dd'T'HH:mm"),
      ends_at: event.ends_at ? format(new Date(event.ends_at), "yyyy-MM-dd'T'HH:mm") : '',
      all_day: event.all_day,
      location: event.location ?? '',
      meeting_link: event.meeting_link ?? '',
      participants: '',
    })
    setError(null)
    setShowForm(true)
  }

  const close = () => {
    setShowForm(false)
    setEditing(null)
  }

  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(month), { weekStartsOn: 1 }),
    end: endOfWeek(endOfMonth(month), { weekStartsOn: 1 }),
  })

  const itemsByDay = useMemo(() => {
    const map = new Map<string, { events: CalendarEvent[]; tasks: CalendarFeedTask[] }>()
    const bucket = (key: string) => {
      if (!map.has(key)) map.set(key, { events: [], tasks: [] })
      return map.get(key)!
    }
    for (const event of data?.events ?? []) {
      bucket(format(new Date(event.starts_at), 'yyyy-MM-dd')).events.push(event)
    }
    for (const task of data?.tasks ?? []) {
      bucket(format(new Date(task.starts_at), 'yyyy-MM-dd')).tasks.push(task)
    }
    return map
  }, [data])

  const downloadIcs = async () => {
    const token = JSON.parse(localStorage.getItem('mypa-auth') ?? '{}')?.state?.token
    const res = await fetch(eventsApi.icsUrl, { headers: { Authorization: `Bearer ${token}` } })
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'mypa-calendar.ics'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold tracking-tight">Calendar</h1>
        <div className="flex items-center gap-2">
          <Button variant="secondary" size="sm" onClick={downloadIcs} title="Export .ics">
            <Download className="size-4" /> Export
          </Button>
          <Button size="sm" onClick={() => openCreate(new Date())}>
            <Plus className="size-4" /> New event
          </Button>
        </div>
        {/* Month stepping is its own row on a phone. Kept beside the buttons it
            needed 407px of a 390px screen, and the arrows fell off the edge. */}
        <div className="flex w-full items-center justify-between gap-2 sm:w-auto">
          <Button variant="secondary" size="sm" onClick={() => setMonth(addMonths(month, -1))}>
            <ChevronLeft className="size-4" />
          </Button>
          <span className="min-w-32 text-center text-sm font-medium">{format(month, 'MMMM yyyy')}</span>
          <Button variant="secondary" size="sm" onClick={() => setMonth(addMonths(month, 1))}>
            <ChevronRight className="size-4" />
          </Button>
        </div>
      </div>

      {isLoading ? (
        /* The month, or the agenda it becomes on a phone — the same fork the
           real view makes below, so the placeholder is never the wrong shape
           for the screen it is on. */
        <Card>
          <div className="hidden grid-cols-7 gap-1 sm:grid">
            {Array.from({ length: 35 }, (_, i) => (
              <Skeleton key={i} className="h-16 rounded-lg" />
            ))}
          </div>
          <div className="sm:hidden">
            <SkeletonList rows={5} avatar={false} />
          </div>
        </Card>
      ) : isPhone ? (
        /* A 7-column month needs 640px, so on a phone it becomes a sideways
           pan through 21px chips. An agenda reads the way a phone calendar
           should: this month's days that actually have something on them. */
        <Card className="p-0">
          {days.filter((day) => isSameMonth(day, month) && itemsByDay.has(format(day, 'yyyy-MM-dd'))).length === 0 ? (
            <p className="px-4 py-10 text-center text-sm text-slate-400">
              Nothing scheduled in {format(month, 'MMMM')}.
            </p>
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {days
                .filter((day) => isSameMonth(day, month) && itemsByDay.has(format(day, 'yyyy-MM-dd')))
                .map((day) => {
                  const key = format(day, 'yyyy-MM-dd')
                  const items = itemsByDay.get(key)!
                  return (
                    <li key={key} className="flex gap-3 px-3 py-2.5">
                      <div className="w-11 shrink-0 text-center">
                        <p className="text-[10px] uppercase text-slate-400">{format(day, 'EEE')}</p>
                        <p className={clsx(
                          'mx-auto flex size-8 items-center justify-center rounded-full text-sm font-semibold',
                          isToday(day) && 'bg-brand-600 text-white',
                        )}>
                          {format(day, 'd')}
                        </p>
                      </div>
                      <div className="min-w-0 flex-1 space-y-1">
                        {items.events.map((event) => (
                          <button
                            key={event.uuid}
                            className="tap flex w-full items-center gap-2 rounded-lg bg-violet-50 px-2.5 py-2 text-left text-sm text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                            style={event.color ? { backgroundColor: event.color + '22', color: event.color } : undefined}
                            onClick={() => openEdit(event)}
                          >
                            <span className="shrink-0 text-xs tabular-nums opacity-70">
                              {event.all_day ? 'All day' : format(new Date(event.starts_at), 'HH:mm')}
                            </span>
                            <span className="min-w-0 flex-1 truncate">{event.title}</span>
                          </button>
                        ))}
                        {items.tasks.map((task) => (
                          <Link
                            key={task.uuid}
                            to={`/tasks?open=${task.uuid}`}
                            className={clsx(
                              'tap flex items-center rounded-lg px-2.5 py-2 text-sm',
                              task.status === 'completed'
                                ? 'bg-slate-100 text-slate-400 line-through dark:bg-slate-800'
                                : 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300',
                            )}
                          >
                            <span className="truncate">{task.title}</span>
                          </Link>
                        ))}
                      </div>
                    </li>
                  )
                })}
            </ul>
          )}
        </Card>
      ) : (
        <Card className="overflow-x-auto p-0">
          <div className="grid min-w-[640px] grid-cols-7 border-b border-slate-200 text-center text-xs font-medium text-slate-500 dark:border-slate-800">
            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
              <div key={d} className="py-2">{d}</div>
            ))}
          </div>
          <div className="grid min-w-[640px] grid-cols-7">
            {days.map((day) => {
              const key = format(day, 'yyyy-MM-dd')
              const items = itemsByDay.get(key)
              return (
                <div
                  key={key}
                  className={clsx(
                    'group min-h-24 border-b border-r border-slate-100 p-1.5 dark:border-slate-800',
                    !isSameMonth(day, month) && 'bg-slate-50/60 dark:bg-slate-950/40',
                  )}
                  onDoubleClick={() => openCreate(day)}
                >
                  <div className="flex items-center justify-between">
                    <span
                      className={clsx(
                        'inline-flex size-6 items-center justify-center rounded-full text-xs',
                        isToday(day) && 'bg-brand-600 font-semibold text-white',
                      )}
                    >
                      {format(day, 'd')}
                    </span>
                    <button
                      className="hidden rounded p-0.5 text-slate-300 hover:text-brand-600 group-hover:block"
                      onClick={() => openCreate(day)}
                      title="Add event"
                    >
                      <Plus className="size-3.5" />
                    </button>
                  </div>
                  <div className="mt-1 space-y-1">
                    {items?.events.slice(0, 2).map((event) => (
                      <button
                        key={event.uuid}
                        className="block w-full truncate rounded bg-violet-100 px-1.5 py-0.5 text-left text-[11px] text-violet-700 hover:bg-violet-200 dark:bg-violet-950 dark:text-violet-300"
                        style={event.color ? { backgroundColor: event.color + '22', color: event.color } : undefined}
                        title={`${event.title}${event.location ? ' · ' + event.location : ''}`}
                        onClick={() => openEdit(event)}
                      >
                        {event.all_day ? '' : format(new Date(event.starts_at), 'HH:mm ')}
                        {event.title}
                      </button>
                    ))}
                    {items?.tasks.slice(0, 2).map((task) => (
                      <Link
                        key={task.uuid}
                        to={`/tasks?open=${task.uuid}`}
                        className={clsx(
                          'block truncate rounded px-1.5 py-0.5 text-[11px]',
                          task.status === 'completed'
                            ? 'bg-slate-100 text-slate-400 line-through dark:bg-slate-800'
                            : 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300',
                        )}
                        title={task.title}
                      >
                        {task.title}
                      </Link>
                    ))}
                    {((items?.events.length ?? 0) > 2 || (items?.tasks.length ?? 0) > 2) && (
                      <p className="px-1 text-[10px] text-slate-400">
                        +{Math.max(0, (items?.events.length ?? 0) - 2) + Math.max(0, (items?.tasks.length ?? 0) - 2)} more
                      </p>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      )}

      {showForm && (
        <Modal title={editing ? 'Edit event' : 'New event'} onClose={close} wide>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              setError(null)
              saveMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-2">
                <Label>Title</Label>
                <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required autoFocus />
              </div>
              <div>
                <Label>Type</Label>
                <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                  {EVENT_TYPES.map((t) => (
                    <option key={t} value={t}>{t}</option>
                  ))}
                </Select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Starts</Label>
                <Input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} required />
              </div>
              <div>
                <Label>Ends</Label>
                <Input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Location</Label>
                <Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
              </div>
              <div>
                <Label>Meeting link</Label>
                <Input type="url" placeholder="https://…" value={form.meeting_link} onChange={(e) => setForm({ ...form, meeting_link: e.target.value })} />
              </div>
            </div>
            <div>
              <Label>Description</Label>
              <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div>
              <Label>Invite participants (usernames/emails, comma separated)</Label>
              <UserSuggest multi placeholder="rahul, priya@mypa.local" value={form.participants} onChange={(v) => setForm({ ...form, participants: v })} />
              {editing && editing.participants.length > 0 && (
                <p className="mt-1 text-xs text-slate-400">
                  Invited: {editing.participants.map((p) => `${p.name} (${p.status})`).join(', ')}
                </p>
              )}
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={form.all_day} onChange={(e) => setForm({ ...form, all_day: e.target.checked })} />
              All-day event
            </label>
            <div className="flex justify-between gap-2">
              {editing?.is_own ? (
                <Button
                  type="button"
                  variant="danger"
                  onClick={() => {
                    if (confirm(`Delete event "${editing.title}"?`)) deleteMutation.mutate(editing.uuid)
                  }}
                >
                  <Trash2 className="size-4" /> Delete
                </Button>
              ) : (
                <span />
              )}
              <div className="flex gap-2">
                <Button type="button" variant="secondary" onClick={close}>
                  Cancel
                </Button>
                <Button type="submit" disabled={saveMutation.isPending}>
                  {saveMutation.isPending ? 'Saving…' : 'Save event'}
                </Button>
              </div>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
