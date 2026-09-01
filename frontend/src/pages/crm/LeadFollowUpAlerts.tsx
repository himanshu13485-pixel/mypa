import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlarmClock, ExternalLink, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe } from '../../api/crm'
import { Button } from '../../components/ui'

const SNOOZE_KEY = 'crm-lead-alert-snooze-until'

/**
 * The follow-up nag. When a lead's booked date and time arrives, a popup
 * lists every lead now due — one popup, however many leads, scrolling past
 * five. Dismissing it buys quiet for the minutes the Admin chose (15 by
 * default); attending a lead — a new date, a changed status — removes it
 * for real. "Open" works in a new window so the list survives, and an
 * opened lead shows Processing until its update actually lands.
 */
export function LeadFollowUpAlerts({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled && crmCan(me, 'leads', 'view')
  const [open, setOpen] = useState(false)
  // Leads someone clicked Open on: Processing until the server says done.
  const [processing, setProcessing] = useState<Set<string>>(new Set())

  const { data } = useQuery({
    queryKey: ['crm', 'leads-due'],
    queryFn: crm.leads.due,
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
  })

  /*
   * Memoised so its identity is stable between renders.
   *
   * `?? []` builds a fresh array every time it runs, and an effect that
   * depends on due then fires on every render rather than when the list
   * actually changes. Nothing breaks — the setState below returns its previous
   * value when nothing moved, so React stops there — but it is work done on a
   * loop for no reason, and it is one careless edit away from being a real
   * one.
   */
  const due = useMemo(() => data?.data ?? [], [data])
  const snoozeMinutes = data?.alert_minutes ?? 15

  // Surface the popup whenever something is due and the snooze has passed.
  useEffect(() => {
    if (due.length === 0) {
      setOpen(false)
      return
    }
    let snoozedUntil = 0
    try {
      snoozedUntil = Number(sessionStorage.getItem(SNOOZE_KEY) ?? 0)
    } catch { /* storage may be unavailable; nag rather than stay silent */ }
    if (Date.now() >= snoozedUntil) {
      setOpen(true)
    } else {
      // Wake exactly when the snooze runs out, not a poll later.
      const timer = setTimeout(() => setOpen(true), snoozedUntil - Date.now())
      return () => clearTimeout(timer)
    }
  }, [due.length])

  // A lead that left the due list was attended — stop calling it Processing.
  useEffect(() => {
    setProcessing((prev) => {
      const still = new Set([...prev].filter((uuid) => due.some((l) => l.uuid === uuid)))
      return still.size === prev.size ? prev : still
    })
  }, [due])

  if (!open || due.length === 0) return null

  const snooze = () => {
    try {
      sessionStorage.setItem(SNOOZE_KEY, String(Date.now() + snoozeMinutes * 60_000))
    } catch { /* without storage the next poll reopens it — safe direction */ }
    setOpen(false)
  }

  const openLead = (uuid: string) => {
    setProcessing((prev) => new Set(prev).add(uuid))
    window.open(`/crm/leads/${uuid}`, '_blank', 'noopener')
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <AlarmClock className="size-4 text-red-500" />
            {due.length === 1 ? 'A lead needs attending' : `${due.length} leads need attending`}
          </h2>
          <button onClick={snooze} aria-label="Remind me later" className="rounded p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
            <X className="size-4" />
          </button>
        </div>

        {/* One popup, however many leads — scrolling past five. */}
        <ul className="max-h-80 divide-y divide-slate-50 overflow-y-auto px-5 dark:divide-slate-800/60">
          {due.map((lead) => {
            const isProcessing = processing.has(lead.uuid)
            return (
              <li key={lead.uuid} className="flex items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-medium text-slate-800 dark:text-slate-100">
                      #{lead.lead_no} {lead.company_name}
                    </span>
                    {lead.is_urgent && (
                      <span className="rounded-full bg-red-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                        URGENT
                      </span>
                    )}
                    {isProcessing && (
                      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        Processing…
                      </span>
                    )}
                  </div>
                  <div className="truncate text-xs text-slate-500">
                    {[lead.contact_person, lead.mobile, lead.assigned_to && `with ${lead.assigned_to}`]
                      .filter(Boolean).join(' · ')}
                  </div>
                  <div className={clsx('text-xs', lead.overdue_minutes > 60 ? 'font-medium text-red-500' : 'text-amber-600')}>
                    due {lead.follow_up_at.slice(0, 16)}
                    {lead.overdue_minutes > 0 && <> · {lead.overdue_minutes >= 60
                      ? `${Math.floor(lead.overdue_minutes / 60)}h ${lead.overdue_minutes % 60}m late`
                      : `${lead.overdue_minutes}m late`}</>}
                  </div>
                </div>
                <Button size="sm" variant="secondary" onClick={() => openLead(lead.uuid)}>
                  <ExternalLink className="size-3.5" /> Open
                </Button>
              </li>
            )
          })}
        </ul>

        <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
          <span className="text-xs text-slate-400">
            Attending a lead — a new date or status — clears it. Otherwise this returns in {snoozeMinutes} minutes.
          </span>
          <Button size="sm" variant="secondary" onClick={snooze}>Remind me later</Button>
        </div>
      </div>
    </div>
  )
}
