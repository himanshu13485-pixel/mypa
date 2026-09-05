import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, ShieldAlert, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe } from '../../api/crm'
import { Button } from '../../components/ui'
import { crmPath } from '../../lib/crmPath'

const SNOOZE_KEY = 'crm-complaint-alert-snooze-until'

/**
 * The CMS nag, same shape as the leads popup: every open complaint that is
 * THIS person's to answer — allocated to them, or unattended for a manager
 * — rides one popup, several at a time. Attending one (allocating it,
 * moving it forward, closing it) clears it; dismissing buys the same quiet
 * minutes the leads alert uses.
 */
export function ComplaintAlerts({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled && crmCan(me, 'complaints', 'view')
  const [open, setOpen] = useState(false)

  const { data } = useQuery({
    queryKey: ['crm', 'complaints-due'],
    queryFn: crm.complaints.due,
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
  })

  const due = data?.data ?? []
  const snoozeMinutes = data?.alert_minutes ?? 15

  useEffect(() => {
    if (due.length === 0) {
      setOpen(false)
      return
    }
    let snoozedUntil = 0
    try {
      snoozedUntil = Number(sessionStorage.getItem(SNOOZE_KEY) ?? 0)
    } catch { /* nag rather than stay silent */ }
    if (Date.now() >= snoozedUntil) {
      setOpen(true)
    } else {
      const timer = setTimeout(() => setOpen(true), snoozedUntil - Date.now())
      return () => clearTimeout(timer)
    }
  }, [due.length])

  if (!open || due.length === 0) return null

  const snooze = () => {
    try {
      sessionStorage.setItem(SNOOZE_KEY, String(Date.now() + snoozeMinutes * 60_000))
    } catch { /* the next poll reopens it — safe direction */ }
    setOpen(false)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <ShieldAlert className="size-4 text-orange-500" />
            {due.length === 1 ? 'A complaint needs answering' : `${due.length} complaints need answering`}
          </h2>
          <button onClick={snooze} aria-label="Remind me later" className="rounded p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
            <X className="size-4" />
          </button>
        </div>

        <ul className="max-h-80 divide-y divide-slate-50 overflow-y-auto px-5 dark:divide-slate-800/60">
          {due.map((c) => (
            <li key={c.uuid} className="flex items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <span className="font-medium text-slate-800 dark:text-slate-100">
                    {c.cms_no} — {c.company_name ?? 'Complaint'}
                  </span>
                  {c.priority === 'urgent' && (
                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-500/15 dark:text-red-300">
                      Urgent
                    </span>
                  )}
                </div>
                {c.subject && <div className="truncate text-xs text-slate-500">{c.subject}</div>}
                <div className={clsx('text-xs', c.overdue ? 'font-medium text-red-500' : 'text-slate-400')}>
                  {c.allocated_to ? `with ${c.allocated_to}` : 'unallocated'}
                  {c.due_at && <> · due {c.due_at.slice(0, 16)}</>}
                  {c.overdue && ' · OVERDUE'}
                </div>
              </div>
              <Button size="sm" variant="secondary" onClick={() => window.open(crmPath(`/crm/complaints/${c.uuid}`), '_blank', 'noopener')}>
                <ExternalLink className="size-3.5" /> Open
              </Button>
            </li>
          ))}
        </ul>

        <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
          <span className="text-xs text-slate-400">
            Moving a complaint forward clears it. Otherwise this returns in {snoozeMinutes} minutes.
          </span>
          <Button size="sm" variant="secondary" onClick={snooze}>Remind me later</Button>
        </div>
      </div>
    </div>
  )
}
