import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, CalendarClock, CheckCircle2, ListTodo, Star, TrendingUp } from 'lucide-react'
import { dashboard } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { Badge, Card, EmptyState, Spinner } from '../components/ui'
import type { Task } from '../types'
import { format } from 'date-fns'

function StatCard({
  label, value, icon: Icon, to, accent,
}: {
  label: string
  value: number | string
  icon: React.ComponentType<{ className?: string }>
  to?: string
  accent?: string
}) {
  const body = (
    // The number is the point of the card, so it is the biggest thing on it,
    // with the icon a quiet chip in the corner rather than half the tile.
    <Card className="group flex items-center gap-3 p-3.5 transition-shadow hover:shadow-lift sm:flex-col sm:items-start sm:gap-2.5 sm:p-4">
      <div className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${accent ?? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'}`}>
        <Icon className="size-[18px]" />
      </div>
      <div className="min-w-0">
        <p className="text-xl font-semibold leading-none tracking-tight sm:text-2xl">{value}</p>
        <p className="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{label}</p>
      </div>
    </Card>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

function TaskRow({ task }: { task: Task }) {
  return (
    <Link
      to={`/tasks?open=${task.uuid}`}
      className="-mx-2 flex items-center justify-between gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-slate-900/[0.03] dark:hover:bg-white/5"
    >
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">{task.title}</p>
        <p className="mt-0.5 text-xs text-slate-400">
          {task.due_at ? format(new Date(task.due_at), 'd MMM, h:mm a') : 'No due date'}
          {task.category ? ` · ${task.category.name}` : ''}
        </p>
      </div>
      <Badge value={task.is_overdue ? 'overdue' : task.status} />
    </Link>
  )
}

export default function Dashboard() {
  const user = useAuthStore((s) => s.user)
  const { data, isLoading } = useQuery({ queryKey: ['dashboard'], queryFn: dashboard.summary })

  if (isLoading || !data) return <Spinner />

  const { counts } = data

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          Hello, {user?.name?.split(' ')[0]} 👋
        </h1>
        <p className="text-sm text-slate-500">
          {counts.pending} pending · {counts.overdue} overdue · {counts.completion_rate}% completed overall
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Due today" value={counts.today} icon={CalendarClock} to="/tasks" />
        <StatCard
          label="Overdue"
          value={counts.overdue}
          icon={AlertTriangle}
          to="/tasks?overdue=1"
          accent="bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-300"
        />
        <StatCard
          label="Important"
          value={counts.important}
          icon={Star}
          to="/tasks?important=1"
          accent="bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300"
        />
        <StatCard label="Pending" value={counts.pending} icon={ListTodo} to="/tasks" />
        <StatCard
          label="Completed"
          value={counts.completed}
          icon={CheckCircle2}
          accent="bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300"
        />
        <StatCard label="Completion" value={`${counts.completion_rate}%`} icon={TrendingUp} />
      </div>

      {/*
        `grid-cols-1` is not decoration. Without an explicit track the column is
        sized from its content, and the content is a `truncate` title — which is
        `white-space: nowrap`, so its minimum width is the whole sentence. Each
        of these cards measured 476px on a 390px phone and the status badge sat
        off the screen. An explicit `minmax(0, 1fr)` track, and `min-w-0` on the
        cards so they honour it, is what lets the titles actually truncate.
      */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="min-w-0">
          <h2 className="mb-3 text-sm font-semibold">Today's tasks</h2>
          {data.today_tasks.length === 0 ? (
            <EmptyState title="Nothing due today" hint="Enjoy the calm or plan ahead." />
          ) : (
            <div className="space-y-0.5">{data.today_tasks.map((t) => <TaskRow key={t.uuid} task={t} />)}</div>
          )}
        </Card>
        <Card className="min-w-0">
          <h2 className="mb-3 text-sm font-semibold">Overdue</h2>
          {data.overdue_tasks.length === 0 ? (
            <EmptyState title="No overdue tasks" hint="You're all caught up." />
          ) : (
            <div className="space-y-0.5">{data.overdue_tasks.map((t) => <TaskRow key={t.uuid} task={t} />)}</div>
          )}
        </Card>
        <Card className="min-w-0">
          <h2 className="mb-3 text-sm font-semibold">Recently added</h2>
          {data.recent_tasks.length === 0 ? (
            <EmptyState title="No tasks yet" hint="Create your first task from My Tasks." />
          ) : (
            <div className="space-y-0.5">{data.recent_tasks.map((t) => <TaskRow key={t.uuid} task={t} />)}</div>
          )}
        </Card>
      </div>
    </div>
  )
}
