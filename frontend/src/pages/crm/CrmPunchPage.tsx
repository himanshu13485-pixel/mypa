import { useEffect, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Fingerprint, LogIn, LogOut } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, CRM_PUNCH_STATUS_LABELS, type CrmMe, type CrmPunchRow } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'

const STATUS_COLORS: Record<string, string> = {
  present: CHART_COLORS[0],
  late: CHART_COLORS[2],
  half_day: CHART_COLORS[1],
  holiday: CHART_COLORS[3],
  sunday: '#64748b',
  absent: CHART_COLORS[4],
}

export function punchBadge(status: string) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    status === 'present' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    status === 'late' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    status === 'half_day' && 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    status === 'leave' && 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    status === 'holiday' && 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
    (status === 'sunday' || status === 'week_off') && 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
    status === 'absent' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
  )
}

function Clock() {
  const [now, setNow] = useState(new Date())
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(id)
  }, [])
  return <span className="font-mono text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">{now.toLocaleTimeString('en-GB')}</span>
}

export default function CrmPunchPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  // Attendance is personal, like salary: only the Admin/Subadmin read
  // the company's rows; everyone else sees their own days.
  const teamView = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const canOverride = crmCan(me, 'punch', 'edit')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const monthStart = new Date().toISOString().slice(0, 8) + '01'
  const [dateFrom, setDateFrom] = useState(monthStart)
  const [dateTo, setDateTo] = useState(new Date().toISOString().slice(0, 10))
  const [member, setMember] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data: today } = useQuery({ queryKey: ['crm', 'punch', 'today'], queryFn: crm.punch.today })
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters, enabled: teamView })
  const { data: report, isLoading } = useQuery({
    queryKey: ['crm', 'punch', 'report', dateFrom, dateTo, member, status, page],
    queryFn: () =>
      crm.punch.list({
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        member: member || undefined,
        status: status || undefined,
        page,
      }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'punch'] })

  const inMutation = useMutation({
    mutationFn: crm.punch.punchIn,
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })
  const outMutation = useMutation({
    mutationFn: crm.punch.punchOut,
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })
  const overrideMutation = useMutation({
    mutationFn: ({ row, newStatus }: { row: CrmPunchRow; newStatus: string }) => crm.punch.override(row, newStatus),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const punch = today?.punch

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Punch report</h1>
        <p className="text-sm text-slate-500">
          Office opens {today?.config.start ?? '10:00'} with {today?.config.grace_minutes ?? 15} min grace — the server stamps the time and IP.
        </p>
      </div>

      {/* My punch card */}
      <Card className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <div className="rounded-2xl bg-emerald-50 p-3 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
            <Fingerprint className="size-8" />
          </div>
          <div>
            <Clock />
            <div className="mt-0.5 text-sm text-slate-500">
              {punch?.punch_in ? <>In at <span className="font-medium">{punch.punch_in}</span></> : 'Not punched in yet'}
              {punch?.punch_out && <> · out at <span className="font-medium">{punch.punch_out}</span> ({punch.hours} h)</>}
              {punch && <span className={clsx('ml-2', punchBadge(punch.status))}>{CRM_PUNCH_STATUS_LABELS[punch.status]}</span>}
            </div>
          </div>
        </div>
        <div className="flex gap-2">
          <Button onClick={() => inMutation.mutate()} disabled={!!punch?.punch_in || inMutation.isPending}>
            <LogIn className="size-4" /> Punch in
          </Button>
          <Button variant="secondary" onClick={() => outMutation.mutate()} disabled={!punch?.punch_in || !!punch?.punch_out || outMutation.isPending}>
            <LogOut className="size-4" /> Punch out
          </Button>
        </div>
      </Card>

      {/* Charts */}
      {report && report.summary.statuses.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Attendance mix</h2>
            <DonutChart
              data={report.summary.statuses.map((s) => ({
                label: CRM_PUNCH_STATUS_LABELS[s.status] ?? s.status,
                value: s.count,
                color: STATUS_COLORS[s.status],
              }))}
              centerLabel="days"
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Late marks by person</h2>
            <HBarChart
              data={report.summary.members
                .filter((m) => m.late > 0)
                .map((m) => ({ label: m.name ?? '—', value: m.late }))}
              color={STATUS_COLORS.late}
            />
            {report.summary.members.every((m) => m.late === 0) && (
              <p className="py-4 text-center text-sm text-emerald-600">Nobody late in this range 🎉</p>
            )}
          </Card>
        </div>
      )}

      {/* Report */}
      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto text-sm font-semibold text-slate-800 dark:text-slate-100">Daily punches</h2>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To" />
          {teamView && (
            <Select value={member} onChange={(e) => { setMember(e.target.value); setPage(1) }}>
              <option value="">Everyone</option>
              {masters?.members.filter((m) => (m.crm_role ?? 'employee') !== 'admin')
                .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
          )}
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {Object.entries(CRM_PUNCH_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !report || report.data.length === 0 ? (
          <EmptyState title="No punches in this range" hint="Punch-ins appear here as they happen." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[880px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Date</th>
                  {teamView && <th className="py-2 pr-3 font-medium">Employee</th>}
                  <th className="py-2 pr-3 font-medium">In</th>
                  <th className="py-2 pr-3 font-medium">Out</th>
                  <th className="py-2 pr-3 text-right font-medium">Hours</th>
                  <th className="py-2 pr-3 font-medium">In IP</th>
                  <th className="py-2 pr-3 font-medium">Out IP</th>
                  <th className="py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {report.data.map((p) => (
                  <tr key={`${p.member?.uuid}-${p.work_date}`} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      {p.work_date}
                      {/* Why the day reads as it does, when nobody punched. */}
                      {(p.holiday_name || p.leave_category) && (
                        <div className="text-xs text-slate-400">{p.holiday_name ?? p.leave_category}</div>
                      )}
                    </td>
                    {teamView && (
                      <td className="py-2.5 pr-3">
                        <div>{p.member?.name ?? '—'}</div>
                        {/* Which account punched, not only whose name it is. */}
                        {p.member?.login && (
                          <div className="text-xs text-slate-400">{p.member.login}</div>
                        )}
                      </td>
                    )}
                    <td className="whitespace-nowrap py-2.5 pr-3 font-medium">{p.punch_in ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 font-medium">{p.punch_out ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{p.hours ?? '—'}</td>
                    <td className="max-w-[140px] truncate py-2.5 pr-3 text-xs text-slate-400" title={p.in_ip ?? ''}>
                      {p.in_ip ?? '—'}
                    </td>
                    <td className={clsx(
                      'max-w-[140px] truncate py-2.5 pr-3 text-xs',
                      // Punched out somewhere else than they punched in.
                      p.out_ip && p.in_ip && p.out_ip !== p.in_ip ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400',
                    )} title={p.out_ip ?? ''}>
                      {p.out_ip ?? '—'}
                    </td>
                    <td className="py-2.5">
                      {canOverride ? (
                        <select
                          value={p.status}
                          onChange={(e) => overrideMutation.mutate({ row: p, newStatus: e.target.value })}
                          className="rounded-lg bg-transparent px-1.5 py-1 text-xs ring-1 ring-inset ring-slate-200 dark:ring-slate-700"
                          title={p.status_source === 'manual' ? 'Set manually' : 'Computed from the HR Policy'}
                        >
                          {Object.entries(CRM_PUNCH_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                      ) : (
                        <span className={punchBadge(p.status)}>{CRM_PUNCH_STATUS_LABELS[p.status]}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={report} onPage={setPage} />

        {/* Per-member summary, like the old CRM's footer table */}
        {teamView && report && report.summary.members.length > 0 && (
          <div className="-mx-4 mt-4 overflow-x-auto border-t border-slate-100 px-4 pt-3 dark:border-slate-800">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="py-2 pr-3 font-medium">Summary</th>
                  <th className="py-2 pr-3 text-right font-medium">Days</th>
                  <th className="py-2 pr-3 text-right font-medium">Present</th>
                  <th className="py-2 pr-3 text-right font-medium">Late</th>
                  <th className="py-2 pr-3 text-right font-medium">Half day</th>
                  <th className="py-2 pr-3 text-right font-medium">Leave</th>
                  <th className="py-2 pr-3 text-right font-medium">Absent</th>
                  <th className="py-2 pr-3 text-right font-medium">Holiday</th>
                  <th className="py-2 pr-3 text-right font-medium">Payable</th>
                  <th className="py-2 pr-3 text-right font-medium">LOP</th>
                  <th className="py-2 text-right font-medium">Avg in</th>
                </tr>
              </thead>
              <tbody>
                {report.summary.members.map((m) => (
                  <tr key={m.member_uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2 pr-3">
                      <div className="font-medium">{m.name}</div>
                      {m.login && <div className="text-xs text-slate-400">{m.login}</div>}
                    </td>
                    <td className="py-2 pr-3 text-right">{m.days}</td>
                    <td className="py-2 pr-3 text-right text-emerald-600">{m.present}</td>
                    <td className={clsx('py-2 pr-3 text-right', m.late > 0 && 'font-medium text-amber-600')}>{m.late}</td>
                    <td className="py-2 pr-3 text-right">{m.half_day}</td>
                    <td className="py-2 pr-3 text-right text-indigo-600 dark:text-indigo-400">{m.leave || '—'}</td>
                    <td className={clsx('py-2 pr-3 text-right', m.absent > 0 && 'font-medium text-red-500')}>{m.absent || '—'}</td>
                    <td className="py-2 pr-3 text-right text-slate-400">{m.holiday}</td>
                    <td className="py-2 pr-3 text-right font-semibold">{m.payable_days}</td>
                    <td className={clsx('py-2 pr-3 text-right', m.lop_days > 0 && 'text-red-500')}>{m.lop_days || '—'}</td>
                    <td className="py-2 text-right font-mono text-xs">{m.avg_in ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
