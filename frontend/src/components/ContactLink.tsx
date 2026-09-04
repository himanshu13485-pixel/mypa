import { useState } from 'react'
import { clsx } from 'clsx'
import { Phone } from 'lucide-react'
import { dial, phoneCalls } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { mailtoHref, telHref } from '../lib/contactLinks'
import { useMediaQuery } from '../lib/useMediaQuery'
import { useToast } from './Toast'

/**
 * A phone number you can ring — by whatever means this machine actually has.
 *
 * The same number behaves differently because the devices do:
 *
 *   On a phone it is a tel: link, which opens the dialler with the number
 *   entered. That is the whole job there.
 *
 *   On a laptop a tel: link is worse than nothing. Unless a softphone has
 *   claimed the protocol, Windows answers with "Select an app to open this
 *   'tel' link" and offers Chrome, Edge and People — none of which can place
 *   a call. So on a laptop the number instead sends itself to the phone in
 *   the person's pocket, whose SIM makes the call for free.
 *
 * The number itself carries the action rather than a button beside it,
 * because clicking the number is what people do — it is the thing that looks
 * like a phone number, and a separate control next to it is one somebody has
 * to be told about.
 */
export function PhoneLink({
  value,
  className,
  label,
  subject,
  icon = false,
}: {
  value?: string | null
  className?: string
  /** Who is being rung, for the phone to show while it dials. */
  label?: string | null
  /**
   * The record this number belongs to, so the call lands on its history.
   * Without it the call is still logged to the caller's own list — it simply
   * is not attached to anything.
   */
  subject?: { type: 'lead' | 'client' | 'complaint'; uuid: string }
  /** A handset beside the number, where the row has no label of its own. */
  icon?: boolean
}) {
  const canHover = useMediaQuery('(hover: hover)')
  const { toast, toastError } = useToast()
  const [sending, setSending] = useState(false)

  const href = value ? telHref(value) : null

  if (!value) return null
  if (!href) return <>{value}</>

  const inner = (
    <>
      {icon && <Phone className="size-3.5 shrink-0 text-slate-400" />}
      {sending ? 'Sending…' : value}
    </>
  )

  // A laptop cannot dial, so the click goes to the phone instead.
  if (canHover) {
    return (
      <button
        type="button"
        title="Ring this on my phone"
        disabled={sending}
        className={clsx('inline-flex items-center gap-1 hover:text-brand-600 hover:underline disabled:opacity-50', className)}
        onClick={(e) => {
          // The row underneath opens the record. Ringing is not a navigation.
          e.stopPropagation()
          setSending(true)
          /*
           * Logged and dialled together. The log is the thing the CRM keeps,
           * so a failure to record it must not stop the call — the ring is
           * what the person clicked for.
           */
          void phoneCalls.placed({
            number: value,
            label: label ?? undefined,
            placed_from: 'laptop',
            ...(subject ? { subject_type: subject.type, subject_uuid: subject.uuid } : {}),
          }).catch(() => undefined)

          dial.toMyPhone(value, label ?? undefined)
            .then(() => toast('Sent to your phone.', 'success'))
            .catch((err) => toastError(errorMessage(err)))
            .finally(() => setSending(false))
        }}
      >
        {inner}
      </button>
    )
  }

  return (
    <a
      href={href}
      className={clsx('inline-flex items-center gap-1 hover:underline', className)}
      onClick={(e) => {
        e.stopPropagation()
        /*
         * Recorded as the dialler opens. Nothing is awaited: the browser is
         * about to hand the page to the dialler, and a call that rang but was
         * not logged is far better than a log that delayed the ring.
         */
        void phoneCalls.placed({
          number: value,
          label: label ?? undefined,
          placed_from: 'phone',
          ...(subject ? { subject_type: subject.type, subject_uuid: subject.uuid } : {}),
        }).catch(() => undefined)
      }}
    >
      {inner}
    </a>
  )
}

export function EmailLink({ value, className }: { value?: string | null; className?: string }) {
  const href = value ? mailtoHref(value) : null

  if (!value) return null
  if (!href) return <>{value}</>

  return (
    <a
      href={href}
      className={clsx('hover:underline', className)}
      onClick={(e) => e.stopPropagation()}
    >
      {value}
    </a>
  )
}
