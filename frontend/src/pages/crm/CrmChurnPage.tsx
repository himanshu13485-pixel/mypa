import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { TrendingDown } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmMeQuery } from '../../api/crm'
import { useQuery as useQ } from '@tanstack/react-query'
import { Card, EmptyState, Select, Spinner } from '../../components/ui'

const inr = (v: number) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

/**
 * Churn the industry way: a client counts as active while invoiced inside
 * the trailing twelve months (or their Work Order validity still runs).
 * Monthly churn = clients lost this month over clients active at its start.
 */
export default function CrmChurnPage() {
  const [months, setMonths] = useState(12)
  const [member, setMember] = useState('')
  const { data: me } = useQ(crmMeQuery())
  const { data: masters } = useQ({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const manager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'churn', months, member],
    queryFn: () => crm.churn(months, member || undefined),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            <TrendingDown className="size-5 text-emerald-500" /> Churn
          </h1>
          <p className="text-sm text-slate-500">
            Active, new and repeat customers month by month — and who fell away.
          </p>
        </div>
        {(manager || !!me?.has_team) && (
          <Select value={member} onChange={(e) => setMember(e.target.value)} title="Whose churn">
            <option value="">{manager ? 'Whole company' : 'My whole team'}</option>
            {(masters?.members ?? [])
              .filter((m) => (m.crm_role ?? 'employee') !== 'admin')
              .filter((m) => manager || (me?.member?.team_member_uuids ?? []).includes(m.uuid))
              .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
        )}
        <Select value={months} onChange={(e) => setMonths(Number(e.target.value))}>
          <option value={6}>Last 6 months</option>
          <option value={12}>Last 12 months</option>
          <option value={24}>Last 24 months</option>
        </Select>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner /></div>
      ) : !data ? (
        <Card><EmptyState title="Nothing to compute yet" hint="Churn reads from the invoice ledger." /></Card>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            {([
              ['Active customers now', String(data.summary.active), 'text-slate-900 dark:text-white'],
              ['Avg monthly churn', data.summary.avg_churn_rate + '%', 'text-red-500'],
              ['Avg retention', data.summary.avg_retention_rate + '%', 'text-emerald-600'],
            ] as const).map(([label, value, tone]) => (
              <Card key={label} className="py-3">
                <div className={clsx('text-lg font-semibold', tone)}>{value}</div>
                <div className="text-xs text-slate-500">{label}</div>
              </Card>
            ))}
          </div>

          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Month by month</h2>
            <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[760px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Month</th>
                    <th className="py-2 pr-3 text-right font-medium">Active</th>
                    <th className="py-2 pr-3 text-right font-medium">New</th>
                    <th className="py-2 pr-3 text-right font-medium">Repeat</th>
                    <th className="py-2 pr-3 text-right font-medium">Churned</th>
                    <th className="py-2 pr-3 text-right font-medium">Churn %</th>
                    <th className="py-2 text-right font-medium">Retention %</th>
                  </tr>
                </thead>
                <tbody>
                  {data.months.map((m) => (
                    <tr key={m.month} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50" title={m.churned_names.join(', ')}>
                      <td className="py-2 pr-3">{m.month}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{m.active}</td>
                      <td className="py-2 pr-3 text-right tabular-nums text-emerald-600">{m.new_customers}</td>
                      <td className="py-2 pr-3 text-right tabular-nums text-sky-600">{m.repeat_customers}</td>
                      <td className="py-2 pr-3 text-right tabular-nums text-red-500">{m.churned}</td>
                      <td className={clsx('py-2 pr-3 text-right font-medium tabular-nums', m.churn_rate > 5 ? 'text-red-600' : 'text-slate-600 dark:text-slate-300')}>
                        {m.churn_rate}%
                      </td>
                      <td className="py-2 text-right tabular-nums text-slate-500">{m.retention_rate}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-2 text-xs text-slate-400">
              Hover a row to see who churned that month. Active = invoiced inside the trailing 12 months, or a Work Order validity still running.
            </p>
          </Card>

          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              Validity ended, not renewed — the follow-up list
            </h2>
            {data.not_renewed.length === 0 ? (
              <p className="text-sm text-slate-400">Every finished run has renewed. Nothing to chase.</p>
            ) : (
              <div className="-mx-4 overflow-x-auto px-4">
              <table className="w-full min-w-[560px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                    <th className="py-2 pr-3 font-medium">Client</th>
                    <th className="py-2 pr-3 font-medium">Covered till</th>
                    <th className="py-2 pr-3 font-medium">Last invoiced</th>
                    <th className="py-2 text-right font-medium">Lifetime revenue</th>
                  </tr>
                </thead>
                <tbody>
                  {data.not_renewed.map((r, i) => (
                    <tr key={i} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                      <td className="py-2 pr-3 font-medium text-slate-700 dark:text-slate-200">{r.client}</td>
                      <td className="py-2 pr-3 text-red-500">{r.covered_to}</td>
                      <td className="py-2 pr-3 text-slate-500">{r.last_invoice}</td>
                      <td className="py-2 text-right tabular-nums">{inr(r.lifetime_revenue)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  )
}
