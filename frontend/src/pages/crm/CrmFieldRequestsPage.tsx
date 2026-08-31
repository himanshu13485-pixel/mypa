import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Check, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_DCW_ENTITY_LABELS, CRM_FIELD_TYPE_LABELS } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Pager, Select, Spinner } from '../../components/ui'

/**
 * The Super Admin's DCW queue: field requests from every company. Approving
 * one puts it on that company's form and nobody else's.
 */
export default function CrmFieldRequestsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [status, setStatus] = useState('pending')
  const [organization, setOrganization] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'field-requests', status, organization],
    queryFn: () => crm.fieldRequests.list({ status: status || undefined, organization: organization || undefined }),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, verdict }: { uuid: string; verdict: 'approved' | 'rejected' }) => {
      const note = verdict === 'rejected' ? prompt('Why is this being rejected?') ?? undefined : undefined
      return crm.fieldRequests.decide(uuid, verdict, note)
    },
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'field-requests'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'masters'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Field requests</h1>
          <p className="text-sm text-slate-500">
            Dedicated Company Workspace requests — approving one adds the field to that company only.
            {data && data.pending_count > 0 && <span className="ml-1 font-medium text-amber-600">{data.pending_count} waiting.</span>}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Select value={organization} onChange={(e) => setOrganization(e.target.value)} title="Filter by company">
            <option value="">All companies</option>
            {(data?.organizations ?? []).map((o) => <option key={o.uuid} value={o.uuid}>{o.name}</option>)}
          </Select>
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="">All</option>
          </Select>
        </div>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Nothing here" hint="Field requests from companies appear here for approval." />
        ) : (
          <div className="space-y-2">
            {data.data.map((f) => (
              <div key={f.uuid} className="rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                <div className="flex flex-wrap items-center gap-2">
                  <Building2 className="size-4 shrink-0 text-emerald-500" />
                  <span className="font-medium text-slate-800 dark:text-slate-100">{f.organization?.name}</span>
                  <span className="text-slate-400">wants</span>
                  <span className="font-medium text-slate-800 dark:text-slate-100">"{f.label}"</span>
                  <span className={clsx(
                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                    f.status === 'approved' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                    f.status === 'pending' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                    f.status === 'rejected' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                  )}>
                    {f.status}
                  </span>
                  {f.status === 'pending' && (
                    <div className="ml-auto flex gap-1">
                      <Button size="sm" onClick={() => decideMutation.mutate({ uuid: f.uuid, verdict: 'approved' })}>
                        <Check className="size-3.5" /> Approve
                      </Button>
                      <Button size="sm" variant="secondary" onClick={() => decideMutation.mutate({ uuid: f.uuid, verdict: 'rejected' })}>
                        <X className="size-3.5" /> Reject
                      </Button>
                    </div>
                  )}
                </div>
                <div className="mt-1 text-xs text-slate-400">
                  {CRM_DCW_ENTITY_LABELS[f.entity] ?? f.entity} · {CRM_FIELD_TYPE_LABELS[f.type] ?? f.type}
                  {f.is_builtin && <> · re-words the built-in “{f.key}” column</>}
                  {f.is_hidden && <> · asks to hide it</>}
                  {f.is_required && ' · required'}
                  {f.options && f.options.length > 0 && <> · options: {f.options.join(', ')}</>}
                </div>
                {/* Received when and from whom; decided when and by whom. */}
                <div className="mt-0.5 text-xs text-slate-400">
                  Received {f.created_at?.slice(0, 16) ?? '—'}
                  {f.requested_by && <> from {f.requested_by}</>}
                  {f.decided_at && (
                    <> · {f.status === 'approved' ? 'Approved' : 'Rejected'} {f.decided_at.slice(0, 16)}
                      {f.decided_by && <> by {f.decided_by}</>}</>
                  )}
                </div>
                {f.reason && <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">"{f.reason}"</p>}
                {f.decision_note && <p className="mt-1 text-xs text-red-400">Note: {f.decision_note}</p>}
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={() => undefined} />
      </Card>
    </div>
  )
}
