import { clsx } from 'clsx'
import { Phone } from 'lucide-react'
import { mailtoHref, telHref } from '../lib/contactLinks'

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
