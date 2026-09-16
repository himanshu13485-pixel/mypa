import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, PackageCheck, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_DISPATCH_STATUS_LABELS, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { Button, Input } from '../../components/ui'
import { useToast } from '../../components/Toast'
import { crmPath } from '../../lib/crmPath'

const SNOOZE_KEY = 'crm-dispatch-alert-snooze-until'

/** Long enough not to be a tic, short enough that the day does not pass. */
const SNOOZE_MINUTES = 60

/** Past this, waiting stops being ordinary and the row says so in red. */
const LONG_WAIT_DAYS = 7

/** The date input's floor, in the reader's own day rather than UTC's. */
function todayIso(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/**
 * The dispatch nag, same shape as the leads and tasks popups: every document
 * whose goods have not gone out rides one popup, however many there are.
 *
 * Whose popup it is, is the server's answer and not this component's: the
 * due list comes back empty for anybody who is not an Admin or a Subadmin,
 * and empty again for a company that has switched the chasing off. So there
 * is no role check here to drift out of step with the one that matters.
 *
 * Two ways of putting a row off, and they mean different things. "Later" is
 * the company's own rhythm, the repeat_days the Admin set. "Remind me on…"
 * is a date this person picked, because the office often knows exactly when
 * the lorry is coming. Both are the server's to record, so every screen in
 * the company agrees; dismissing the whole popup is neither, and buys quiet
 * on this browser alone.
 */
export function DispatchAlerts({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [open, setOpen] = useState(false)
  // The date each row is being put off to, until it is sent.
  const [dates, setDates] = useState<Record<string, string>>({})

  const { data } = useQuery({
    queryKey: ['crm', 'dispatch-due'],
    queryFn: crm.dispatch.due,
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
  })

  /*
   * Memoised so its identity is stable between renders - an effect that
   * depends on a fresh array fires on every render rather than when the
   * list actually changes.
   */
  const due = useMemo(() => data?.data ?? [], [data])
  const repeatDays = data?.schedule?.repeat_days ?? 0

  const deferMutation = useMutation({
    mutationFn: ({ uuid, until }: { uuid: string; until?: string }) => crm.dispatch.defer(uuid, until),
    onSuccess: (res) => {
      // The deferred row leaves the due list on the refetch, so the popup
      // shrinks by itself and closes when it empties.
      queryClient.invalidateQueries({ queryKey: ['crm', 'dispatch-due'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  useEffect(() => {
    if (due.length === 0) {
      setOpen(false)
      return
    }

    let snoozedUntil = 0
    try {
      snoozedUntil = Number(sessionStorage.getItem(SNOOZE_KEY) ?? 0)
    } catch { /* storage may be unavailable; ask rather than stay silent */ }

    if (Date.now() >= snoozedUntil) {
      setOpen(true)
    } else {
      // Wake exactly when the quiet runs out, not a poll later.
      const timer = setTimeout(() => setOpen(true), snoozedUntil - Date.now())
      return () => clearTimeout(timer)
    }
  }, [due.length])

  if (!open || due.length === 0) return null

  const snooze = () => {
    try {
      sessionStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MINUTES * 60_000))
    } catch { /* without storage the next poll reopens it - safe direction */ }
    setOpen(false)
  }

  const openDoc = (uuid: string) => {
    // A new window, so the list survives being read from.
    window.open(crmPath(`/crm/invoices/${uuid}`), '_blank', 'noopener')
  }

  const min = todayIso()

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3.5 dark:border-slate-800 sm:px-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <PackageCheck className="size-4 text-amber-500" />
            {due.length === 1 ? '1 dispatch has not gone out' : `${due.length} dispatches have not gone out`}
          </h2>
          <button onClick={snooze} aria-label="Remind me later" className="rounded p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
            <X className="size-4" />
          </button>
        </div>

        {/* One popup, however many documents - scrolling past five. */}
        <ul className="max-h-80 divide-y divide-slate-50 overflow-y-auto px-4 dark:divide-slate-800/60 sm:px-5">
          {due.map((doc) => {
            // Only the row actually in the air says it is working.
            const isDeferring = deferMutation.isPending && deferMutation.variables?.uuid === doc.uuid
            const waiting = doc.waiting_days
            return (
              <li key={doc.uuid} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-medium text-slate-800 dark:text-slate-100">{doc.number}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      {CRM_DISPATCH_STATUS_LABELS[doc.dispatch_status] ?? doc.dispatch_status}
                    </span>
                    {isDeferring && (
                      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        Putting off…
                      </span>
                    )}
                  </div>
                  <div className="truncate text-xs text-slate-500">
                    {[doc.client, doc.contact_person, doc.salesperson && `with ${doc.salesperson}`]
                      .filter(Boolean).join(' · ')}
                  </div>
                  {waiting !== null && (
                    // How long the client has been waiting is the whole
                    // argument for ringing the warehouse, so it is read
                    // plainly and turns red once it is no longer ordinary.
                    <div className={clsx('text-xs', waiting >= LONG_WAIT_DAYS ? 'font-medium text-red-500' : 'text-amber-600')}>
                      {waiting <= 0 ? 'waiting since today' : waiting === 1 ? 'waiting 1 day' : `waiting ${waiting} days`}
                      {doc.invoice_date && ` · dated ${doc.invoice_date.slice(0, 10)}`}
                    </div>
                  )}
                </div>

                <div className="flex flex-col gap-1.5 sm:shrink-0 sm:items-end">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Input
                      type="date"
                      min={min}
                      aria-label={`Remind me about ${doc.number} on`}
                      value={dates[doc.uuid] ?? ''}
                      onChange={(e) => setDates((prev) => ({ ...prev, [doc.uuid]: e.target.value }))}
                      className="w-[9.5rem] py-1.5 text-xs"
                    />
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={!dates[doc.uuid] || isDeferring}
                      onClick={() => deferMutation.mutate({ uuid: doc.uuid, until: dates[doc.uuid] })}
                    >
                      Remind me on…
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => openDoc(doc.uuid)}>
                      <ExternalLink className="size-3.5" /> Open
                    </Button>
                  </div>
                  <button
                    onClick={() => deferMutation.mutate({ uuid: doc.uuid })}
                    disabled={isDeferring}
                    className="self-start text-[11px] text-slate-400 hover:text-slate-600 disabled:opacity-50 dark:hover:text-slate-200 sm:self-end"
                  >
                    Later
                  </button>
                </div>
              </li>
            )
          })}
        </ul>

        <div className="flex flex-col gap-2 border-t border-slate-100 px-4 py-3 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:px-5">
          <span className="text-xs text-slate-400">
            Marking the goods dispatched clears a row for good. “Later” asks again
            {repeatDays > 0 ? ` in ${repeatDays} days` : ' on the company’s own rhythm'}.
          </span>
          <Button size="sm" variant="secondary" className="self-start sm:self-auto" onClick={snooze}>Remind me later</Button>
        </div>
      </div>
    </div>
  )
}
