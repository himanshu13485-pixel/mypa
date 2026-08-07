import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { format } from 'date-fns'
import { reports } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { Button, Card, Spinner } from '../components/ui'

function StatTile({ label, value, suffix }: { label: string; value: number | string; suffix?: string }) {
  return (
    <Card>
      <p className="text-2xl font-semibold">
        {value}
        {suffix && <span className="text-sm font-normal text-slate-400"> {suffix}</span>}
      </p>
      <p className="text-xs text-slate-500">{label}</p>
    </Card>
  )
}

/** Horizontal magnitude bars: one measure, one hue, direct-labeled values. */
function BarList({ rows }: { rows: { label: string; value: number; done?: number }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  return (
    <div className="space-y-2">
      {rows.map((row) => (
        <div key={row.label} className="group flex items-center gap-2" title={`${row.label}: ${row.value}${row.done !== undefined ? ` (${row.done} completed)` : ''}`}>
          <span className="w-28 shrink-0 truncate text-xs text-slate-600 dark:text-slate-300">{row.label}</span>
          <div className="h-4 flex-1">
            <div
              className="h-2 translate-y-1 rounded-r bg-brand-500 transition-opacity group-hover:opacity-80"
              style={{ width: `${(row.value / max) * 100}%`, minWidth: row.value > 0 ? 4 : 0 }}
            />
          </div>
          <span className="w-8 shrink-0 text-right text-xs tabular-nums text-slate-500">{row.value}</span>
        </div>
      ))}
    </div>
  )
}

export default function ReportsPage() {
  const [days, setDays] = useState(30)
  const { data: summary, isLoading } = useQuery({ queryKey: ['report-summary'], queryFn: reports.summary })
  const { data: productivity } = useQuery({
    queryKey: ['report-productivity', days],
    queryFn: () => reports.productivity(days),
  })

  const downloadCsv = async () => {
    const token = useAuthStore.getState().token
    const res = await fetch(reports.csvUrl, { headers: { Authorization: `Bearer ${token}` } })
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'mypa-tasks.csv'
    a.click()
    URL.revokeObjectURL(url)
  }

  if (isLoading || !summary) return <Spinner />

  const { totals } = summary
  const maxCompleted = Math.max(1, ...(productivity ?? []).map((d) => d.completed))

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold tracking-tight">Reports</h1>
        <Button variant="secondary" size="sm" onClick={downloadCsv}>
          <Download className="size-4" /> Export CSV
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatTile label="Total tasks" value={totals.total} />
        <StatTile label="Completed" value={totals.completed} />
        <StatTile label="Pending" value={totals.pending} />
        <StatTile label="Overdue" value={totals.overdue} />
        <StatTile label="Completion rate" value={totals.completion_rate} suffix="%" />
        <StatTile
          label="Avg completion time"
          value={totals.avg_completion_hours !== null ? totals.avg_completion_hours : '—'}
          suffix={totals.avg_completion_hours !== null ? 'hrs' : undefined}
        />
      </div>

      {/* Tasks completed per day — single series, so the title names it (no legend). */}
      <Card>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold">Tasks completed per day</h2>
          <div className="flex gap-1">
            {[7, 30, 90].map((option) => (
              <Button
                key={option}
                size="sm"
                variant={days === option ? 'primary' : 'ghost'}
                onClick={() => setDays(option)}
              >
                {option}d
              </Button>
            ))}
          </div>
        </div>
        <div className="flex h-32 items-end gap-px overflow-hidden">
          {(productivity ?? []).map((day) => (
            <div
              key={day.date}
              className="group flex h-full flex-1 flex-col justify-end"
              title={`${format(new Date(day.date), 'd MMM')}: ${day.completed} completed, ${day.created} created`}
            >
              <div
                className="mx-auto w-full max-w-3 rounded-t bg-brand-500 transition-opacity group-hover:opacity-70"
                style={{ height: `${(day.completed / maxCompleted) * 100}%`, minHeight: day.completed > 0 ? 3 : 0 }}
              />
            </div>
          ))}
        </div>
        <div className="mt-1 flex justify-between text-[10px] text-slate-400">
          <span>{productivity?.length ? format(new Date(productivity[0].date), 'd MMM') : ''}</span>
          <span>{productivity?.length ? format(new Date(productivity[productivity.length - 1].date), 'd MMM') : ''}</span>
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-3 text-sm font-semibold">Tasks by category</h2>
          {summary.by_category.length === 0 ? (
            <p className="text-xs text-slate-400">No tasks yet.</p>
          ) : (
            <BarList
              rows={summary.by_category
                .slice()
                .sort((a, b) => b.total - a.total)
                .slice(0, 10)
                .map((c) => ({ label: c.name, value: c.total, done: c.completed }))}
            />
          )}
        </Card>

        <Card>
          <h2 className="mb-3 text-sm font-semibold">Tasks by priority</h2>
          <BarList
            rows={['critical', 'urgent', 'high', 'medium', 'normal', 'low']
              .filter((p) => (summary.by_priority[p] ?? 0) > 0 || true)
              .map((p) => ({ label: p, value: summary.by_priority[p] ?? 0 }))}
          />
        </Card>
      </div>

      <Card>
        <h2 className="mb-3 text-sm font-semibold">Tasks by status</h2>
        <BarList
          rows={Object.entries(summary.by_status)
            .sort((a, b) => b[1] - a[1])
            .map(([status, count]) => ({ label: status.replaceAll('_', ' '), value: count }))}
        />
      </Card>
    </div>
  )
}
