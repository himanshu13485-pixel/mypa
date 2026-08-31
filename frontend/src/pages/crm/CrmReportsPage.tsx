import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { ScopeToggle, useTeamHead } from './ScopeToggle'
import { crm, CRM_LEAD_STATUS_LABELS, CRM_PAYMENT_STATUS_LABELS } from '../../api/crm'
import { Button, Card, Select, Spinner } from '../../components/ui'
import { CHART_COLORS, ColumnChart, DonutChart, HBarChart, Legend } from './charts'

const inr = (v: number) => '₹' + Math.round(v).toLocaleString('en-IN')

/** First and last day (so far) of a month offset from the current one. */
const monthRange = (offset: number) => {
  const now = new Date()
  const first = new Date(now.getFullYear(), now.getMonth() + offset, 1)
  const last = offset === 0 ? now : new Date(now.getFullYear(), now.getMonth() + offset + 1, 0)
  const day = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  return { from: day(first), to: day(last) }
}

export default function CrmReportsPage() {
  const [months, setMonths] = useState(12)
  // 'months' presets, or this/last month, or the calendar pick.
  const [rangeKind, setRangeKind] = useState<'months' | 'this_month' | 'last_month' | 'custom'>('months')
  const [customFrom, setCustomFrom] = useState('')
  const [customTo, setCustomTo] = useState('')
  const range = rangeKind === 'this_month' ? monthRange(0)
    : rangeKind === 'last_month' ? monthRange(-1)
      : rangeKind === 'custom' && customFrom ? { from: customFrom, to: customTo || customFrom }
        : undefined
  // The same two ledgers as everywhere money is shown.
  const teamHead = useTeamHead()
  const [scope, setScope] = useState<'mine' | 'team'>('mine')
  const [salesperson, setSalesperson] = useState('')
  const effectiveScope = teamHead ? scope : 'team'
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'reports', months, effectiveScope, salesperson, range?.from ?? '', range?.to ?? ''],
    queryFn: () => crm.reports.overview(months, effectiveScope, effectiveScope === 'team' ? salesperson : undefined, range),
  })

  const exportCsv = () => {
    if (!data) return
    const rows = [
      ['Month', 'Invoiced', 'Received', 'Expenses', 'Payroll'],
      ...data.monthly.map((m) => [m.month, m.invoiced, m.received, m.expenses, m.payroll]),
    ]
    const csv = rows.map((r) => r.join(',')).join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    const a = document.createElement('a')
    a.href = url
    a.download = range ? `crm-report-${range.from}-to-${range.to}.csv` : `crm-report-${months}m.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  if (isLoading || !data) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  const net = data.totals.received - data.totals.expenses - data.totals.payroll

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Reports</h1>
          <p className="text-sm text-slate-500">
            Computed live from the ledgers — nothing here can go stale.
            {teamHead && <> · {scope === 'mine' ? 'your own sales' : 'you and your team together'}</>}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select
            value={rangeKind === 'months' ? String(months) : rangeKind}
            onChange={(e) => {
              const v = e.target.value
              if (v === 'this_month' || v === 'last_month' || v === 'custom') {
                setRangeKind(v)
              } else {
                setRangeKind('months')
                setMonths(Number(v))
              }
            }}
          >
            <option value="this_month">Current month</option>
            <option value="last_month">Last month</option>
            <option value="3">Last 3 months</option>
            <option value="6">Last 6 months</option>
            <option value="12">Last 12 months</option>
            <option value="24">Last 24 months</option>
            <option value="custom">Custom range…</option>
          </Select>
          {rangeKind === 'custom' && (
            <>
              <input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" />
              <span className="text-sm text-slate-400">→</span>
              <input type="date" value={customTo} min={customFrom || undefined} onChange={(e) => setCustomTo(e.target.value)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" />
            </>
          )}
          <Button variant="secondary" onClick={exportCsv}><Download className="size-4" /> CSV</Button>
        </div>
      </div>

      <ScopeToggle scope={scope} onChange={(next) => { setScope(next); setSalesperson('') }} show={teamHead} />

      {effectiveScope === 'team' && (data?.salespeople?.length ?? 0) > 1 && (
        <Select value={salesperson} onChange={(e) => setSalesperson(e.target.value)} className="w-full sm:max-w-xs">
          <option value="">All salespeople</option>
          {data!.salespeople!.map((m) => (
            <option key={m.uuid} value={m.uuid}>{m.name}{m.is_me ? ' (you)' : ''}</option>
          ))}
        </Select>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {[
          { label: 'Invoiced', value: inr(data.totals.invoiced) },
          { label: 'Received', value: inr(data.totals.received) },
          { label: 'Expenses', value: inr(data.totals.expenses) },
          { label: 'Payroll', value: inr(data.totals.payroll) },
          { label: 'Net (received − out)', value: inr(net), tone: net >= 0 ? 'text-emerald-600' : 'text-red-500' },
        ].map((s) => (
          <Card key={s.label} className="py-3">
            <div className={`text-lg font-semibold ${s.tone ?? 'text-slate-900 dark:text-white'}`}>{s.value}</div>
            <div className="text-xs text-slate-500">{s.label}</div>
          </Card>
        ))}
      </div>

      <Card>
        <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Money in — invoiced vs received, by month</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <ColumnChart data={data.monthly.map((m) => ({ label: m.month.slice(2), value: m.invoiced }))} color={CHART_COLORS[1]} />
          <ColumnChart data={data.monthly.map((m) => ({ label: m.month.slice(2), value: m.received }))} color={CHART_COLORS[0]} />
        </div>
        <Legend data={[
          { label: 'Invoiced', value: data.totals.invoiced, color: CHART_COLORS[1] },
          { label: 'Received', value: data.totals.received, color: CHART_COLORS[0] },
        ]} />
      </Card>

      <Card>
        <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Money out — expenses and payroll, by month</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <ColumnChart data={data.monthly.map((m) => ({ label: m.month.slice(2), value: m.expenses }))} color={CHART_COLORS[2]} />
          <ColumnChart data={data.monthly.map((m) => ({ label: m.month.slice(2), value: m.payroll }))} color={CHART_COLORS[3]} />
        </div>
        <Legend data={[
          { label: 'Expenses', value: data.totals.expenses, color: CHART_COLORS[2] },
          { label: 'Payroll', value: data.totals.payroll, color: CHART_COLORS[3] },
        ]} />
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Invoices by payment state</h2>
          <DonutChart
            data={data.invoice_status.map((s) => ({
              label: CRM_PAYMENT_STATUS_LABELS[s.status] ?? s.status,
              value: s.count,
              color: { paid: CHART_COLORS[0], partial: CHART_COLORS[2], due: CHART_COLORS[4] }[s.status] ?? CHART_COLORS[3],
            }))}
            centerLabel="invoices"
          />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Lead funnel</h2>
          <HBarChart
            data={data.lead_funnel.map((s) => ({
              label: CRM_LEAD_STATUS_LABELS[s.status] ?? s.status,
              value: s.count,
              color: { closed: CHART_COLORS[0], follow_up: CHART_COLORS[1], unattended: CHART_COLORS[2], not_interested: '#64748b', transferred: CHART_COLORS[3] }[s.status],
            }))}
          />
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Top clients by billing</h2>
          <HBarChart data={data.top_clients.map((c) => ({ label: c.name, value: c.amount }))} color={CHART_COLORS[1]} />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Top salespeople</h2>
          {(data.totals.commission ?? 0) > 0 ? (
            /* Commission was paid out of these sales, so billed alone would
               flatter — the table says billed, commission and net. */
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-1.5 pr-2 font-medium">Salesperson</th>
                  <th className="py-1.5 pr-2 text-right font-medium">Billed</th>
                  <th className="py-1.5 pr-2 text-right font-medium">Commission</th>
                  <th className="py-1.5 text-right font-medium">Net</th>
                </tr>
              </thead>
              <tbody>
                {data.top_salespeople.map((s) => (
                  <tr key={s.name} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="max-w-[140px] truncate py-1.5 pr-2">{s.name}</td>
                    <td className="whitespace-nowrap py-1.5 pr-2 text-right tabular-nums">₹{s.amount.toLocaleString('en-IN')}</td>
                    <td className="whitespace-nowrap py-1.5 pr-2 text-right tabular-nums text-red-500">
                      {(s.commission ?? 0) > 0 ? `− ₹${(s.commission ?? 0).toLocaleString('en-IN')}` : '—'}
                    </td>
                    <td className="whitespace-nowrap py-1.5 text-right font-medium tabular-nums">₹{(s.net ?? s.amount).toLocaleString('en-IN')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <HBarChart data={data.top_salespeople.map((s) => ({ label: s.name, value: s.amount }))} color={CHART_COLORS[0]} />
          )}
        </Card>
      </div>
    </div>
  )
}
