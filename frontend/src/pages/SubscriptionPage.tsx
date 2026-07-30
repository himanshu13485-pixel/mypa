import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CreditCard, FileText, Receipt } from 'lucide-react'
import { format } from 'date-fns'
import { subscription as subscriptionApi } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { Badge, Button, Card, EmptyState, Spinner } from '../components/ui'

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

async function openInvoice(uuid: string) {
  const token = useAuthStore.getState().token
  const res = await fetch(`/api/v1/invoices/${uuid}`, { headers: { Authorization: `Bearer ${token}` } })
  const html = await res.text()
  const win = window.open('', '_blank')
  win?.document.write(html)
  win?.document.close()
}

export default function SubscriptionPage() {
  const queryClient = useQueryClient()
  const { data: mySub, isLoading } = useQuery({ queryKey: ['my-subscription'], queryFn: subscriptionApi.mine })
  const { data: payments } = useQuery({ queryKey: ['my-payments'], queryFn: subscriptionApi.payments })
  const { data: invoices } = useQuery({ queryKey: ['my-invoices'], queryFn: subscriptionApi.invoices })

  if (isLoading || !mySub) return <Spinner />

  const isFree = mySub.plan.slug === 'free'

  return (
    <div className="max-w-4xl space-y-4">
      <h1 className="flex items-center gap-2 text-lg font-semibold">
        <CreditCard className="size-5 text-brand-600" /> Subscription
      </h1>

      {/* Current plan */}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xl font-bold">{mySub.plan.name} plan</p>
            {mySub.plan.description && <p className="mt-0.5 text-xs text-slate-500">{mySub.plan.description}</p>}
            <p className="mt-1 text-xs text-slate-400">
              Status: <Badge value={mySub.status === 'free' ? 'active' : mySub.status} />
              {mySub.started_at && ` · started ${format(new Date(mySub.started_at), 'd MMM yyyy')}`}
              {mySub.trial_ends_at && ` · trial ends ${format(new Date(mySub.trial_ends_at), 'd MMM yyyy')}`}
              {mySub.ends_at && ` · renews/expires ${format(new Date(mySub.ends_at), 'd MMM yyyy')}`}
            </p>
          </div>
          <div className="flex gap-2">
            <Link to="/pricing">
              <Button size="sm">{isFree ? 'Upgrade plan' : 'Change plan'}</Button>
            </Link>
            {!isFree && mySub.ends_at && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (confirm('Cancel your subscription? Your plan stays active until the end of the paid period, then your account moves to Free.')) {
                    subscriptionApi.cancel().then((res) => {
                      alert((res as { message?: string }).message ?? 'Cancelled.')
                      queryClient.invalidateQueries({ queryKey: ['my-subscription'] })
                    })
                  }
                }}
              >
                Cancel
              </Button>
            )}
          </div>
        </div>

        {/* Usage vs limits */}
        <div className="mt-4 space-y-3">
          {Object.entries(mySub.usage).map(([key, { used, limit }]) => {
            const isStorage = key === 'storage'
            const pct = limit ? Math.min(100, (used / limit) * 100) : 0
            return (
              <div key={key}>
                <div className="mb-1 flex justify-between text-xs text-slate-500">
                  <span className="capitalize">{key}</span>
                  <span>
                    {isStorage ? formatBytes(used) : used}
                    {' / '}
                    {limit === null ? 'unlimited' : isStorage ? formatBytes(limit) : limit}
                  </span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                  <div
                    className={pct >= 90 ? 'h-full rounded-full bg-red-500' : 'h-full rounded-full bg-brand-500'}
                    style={{ width: limit === null ? '4%' : `${pct}%` }}
                  />
                </div>
              </div>
            )
          })}
        </div>
      </Card>

      {/* Payment history */}
      <Card>
        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
          <Receipt className="size-4 text-slate-400" /> Payment history
        </h2>
        {!payments?.data.length ? (
          <EmptyState title="No payments yet" hint="Payments appear here after your first checkout." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                  <th className="pb-2 pr-4 font-medium">Date</th>
                  <th className="pb-2 pr-4 font-medium">Plan</th>
                  <th className="pb-2 pr-4 font-medium">Order</th>
                  <th className="pb-2 pr-4 font-medium">Amount</th>
                  <th className="pb-2 pr-4 font-medium">Status</th>
                  <th className="pb-2 font-medium">Invoice</th>
                </tr>
              </thead>
              <tbody>
                {payments.data.map((p) => (
                  <tr key={p.uuid} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                    <td className="py-2 pr-4 text-xs text-slate-500">
                      {p.paid_at ? format(new Date(p.paid_at), 'd MMM yyyy') : '—'}
                    </td>
                    <td className="py-2 pr-4">{p.plan} <span className="text-xs text-slate-400">({p.frequency})</span></td>
                    <td className="py-2 pr-4 font-mono text-xs">{p.order_number}</td>
                    <td className="py-2 pr-4">₹{p.amount}{Number(p.refunded) > 0 && (
                      <span className="ml-1 text-xs text-red-500">(−₹{p.refunded} refunded)</span>
                    )}</td>
                    <td className="py-2 pr-4"><Badge value={p.status === 'successful' ? 'completed' : p.status} /></td>
                    <td className="py-2">
                      {p.invoice_uuid && (
                        <button className="text-xs text-brand-600 hover:underline" onClick={() => openInvoice(p.invoice_uuid!)}>
                          View
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* Invoices */}
      <Card>
        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
          <FileText className="size-4 text-slate-400" /> Invoices
        </h2>
        {!invoices?.data.length ? (
          <EmptyState title="No invoices yet" />
        ) : (
          <div className="space-y-1.5">
            {invoices.data.map((inv) => (
              <div key={inv.uuid} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
                <div>
                  <span className="font-mono text-xs">{inv.invoice_number}</span>
                  <span className="ml-2 text-xs text-slate-500">{inv.plan_name} · ₹{inv.total}</span>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-[11px] text-slate-400">{format(new Date(inv.issued_at), 'd MMM yyyy')}</span>
                  <button className="text-xs text-brand-600 hover:underline" onClick={() => openInvoice(inv.uuid)}>
                    View / print
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <p className="text-center text-[11px] text-slate-400">
        Open an invoice and use your browser's Print → Save as PDF to download it.
      </p>
    </div>
  )
}
