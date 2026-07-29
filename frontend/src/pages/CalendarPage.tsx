import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import {
  addMonths, eachDayOfInterval, endOfMonth, endOfWeek, format, isSameDay,
  isSameMonth, isToday, startOfMonth, startOfWeek,
} from 'date-fns'
import { clsx } from 'clsx'
import { tasks as tasksApi } from '../api/endpoints'
import { Button, Card, Spinner } from '../components/ui'

export default function CalendarPage() {
  const [month, setMonth] = useState(() => startOfMonth(new Date()))

  const range = useMemo(
    () => ({
      from: format(startOfWeek(startOfMonth(month), { weekStartsOn: 1 }), 'yyyy-MM-dd'),
      to: format(endOfWeek(endOfMonth(month), { weekStartsOn: 1 }), 'yyyy-MM-dd'),
    }),
    [month],
  )

  const { data, isLoading } = useQuery({
    queryKey: ['calendar-tasks', range],
    queryFn: () => tasksApi.list({ date_from: range.from, date_to: range.to, per_page: 100 }),
  })

  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(month), { weekStartsOn: 1 }),
    end: endOfWeek(endOfMonth(month), { weekStartsOn: 1 }),
  })

  const tasksByDay = useMemo(() => {
    const map = new Map<string, typeof data extends undefined ? never[] : NonNullable<typeof data>['data']>()
    for (const task of data?.data ?? []) {
      if (!task.due_at) continue
      const key = format(new Date(task.due_at), 'yyyy-MM-dd')
      if (!map.has(key)) map.set(key, [] as never)
      ;(map.get(key) as unknown[]).push(task)
    }
    return map
  }, [data])

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Calendar</h1>
        <div className="flex items-center gap-2">
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
        <Spinner />
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
              const dayTasks = (tasksByDay.get(key) ?? []) as { uuid: string; title: string; color?: string | null; category?: { color?: string } | null; status: string }[]
              return (
                <div
                  key={key}
                  className={clsx(
                    'min-h-24 border-b border-r border-slate-100 p-1.5 dark:border-slate-800',
                    !isSameMonth(day, month) && 'bg-slate-50/60 dark:bg-slate-950/40',
                  )}
                >
                  <span
                    className={clsx(
                      'inline-flex size-6 items-center justify-center rounded-full text-xs',
                      isToday(day) && 'bg-brand-600 font-semibold text-white',
                      !isToday(day) && isSameDay(day, new Date()) && 'font-semibold',
                    )}
                  >
                    {format(day, 'd')}
                  </span>
                  <div className="mt-1 space-y-1">
                    {dayTasks.slice(0, 3).map((t) => (
                      <div
                        key={t.uuid}
                        className={clsx(
                          'truncate rounded px-1.5 py-0.5 text-[11px]',
                          t.status === 'completed'
                            ? 'bg-slate-100 text-slate-400 line-through dark:bg-slate-800'
                            : 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300',
                        )}
                        title={t.title}
                      >
                        {t.title}
                      </div>
                    ))}
                    {dayTasks.length > 3 && (
                      <p className="px-1 text-[10px] text-slate-400">+{dayTasks.length - 3} more</p>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      )}
    </div>
  )
}
