import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUpRight, Ban, Check, ShieldAlert, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Spinner } from '../../components/ui'

/**
 * Reports between two people in this company.
 *
 * One employee reporting another for spamming the office group is the
 * company's business first - it employs both and knows the context. So it
 * lands here, with the remedies a company actually has: dismiss it, warn its
 * employee, take the message down. Suspending an account is not one of them,
 * because the account is not the company's; that is handed to Netvork.
 */
export default function CrmSpamReportsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [status, setStatus] = useState<'open' | 'escalated' | 'actioned' | 'dismissed'>('open')

  const { data, isLoading, error } = useQuery({
    queryKey: ['crm', 'spam-reports', status],
    queryFn: () => crm.reportsQueue.list(status),
    refetchInterval: 60_000,
  })

  const actMutation = useMutation({
    mutationFn: ({ uuid, action, note }: { uuid: string; action: string; note?: string }) =>
      crm.reportsQueue.act(uuid, action, note),
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['crm', 'spam-reports'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const act = (uuid: string, action: string) => {
    let note: string | undefined
    if (action === 'warn') note = prompt('A note for the warning (optional):') ?? undefined
    if (action === 'escalate') {
      // Netvork reads this first, so it is required rather than optional.
      const why = prompt('Why does this need Netvork? (required)')
      if (!why?.trim()) return
      note = why.trim()
    }
    if (action === 'delete_message' && !confirm('Take this message down for everyone?')) return
    actMutation.mutate({ uuid, action, note })
  }

  const tabs: [typeof status, string][] = [
    ['open', `Open${data?.counts ? ` (${data.counts.open})` : ''}`],
    ['escalated', `With Netvork${data?.counts ? ` (${data.counts.escalated})` : ''}`],
    ['actioned', 'Actioned'],
    ['dismissed', 'Dismissed'],
  ]

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
          <ShieldAlert className="size-5 text-red-500" /> Spam reports
        </h1>
        <p className="text-sm text-slate-500">
          Reports your employees filed about one another. Netvork sees these too, and anything
          you hand up.
        </p>
      </div>

      <Card>
        <div className="mb-3 flex flex-wrap gap-1">
          {tabs.map(([value, label]) => (
            <Button key={value} size="sm" variant={status === value ? 'primary' : 'ghost'} onClick={() => setStatus(value)}>
              {label}
            </Button>
          ))}
        </div>

        {isLoading ? (
          <div className="flex justify-center py-12"><Spinner /></div>
        ) : error ? (
          <EmptyState title="Not yours to see" hint="Reports about your company are for its Admin." />
        ) : !data?.data.length ? (
          <EmptyState title="Nothing here" hint="Reports between people in your company land here." />
        ) : (
          <ul className="space-y-2">
            {data.data.map((r) => (
              <li key={r.uuid} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0 text-sm">
                    <p>
                      <span className="mr-2 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-500/10 dark:text-red-400">
                        {r.reason}
                      </span>
                      <span className="font-medium">{r.reporter?.name}</span>
                      <span className="text-slate-500"> reported </span>
                      <span className="font-medium">{r.reported_user?.name}</span>
                    </p>
                    {r.message && (
                      <p className="mt-1 rounded bg-slate-50 px-2 py-1 text-xs italic text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {r.message.deleted_at ? '(message taken down)' : `“${r.message.body ?? '…'}”`}
                      </p>
                    )}
                    {r.details && <p className="mt-1 text-xs text-slate-500">Details: {r.details}</p>}
                    {r.escalated_at && (
                      <p className="mt-1 text-xs text-amber-600">
                        Handed to Netvork by {r.escalated_by} on {r.escalated_at.slice(0, 16)}: “{r.escalation_note}”
                      </p>
                    )}
                    <p className="mt-0.5 text-[11px] text-slate-400">
                      {r.created_at?.slice(0, 16)}
                      {r.reviewer && ` · ${r.action_taken} by ${r.reviewer}`}
                      {r.action_note && ` · “${r.action_note}”`}
                    </p>
                  </div>

                  {r.status === 'open' && !r.escalated_at && (
                    <div className={clsx('flex flex-wrap gap-1.5')}>
                      <Button size="sm" variant="secondary" onClick={() => act(r.uuid, 'dismiss')}>
                        <Check className="size-3.5" /> Dismiss
                      </Button>
                      <Button size="sm" variant="secondary" onClick={() => act(r.uuid, 'warn')}>
                        <Ban className="size-3.5" /> Warn
                      </Button>
                      {r.message && !r.message.deleted_at && (
                        <Button size="sm" variant="danger" onClick={() => act(r.uuid, 'delete_message')}>
                          <Trash2 className="size-3.5" /> Take down
                        </Button>
                      )}
                      <Button size="sm" variant="secondary" onClick={() => act(r.uuid, 'escalate')} title="Beyond the company's powers - e.g. suspending the account">
                        <ArrowUpRight className="size-3.5" /> Hand to Netvork
                      </Button>
                    </div>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
