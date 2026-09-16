import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote, ExternalLink, Phone, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_PAYMENT_STATUS_LABELS, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { Button, Input } from '../../components/ui'
import { useToast } from '../../components/Toast'
import { crmPath } from '../../lib/crmPath'
import { money } from '../../lib/money'

const SNOOZE_KEY = 'crm-payment-due-alert-snooze-until'

/** Long enough not to be a tic, short enough that the day does not pass. */
const SNOOZE_MINUTES = 60

/** Past a fortnight, money owed stops being ordinary and the row says so in red. */
const LONG_OVERDUE_DAYS = 14

/** The date input's floor, in the reader's own day rather than UTC's. */
function todayIso(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/**
 * The money nag, the dispatch popup's twin: every sale of this person's that
 * has not been paid rides one popup, however many there are.
 *
 * The company already writes to the client about an unpaid invoice. Nobody
 * told the salesperson, who is the one with the relationship and the phone -
 * so this is their own nudge about their own sales, and the mobile is on the
 * row because ringing is what it is for.
 *
 * Whose popup it is, is the server's answer and not this component's: the due
 * list only ever carries the reader's own sales, and comes back empty for a
 * company that has switched the chasing off. So there is no role check here
 * to drift out of step with the one that matters.
 *
 * Two ways of putting a row off, and they mean different things. "Later" is
 * the company's own rhythm, the repeat_days the Admin set. "Remind me on…" is
 * a date this person picked, because a client who has been rung usually names
 * the day they will pay. Both are the server's to record, so every screen in
 * the company agrees; dismissing the whole popup is neither, and buys quiet on
 * this browser alone.
 */
export function PaymentDueAlerts({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [open, setOpen] = useState(false)
  // The date each row is being put off to, until it is sent.
  const [dates, setDates] = useState<Record<string, string>>({})

  const { data } = useQuery({
    queryKey: ['crm', 'payment-due'],
    queryFn: crm.paymentChase.due,
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
    mutationFn: ({ uuid, until }: { uuid: string; until?: string }) => crm.paymentChase.defer(uuid, until),
    onSuccess: (res) => {
      // The deferred row leaves the due list on the refetch, so the popup
      // shrinks by itself and closes when it empties.
      queryClient.invalidateQueries({ queryKey: ['crm', 'payment-due'] })
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
            <Banknote className="size-4 text-amber-500" />
            {due.length === 1 ? '1 of your sales is unpaid' : `${due.length} of your sales are unpaid`}
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
            const overdue = doc.overdue_days
            return (
              <li key={doc.uuid} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-medium text-slate-800 dark:text-slate-100">{doc.number}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      {CRM_PAYMENT_STATUS_LABELS[doc.payment_status] ?? doc.payment_status}
                    </span>
                    {isDeferring && (
                      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        Putting off…
                      </span>
                    )}
                  </div>
                  <div className="truncate text-xs text-slate-500">
                    {[doc.client, doc.contact_person].filter(Boolean).join(' · ')}
                  </div>
                  {doc.mobile && (
                    // The number is the whole point of this popup: the letters
                    // to the client have already gone, and what is left is
                    // somebody ringing. So it is a tap-to-dial on a phone.
                    <a
                      href={`tel:${doc.mobile}`}
                      className="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-emerald-600 hover:underline dark:text-emerald-400"
                    >
                      <Phone className="size-3" /> {doc.mobile}
                    </a>
                  )}
                  {/* The balance is the figure being chased; the total and what
                      has come in are only there to make sense of it. Each in
                      the document's own currency, which is not always rupees. */}
                  <div className="text-xs text-slate-500">
                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                      {money(doc.balance, doc.currency)}
                    </span>
                    {' owed '}
                    <span className="text-slate-400">
                      of {money(doc.total, doc.currency)} · {money(doc.received, doc.currency)} received
                    </span>
                  </div>
                  {/* How late the money is, is the argument for making the
                      call, so it is read plainly and turns red once waiting
                      has stopped being ordinary. */}
                  <div className={clsx('text-xs', overdue >= LONG_OVERDUE_DAYS ? 'font-medium text-red-500' : 'text-amber-600')}>
                    {overdue <= 0 ? 'due today' : overdue === 1 ? '1 day overdue' : `${overdue} days overdue`}
                    {doc.due_date && ` · due ${doc.due_date.slice(0, 10)}`}
                  </div>
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
            The payment landing clears a row for good. “Later” asks again
            {repeatDays > 0 ? ` in ${repeatDays} days` : ' on the company’s own rhythm'}.
          </span>
          <Button size="sm" variant="secondary" className="self-start sm:self-auto" onClick={snooze}>Remind me later</Button>
        </div>
      </div>
    </div>
  )
}
