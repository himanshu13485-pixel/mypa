import { useState } from 'react'
import { clsx } from 'clsx'
import { Phone, Smartphone } from 'lucide-react'
import { dial } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { mailtoHref, telHref } from '../lib/contactLinks'
import { useMediaQuery } from '../lib/useMediaQuery'
import { useToast } from './Toast'

/**
 * A phone number you can ring, and an address you can write to.
 *
 * These were plain text everywhere in the CRM, which on a phone means reading
 * a number off the screen and typing it into the dialler — for a sales team
 * whose whole day is ringing leads, that is the one thing the number is there
 * for.
 *
 * A `tel:` link hands the number to the dialler with it already entered; the
 * person still presses the green button, so nothing is ever dialled by a
 * mis-tap. On a desktop it does whatever that machine is set up to do —
 * usually nothing, sometimes Teams — and is harmless either way.
 *
 * Falls back to plain text rather than an inert link when the value is not
 * dialable. A link that visibly does nothing is worse than no link.
 */
export function PhoneLink({
  value,
  className,
  icon = false,
}: {
  value?: string | null
  className?: string
  /** A handset beside the number, where the row has no label of its own. */
  icon?: boolean
}) {
  const href = value ? telHref(value) : null

  if (!value) return null
  if (!href) return <>{value}</>

  return (
    <a
      href={href}
      className={clsx('inline-flex items-center gap-1 hover:underline', className)}
      // The row underneath is often clickable too — a lead's row opens the
      // lead. Ringing somebody is not a navigation, and must not also be one.
      onClick={(e) => e.stopPropagation()}
    >
      {icon && <Phone className="size-3.5 shrink-0 text-slate-400" />}
      {value}
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

/**
 * "Ring this on my phone" — for somebody sitting at a laptop.
 *
 * A tel: link is the answer on a phone and close to useless on a desktop:
 * unless Teams or a softphone has claimed the protocol, clicking it does
 * nothing at all. This sends the number to the handset the person already
 * has in their pocket, whose SIM makes the call for free.
 *
 * Only drawn where hovering is possible, which is the same question as "is
 * this a machine that cannot dial" — on a phone the number beside it already
 * rings, and two call buttons on one row is a choice nobody needs to make.
 */
export function DialOnPhoneButton({
  value,
  label,
  className,
}: {
  value?: string | null
  label?: string | null
  className?: string
}) {
  const canHover = useMediaQuery('(hover: hover)')
  const { toast, toastError } = useToast()
  const [sending, setSending] = useState(false)

  if (!value || !canHover || !telHref(value)) return null

  return (
    <button
      type="button"
      title="Ring this number on my phone"
      disabled={sending}
      className={clsx(
        'inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs text-slate-400 hover:bg-slate-100 hover:text-brand-600 disabled:opacity-50 dark:hover:bg-slate-800',
        className,
      )}
      onClick={(e) => {
        // The row underneath opens the record. Ringing is not a navigation.
        e.stopPropagation()
        setSending(true)
        dial.toMyPhone(value, label ?? undefined)
          .then(() => toast('Sent to your phone.', 'success'))
          .catch((err) => toastError(errorMessage(err)))
          .finally(() => setSending(false))
      }}
    >
      <Smartphone className="size-3.5" />
      {sending ? 'Sending…' : 'My phone'}
    </button>
  )
}
