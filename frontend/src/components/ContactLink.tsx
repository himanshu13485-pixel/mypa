import { useState } from 'react'
import { clsx } from 'clsx'
import { Phone, Smartphone } from 'lucide-react'
import { dial, phoneCalls } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { mailtoHref, telHref } from '../lib/contactLinks'
import { useMediaQuery } from '../lib/useMediaQuery'
import { useToast } from './Toast'
import { Button, Modal } from './ui'

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
 *
 * And it asks first. A number sitting in a table is a thing people tap by
 * accident — scrolling a lead list on a phone, clicking through a row on a
 * laptop — and the accident here rings a customer. One tap is a fine way to
 * start a call and a terrible way to make one by mistake.
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
  const [asking, setAsking] = useState(false)

  const href = value ? telHref(value) : null

  if (!value) return null
  if (!href) return <>{value}</>

  /** Only once the call is actually going ahead — a cancelled one is not a call. */
  const log = (from: 'phone' | 'laptop') => {
    void phoneCalls.placed({
      number: value,
      label: label ?? undefined,
      placed_from: from,
      ...(subject ? { subject_type: subject.type, subject_uuid: subject.uuid } : {}),
    }).catch(() => undefined)
  }

  const sendToPhone = () => {
    setAsking(false)
    setSending(true)
    /*
     * Logged and dialled together. The log is the thing the CRM keeps, so a
     * failure to record it must not stop the call — the ring is what the
     * person clicked for.
     */
    log('laptop')

    dial.toMyPhone(value, label ?? undefined)
      .then(() => toast('Sent to your phone.', 'success'))
      .catch((err) => toastError(errorMessage(err)))
      .finally(() => setSending(false))
  }

  return (
    <>
      <button
        type="button"
        title={canHover ? 'Ring this on my phone' : 'Call this number'}
        disabled={sending}
        className={clsx('inline-flex items-center gap-1 hover:text-brand-600 hover:underline disabled:opacity-50', className)}
        onClick={(e) => {
          // The row underneath opens the record. Ringing is not a navigation.
          e.stopPropagation()
          setAsking(true)
        }}
      >
        {icon && <Phone className="size-3.5 shrink-0 text-slate-400" />}
        {sending ? 'Sending…' : value}
      </button>

      {/*
        * Wrapped, because the dialog is portalled to <body> but still a React
        * child of this number — so a click inside it, or on its backdrop,
        * bubbles to whatever row this number sits in. Answering "cancel" is
        * not a request to open the record.
        */}
      <span onClick={(e) => e.stopPropagation()}>
        {asking && (
        <Modal title={label ? `Call ${label}?` : 'Call this number?'} onClose={() => setAsking(false)}>
          <div className="space-y-4">
            <div className="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
              <Phone className="size-5 shrink-0 text-emerald-500" />
              <div className="min-w-0">
                <div className="truncate font-medium tabular-nums text-slate-800 dark:text-slate-100">{value}</div>
                {label && <div className="truncate text-xs text-slate-400">{label}</div>}
              </div>
            </div>

            {/* What is about to happen differs by device, so it is said
                rather than left to be discovered. */}
            <p className="flex items-start gap-2 text-xs text-slate-500">
              <Smartphone className="mt-0.5 size-3.5 shrink-0" />
              {canHover
                ? 'Your phone rings first — answer it and it dials the number on your own SIM.'
                : 'This opens your dialler with the number entered.'}
            </p>

            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setAsking(false)}>Cancel</Button>
              {/*
                * A real link on a phone, not a scripted navigation.
                *
                * tel: from JavaScript relies on the browser still counting the
                * click as a user gesture two promises later, and Android
                * WebViews are the place that stops being true. A plain <a> the
                * person taps is a gesture by construction — and this is an
                * app people use inside a WebView all day.
                */}
              {canHover ? (
                <Button onClick={sendToPhone}>
                  <Phone className="size-4" /> Ring my phone
                </Button>
              ) : (
                <a
                  href={href}
                  onClick={() => { log('phone'); setAsking(false) }}
                  className="tap inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                >
                  <Phone className="size-4" /> Call
                </a>
              )}
            </div>
          </div>
        </Modal>
        )}
      </span>
    </>
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
