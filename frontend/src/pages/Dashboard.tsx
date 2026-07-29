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
    <Card className="flex items-center gap-3 transition-shadow hover:shadow-md">
      <div className={`flex size-10 items-center justify-center rounded-lg ${accent ?? 'bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300'}`}>
        <Icon className="size-5" />
      </div>
      <div>
        <p className="text-xl font-semibold leading-tight">{value}</p>
        <p className="text-xs text-slate-500">{label}</p>
      </div>
    </Card>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

function TaskRow({ task }: { task: Task }) {
  return (
    <Link
      to={`/tasks?open=${task.uuid}`}
      className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800"
    >
      <div className="min-w-0">
        <p className="truncate text-sm">{task.title}</p>
        <p className="text-xs text-slate-400">
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
        <h1 className="text-lg font-semibold">
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

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <h2 className="mb-2 text-sm font-semibold">Today's tasks</h2>
          {data.today_tasks.length === 0 ? (
            <EmptyState title="Nothing due today" hint="Enjoy the calm or plan ahead." />
          ) : (
            <div className="space-y-0.5">{data.today_tasks.map((t) => <TaskRow key={t.uuid} task={t} />)}</div>
          )}
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold">Overdue</h2>
          {data.overdue_tasks.length === 0 ? (
            <EmptyState title="No overdue tasks" hint="You're all caught up." />
          ) : (
            <div className="space-y-0.5">{data.overdue_tasks.map((t) => <TaskRow key={t.uuid} task={t} />)}</div>
          )}
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold">Recently added</h2>
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
