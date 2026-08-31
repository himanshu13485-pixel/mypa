import { useEffect, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { NotebookPen, Send } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, CRM_DWR_BAND_LABELS, type CrmDwrRow, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { CHART_COLORS, ColumnChart, DonutChart, HBarChart } from './charts'

export function bandBadge(band: string | null) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    band === 'outstanding' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    band === 'good' && 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    band === 'needs_improvement' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    band === 'pip' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
    !band && 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  )
}

const BAND_COLORS: Record<string, string> = {
  outstanding: CHART_COLORS[0],
  good: CHART_COLORS[1],
  needs_improvement: CHART_COLORS[2],
  pip: CHART_COLORS[4],
}

const UNIT_HINTS: Record<string, string> = { count: '', percent: '%', currency: '₹', boolean: '1 = yes' }

export default function CrmDwrPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  // Managers see the company; a Team Workspace leader sees their own
  // people. The Admin is never a DWR person — they file none.
  const manager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const teamView = manager || crmCan(me, 'dwr', 'view') || !!me?.has_team
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [workDate, setWorkDate] = useState(new Date().toISOString().slice(0, 10))
  const [values, setValues] = useState<Record<number, string>>({})
  const [note, setNote] = useState('')
  const [member, setMember] = useState('')
  const [band, setBand] = useState('')
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<CrmDwrRow | null>(null)

  const { data: myKpis } = useQuery({ queryKey: ['crm', 'dwr', 'my-kpis'], queryFn: crm.dwr.myKpis })
  const { data: prefill } = useQuery({ queryKey: ['crm', 'dwr', 'prefill'], queryFn: crm.dwr.prefill })
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters, enabled: teamView })
  const { data: list, isLoading } = useQuery({
    queryKey: ['crm', 'dwr', 'list', member, band, page],
    queryFn: () => crm.dwr.list({ member: member || undefined, band: band || undefined, page }),
  })
  const { data: stats } = useQuery({
    queryKey: ['crm', 'dwr', 'stats', member],
    queryFn: () => crm.dwr.stats({ member: member || undefined }),
  })

  // Pre-fill today's values when today's report already exists.
  const todays = list?.data.find((d) => d.work_date === workDate && d.member?.uuid === me?.member?.uuid)
  useEffect(() => {
    if (!todays) return
    crm.dwr.get(todays.uuid).then((full) => {
      setValues(Object.fromEntries((full.entries ?? [])
        .filter((e) => e.parameter_id !== null)
        .map((e) => [e.parameter_id as number, String(Number(e.value))])))
      setNote(full.note ?? '')
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [todays?.uuid, workDate])

  // Fresh report for today: fill the basics the system already knows —
  // todays closings, invoices, leads, follow-ups — all still editable.
  const today = new Date().toISOString().slice(0, 10)
  useEffect(() => {
    if (todays || workDate !== today || !prefill?.length) return
    setValues((v) => {
      const next = { ...v }
      for (const p of prefill) {
        if (next[p.parameter_id] === undefined || next[p.parameter_id] === '') {
          next[p.parameter_id] = String(p.value)
        }
      }
      return next
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [todays?.uuid, workDate, prefill])

  const submitMutation = useMutation({
    mutationFn: () =>
      crm.dwr.submit({
        work_date: workDate,
        note: note || null,
        entries: (myKpis ?? [])
          .filter((k) => values[k.parameter_id] !== undefined && values[k.parameter_id] !== '')
          .map((k) => ({ parameter_id: k.parameter_id, value: Number(values[k.parameter_id]) || 0 })),
      }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'dwr'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const openDetail = async (row: CrmDwrRow) => {
    try {
      setDetail(await crm.dwr.get(row.uuid))
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Daily work report</h1>
        <p className="text-sm text-slate-500">Values against your assigned KPIs — the score and band are computed, not typed.</p>
      </div>

      {/* My submission form */}
      <Card>
        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <NotebookPen className="size-4 text-emerald-500" /> Submit my DWR
        </h2>
        {!myKpis || myKpis.length === 0 ? (
          <p className="text-sm text-slate-400">No KPI parameters assigned to you yet — ask your admin to set them on your employee profile.</p>
        ) : (
          <>
            <div className="mb-3 flex items-end gap-2">
              <div>
                <Label>Work date</Label>
                <Input type="date" value={workDate} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setWorkDate(e.target.value)} />
              </div>
              {todays && <span className={clsx('mb-2.5', bandBadge(todays.band))}>Already submitted — {todays.score}%</span>}
              {!todays && workDate === today && (prefill?.length ?? 0) > 0 && (
                <span className="mb-2.5 text-xs text-emerald-600">
                  Basics prefilled from todays invoices and leads — edit freely before submitting.
                </span>
              )}
              {todays && !manager && (
                <span className="mb-2.5 text-xs text-slate-400">
                  Final — corrections go through an Admin/Subadmin.
                </span>
              )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {myKpis.map((k) => (
                <div key={k.parameter_id}>
                  <Label>
                    {k.name}
                    <span className="ml-1 font-normal text-slate-400">
                      · target {Number(k.daily_target)}{UNIT_HINTS[k.unit] && ` ${UNIT_HINTS[k.unit]}`} · wt {k.weightage}
                    </span>
                  </Label>
                  <Input
                    type="number"
                    min="0"
                    step={k.unit === 'boolean' ? 1 : 'any'}
                    max={k.unit === 'boolean' ? 1 : undefined}
                    value={values[k.parameter_id] ?? ''}
                    onChange={(e) => setValues((v) => ({ ...v, [k.parameter_id]: e.target.value }))}
                    className="w-full"
                  />
                </div>
              ))}
              <div className="sm:col-span-2 lg:col-span-3">
                <Label>Note</Label>
                <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} className="w-full" />
              </div>
            </div>
            {/* One shot a day: once submitted, only the Admin/Subadmin can
                correct it — the button disappears for the owner. */}
            {(!todays || manager) && (
              <Button className="mt-3" disabled={submitMutation.isPending} onClick={() => submitMutation.mutate()}>
                <Send className="size-4" /> {submitMutation.isPending ? 'Submitting…' : todays ? 'Correct report (Admin)' : 'Submit report'}
              </Button>
            )}
          </>
        )}
      </Card>

      {/* Charts */}
      {stats && (
        <div className="grid gap-4 lg:grid-cols-3">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Daily average score</h2>
            <ColumnChart
              data={stats.daily.map((d) => ({ label: d.date.slice(5), value: d.avg_score }))}
              unit="%"
              yMax={100}
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Performance bands</h2>
            <DonutChart
              data={stats.bands.map((b) => ({
                label: CRM_DWR_BAND_LABELS[b.band] ?? b.band,
                value: b.count,
                color: BAND_COLORS[b.band],
              }))}
              centerLabel="reports"
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              {teamView ? 'Average score by person' : 'My average'}
            </h2>
            <HBarChart data={stats.members.map((m) => ({ label: m.name ?? '—', value: m.avg_score }))} unit="%" />
          </Card>
        </div>
      )}

      {/* History */}
      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto text-sm font-semibold text-slate-800 dark:text-slate-100">Reports</h2>
          {teamView && (
            <Select value={member} onChange={(e) => { setMember(e.target.value); setPage(1) }}>
              <option value="">Everyone</option>
              {masters?.members
                .filter((m) => (m.crm_role ?? 'employee') !== 'admin')
                .filter((m) => manager || (me?.member?.team_member_uuids ?? []).includes(m.uuid))
                .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
          )}
          <Select value={band} onChange={(e) => { setBand(e.target.value); setPage(1) }}>
            <option value="">All bands</option>
            {Object.entries(CRM_DWR_BAND_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !list || list.data.length === 0 ? (
          <EmptyState title="No reports yet" hint="Submitted DWRs appear here." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Work date</th>
                  {teamView && <th className="py-2 pr-3 font-medium">Employee</th>}
                  <th className="py-2 pr-3 font-medium">Submitted</th>
                  <th className="w-44 py-2 pr-3 font-medium">Score</th>
                  <th className="py-2 font-medium">Band</th>
                </tr>
              </thead>
              <tbody>
                {list.data.map((d) => (
                  <tr
                    key={d.uuid}
                    onClick={() => openDetail(d)}
                    className="cursor-pointer border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40"
                  >
                    <td className="whitespace-nowrap py-2.5 pr-3 font-medium text-emerald-600">{d.work_date}</td>
                    {teamView && <td className="py-2.5 pr-3">{d.member?.name ?? '—'}</td>}
                    <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{d.submitted_at?.slice(0, 16)}</td>
                    <td className="py-2.5 pr-3">
                      {d.score !== null ? (
                        <div className="flex items-center gap-2">
                          <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                              className="h-full rounded-full"
                              style={{ width: `${Math.min(100, d.score)}%`, background: BAND_COLORS[d.band ?? ''] ?? CHART_COLORS[1] }}
                            />
                          </div>
                          <span className="w-12 text-right text-xs font-medium tabular-nums">{d.score}%</span>
                        </div>
                      ) : '—'}
                    </td>
                    <td className="py-2.5">
                      <span className={bandBadge(d.band)}>{d.band ? CRM_DWR_BAND_LABELS[d.band] : '—'}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={list} onPage={setPage} />
      </Card>

      {detail && (
        <Modal title={`DWR — ${detail.member?.name ?? ''} · ${detail.work_date}`} onClose={() => setDetail(null)} wide>
          <div className="space-y-3">
            <div className="flex items-center gap-3">
              <span className={bandBadge(detail.band)}>{detail.band ? CRM_DWR_BAND_LABELS[detail.band] : '—'}</span>
              <span className="text-lg font-semibold">{detail.score}%</span>
              {detail.note && <span className="text-sm text-slate-500">{detail.note}</span>}
            </div>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Parameter</th>
                  <th className="py-2 pr-3 text-right font-medium">Target</th>
                  <th className="py-2 pr-3 text-right font-medium">Value</th>
                  <th className="py-2 pr-3 text-right font-medium">Weight</th>
                  <th className="py-2 text-right font-medium">Achievement</th>
                </tr>
              </thead>
              <tbody>
                {detail.entries?.map((e, i) => (
                  <tr key={i} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2 pr-3">{e.name}</td>
                    <td className="py-2 pr-3 text-right text-slate-500">{Number(e.target)}</td>
                    <td className="py-2 pr-3 text-right font-medium">{Number(e.value)}</td>
                    <td className="py-2 pr-3 text-right text-slate-500">{e.weightage}</td>
                    <td className={clsx('py-2 text-right font-medium', e.achievement >= 100 ? 'text-emerald-600' : e.achievement >= 50 ? 'text-amber-600' : 'text-red-500')}>
                      {e.achievement}%
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Modal>
      )}
    </div>
  )
}
