import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CalendarOff, Video } from 'lucide-react'
import { crm } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { Avatar } from '../../lib/avatars'
import { Card, EmptyState, LoadError, SkeletonCards } from '../../components/ui'

/**
 * The company as it is right now.
 *
 * The dashboard answers how the month is going — invoices raised, money in,
 * leads by stage. None of it answers what an admin actually opens the app
 * asking on a Tuesday morning: who is here, and what is happening.
 *
 * In that order. The standing numbers come last because they are the only
 * part that would still be true tomorrow.
 */
export default function CrmOverviewPage() {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['crm', 'overview'],
    queryFn: crm.overview,
    // It is a "right now" screen; a stale one is worse than none.
    refetchInterval: 30_000,
  })

  if (isLoading) return <SkeletonCards count={3} />

  if (isError || !data) {
    return (
      <Card>
        <LoadError what="the company overview" message={errorMessage(error)} onRetry={() => refetch()} />
      </Card>
    )
  }

  const here = data.members.filter((m) => m.online || m.punched_in)

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold tracking-tight">Overview</h1>

      {/* Who is here. First, because it is the question being asked. */}
      <Card>
        <div className="mb-2 flex items-baseline justify-between">
          <h2 className="text-sm font-semibold">Active members</h2>
          <span className="text-xs text-slate-400">
            {here.length} of {data.members.length} here now
          </span>
        </div>

        {data.members.length === 0 ? (
          <EmptyState title="Nobody on the payroll yet" hint="Register an employee to see them here." />
        ) : (
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {data.members.map((m) => (
              <div
                key={m.uuid}
                className="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 p-2 dark:border-slate-700"
              >
                <div className="relative shrink-0">
                  <Avatar name={m.name} photoPath={m.photo_path} avatar={m.avatar} size={34} />
                  {m.online && (
                    <span
                      title="At their screen now"
                      className="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-emerald-500 dark:border-slate-900"
                    />
                  )}
                </div>
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{m.name}</p>
                  <p className="truncate text-xs text-slate-400">
                    {/*
                      * Two facts, not one. Punched in says they started their
                      * day; online says they are at the screen now. Somebody
                      * can be either without the other — punched in and gone
                      * to a site visit, or online on a day they never punched.
                      */}
                    {m.punched_in ? 'Punched in' : 'Not punched in'}
                    {m.online ? ' · online' : ''}
                    {m.employee_code ? ` · ${m.employee_code}` : ''}
                  </p>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {/* What is running. */}
      <Card>
        <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold">
          <Video className="size-4" /> Live meetings
        </h2>

        {data.meetings.length === 0 ? (
          <p className="py-3 text-center text-sm text-slate-400">Nothing running right now.</p>
        ) : (
          <div className="space-y-2">
            {data.meetings.map((m) => (
              <div
                key={m.uuid}
                className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{m.title || 'Meeting'}</p>
                  <p className="truncate text-xs text-slate-400">
                    {m.host ? `Hosted by ${m.host}` : 'Host unknown'} ·{' '}
                    {m.participants} {m.participants === 1 ? 'person' : 'people'}
                    {m.started_at ? ` · since ${new Date(m.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}
                  </p>
                </div>
                <Link
                  to={`/meetings/room/${m.code}`}
                  className="shrink-0 text-xs font-medium text-brand-600 hover:underline"
                >
                  Join
                </Link>
              </div>
            ))}
          </div>
        )}
      </Card>

      {/* The standing numbers — the part that would still be true tomorrow. */}
      <Card>
        <h2 className="mb-2 text-sm font-semibold">Today</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {[
            { label: 'Members', value: data.overview.members_active, hint: `${data.overview.members_total} on the books` },
            { label: 'Punched in today', value: data.overview.punched_in_today },
            { label: 'On leave today', value: data.overview.on_leave_today, icon: CalendarOff },
            { label: 'Active clients', value: data.overview.clients_active },
            { label: 'Open leads', value: data.overview.leads_open },
            { label: 'Approvals waiting', value: data.overview.approvals_pending },
          ].map((tile) => (
            <div key={tile.label} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
              <p className="text-2xl font-semibold tabular-nums">{tile.value}</p>
              <p className="text-xs text-slate-400">{tile.label}</p>
              {tile.hint && <p className="text-[11px] text-slate-400">{tile.hint}</p>}
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
