import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Cake, IndianRupee, ReceiptText, UserCheck, Users } from 'lucide-react'
import { clsx } from 'clsx'
import { ScopeToggle, useTeamHead } from './ScopeToggle'
import { Avatar } from '../../lib/avatars'
import { LETTER_LABELS, letterAvailability, openLetter, type LetterType } from './letters'
import { crm, crmMeQuery, CRM_LEAD_STATUS_LABELS, CRM_PAYMENT_STATUS_LABELS } from '../../api/crm'
import { Card, EmptyState, Spinner , Select } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'
import { crmPath } from '../../lib/crmPath'

const LEAD_STATUS_COLORS: Record<string, string> = {
  unattended: CHART_COLORS[2],
  follow_up: CHART_COLORS[1],
  closed: CHART_COLORS[0],
  not_interested: '#64748b',
  transferred: CHART_COLORS[3],
}

const PAYMENT_COLORS: Record<string, string> = {
  paid: CHART_COLORS[0],
  partial: CHART_COLORS[2],
  due: CHART_COLORS[4],
  refunded: CHART_COLORS[3],
  credit_note: CHART_COLORS[1],
  bad_debt: '#64748b',
}

const inr = (v: number | string) =>
  '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export default function CrmDashboard() {
  // A Team Head's dashboard opens on their own sales; the combined view is
  // a switch away and never mixed in by accident.
  const teamHead = useTeamHead()
  const { data: me } = useQuery(crmMeQuery())
  const [scope, setScope] = useState<'mine' | 'team'>('mine')
  const [salesperson, setSalesperson] = useState('')
  const effectiveScope = teamHead ? scope : 'team'
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'dashboard', effectiveScope, salesperson],
    queryFn: () => crm.dashboard(effectiveScope, effectiveScope === 'team' ? salesperson : undefined),
  })

  if (isLoading || !data) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  const stats = [
    { label: 'Active employees', value: `${data.employees.active}`, sub: `${data.employees.total} total`, icon: Users },
    { label: 'Active clients', value: `${data.clients.active}`, sub: `${data.clients.total} total`, icon: UserCheck },
    { label: 'Invoiced this month', value: inr(data.invoices.month_total), sub: `${data.invoices.month_count} invoices`, icon: ReceiptText },
    { label: 'Outstanding', value: inr(data.invoices.outstanding), sub: `${inr(data.invoices.received_this_month)} received this month`, icon: IndianRupee },
  ]

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        {/* The person's own face heads their dashboard; the photo comes
            from their Netvork profile, the default from their gender. */}
        <Avatar
          name={me?.member?.name ?? undefined}
          photoPath={me?.member?.photo_path ?? undefined}
          avatar={me?.member?.avatar ?? undefined}
          gender={me?.member?.gender ?? undefined}
          size={46}
        />
        <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">CRM dashboard</h1>
        <p className="text-sm text-slate-500">
          {data.invoices.proforma_open > 0 && (
            <>
              <Link to={crmPath('/crm/invoices?kind=proforma')} className="font-medium text-emerald-600 hover:underline">
                {data.invoices.proforma_open} open proforma
              </Link>{' '}
              waiting to convert ·{' '}
            </>
          )}
          {data.today}
          {teamHead && <> · {scope === 'mine' ? 'your own sales' : 'you and your team together'}</>}
        </p>
        </div>
      </div>

      <ScopeToggle scope={scope} onChange={(next) => { setScope(next); setSalesperson('') }} show={teamHead} />

      {/* One person's figures out of the combined view. */}
      {effectiveScope === 'team' && (data.salespeople?.length ?? 0) > 1 && (
        <Select value={salesperson} onChange={(e) => setSalesperson(e.target.value)} className="w-full sm:max-w-xs">
          <option value="">All salespeople</option>
          {data.salespeople!.map((m) => (
            <option key={m.uuid} value={m.uuid}>{m.name}{m.is_me ? ' (you)' : ''}</option>
          ))}
        </Select>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map((s) => (
          <Card key={s.label} className="flex items-start gap-3">
            <div className="rounded-xl bg-emerald-50 p-2.5 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
              <s.icon className="size-5" />
            </div>
            <div className="min-w-0">
              <div className="truncate text-lg font-semibold text-slate-900 dark:text-white">{s.value}</div>
              <div className="text-xs font-medium text-slate-600 dark:text-slate-300">{s.label}</div>
              <div className="truncate text-xs text-slate-400">{s.sub}</div>
            </div>
          </Card>
        ))}
      </div>

      <MyHrFileCard />

      {data.charts && (
        <div className="grid gap-5 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Lead pipeline</h2>
            <HBarChart
              data={Object.entries(data.charts.leads_by_status).map(([status, n]) => ({
                label: CRM_LEAD_STATUS_LABELS[status] ?? status,
                value: n,
                color: LEAD_STATUS_COLORS[status],
              }))}
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Invoices by payment state</h2>
            <DonutChart
              data={data.charts.invoices_by_payment.map((p) => ({
                label: CRM_PAYMENT_STATUS_LABELS[p.status] ?? p.status,
                value: p.count,
                color: PAYMENT_COLORS[p.status],
              }))}
              centerLabel="invoices"
            />
          </Card>
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <h2 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Recent documents</h2>
          {data.recent_invoices.length === 0 ? (
            <EmptyState title="No invoices yet" hint="Create a proforma or invoice to see it here." />
          ) : (
            <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[540px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Number</th>
                    <th className="py-2 pr-3 font-medium">Client</th>
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 text-right font-medium">Total</th>
                    <th className="py-2 font-medium">Payment</th>
                  </tr>
                </thead>
                <tbody>
                  {data.recent_invoices.map((i) => (
                    <tr key={i.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="py-2 pr-3">
                        <Link to={crmPath(`/crm/invoices/${i.uuid}`)} className="font-medium text-emerald-600 hover:underline">
                          {i.number}
                        </Link>
                        <span className="ml-1.5 text-[10px] uppercase text-slate-400">{i.kind}</span>
                      </td>
                      <td className="max-w-[180px] truncate py-2 pr-3">{i.client ?? '—'}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-slate-500">{i.invoice_date}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right font-medium">{inr(i.total)}</td>
                      <td className="py-2">
                        <span
                          className={clsx(
                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                            i.payment_status === 'paid' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                            i.payment_status === 'partial' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                            !['paid', 'partial'].includes(i.payment_status) && 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                          )}
                        >
                          {CRM_PAYMENT_STATUS_LABELS[i.payment_status] ?? i.payment_status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <Cake className="size-4 text-pink-500" /> Upcoming birthdays
          </h2>
          {data.birthdays.length === 0 ? (
            <p className="text-sm text-slate-400">Nothing in the next 7 days.</p>
          ) : (
            <ul className="space-y-2">
              {data.birthdays.map((b, idx) => (
                <li key={idx} className="flex items-center justify-between text-sm">
                  <span className="flex min-w-0 items-center gap-2 font-medium text-slate-700 dark:text-slate-200">
                    <Avatar name={b.name ?? undefined} photoPath={b.photo_path ?? undefined} avatar={b.avatar ?? undefined} gender={b.gender ?? undefined} size={26} />
                    <span className="truncate">{b.name}</span>
                  </span>
                  <span className="text-xs text-slate-400">
                    {b.in_days === 0 ? 'Today 🎉' : b.in_days === 1 ? 'Tomorrow' : `in ${b.in_days} days`}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}

/**
 * The person's own HR file: the documents the Admin attached to them
 * (theirs to download, always) and their HR letters — offered only when
 * the Admin granted the letters.download permission by name.
 */
function MyHrFileCard() {
  const { data: mine } = useQuery({ queryKey: ['crm', 'my-profile'], queryFn: crm.employees.myProfile })
  const { data: me } = useQuery(crmMeQuery())
  const orgName = me?.organization?.name ?? 'The Company'

  if (!mine) return null
  const docs = mine.documents ?? []
  const availability = mine.letters_allowed ? letterAvailability(mine) : null
  if (docs.length === 0 && !mine.letters_allowed) return null

  const download = async (uuid: string, name: string) => {
    try {
      const blob = await crm.employees.downloadMyDocument(uuid)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      a.click()
      URL.revokeObjectURL(url)
    } catch { /* the server names the refusal */ }
  }

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">My HR file</h2>
      <p className="mt-0.5 text-xs text-slate-400">Your documents on record{mine.letters_allowed ? ' and your HR letters' : ''}.</p>
      <div className="mt-3 grid gap-4 lg:grid-cols-2">
        <div>
          <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Documents</p>
          {docs.length === 0 ? (
            <p className="text-sm text-slate-400">Nothing attached yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {docs.map((d) => (
                <li key={d.uuid} className="flex items-center justify-between gap-2 py-1.5">
                  <span className="min-w-0 truncate text-slate-700 dark:text-slate-200">{d.name}</span>
                  <button onClick={() => download(d.uuid, d.name)} className="shrink-0 text-xs font-medium text-emerald-600 hover:underline">
                    Download
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        {mine.letters_allowed && availability && (
          <div>
            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">HR letters</p>
            <div className="flex flex-wrap gap-1.5">
              {(Object.keys(LETTER_LABELS) as LetterType[]).filter((t) => t !== 'fnf' && availability[t].enabled).map((t) => (
                <button
                  key={t}
                  onClick={() => openLetter(t, mine, orgName)}
                  className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-emerald-500/10"
                >
                  {LETTER_LABELS[t]}
                </button>
              ))}
            </div>
            <p className="mt-1 text-[11px] text-slate-400">Each opens print-ready; save as PDF from the print dialog.</p>
          </div>
        )}
      </div>
    </Card>
  )
}
