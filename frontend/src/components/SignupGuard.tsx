import { useEffect, useRef, useState } from 'react'

/**
 * The fields that tell a script from a person.
 *
 * Three layers, and two of them cost the page nothing. The honeypot is a
 * field a person never sees and never fills; an automated form-filler fills
 * it because it fills everything. The clock records when the form appeared,
 * so the server can refuse one submitted faster than anybody could read it.
 *
 * Turnstile is Cloudflare's challenge, and it only appears once a site key is
 * configured — the page works exactly as before until somebody adds one, and
 * the other two layers apply either way.
 */

/** Read once: a site key that changes mid-session is not a thing. */
const SITE_KEY = import.meta.env.VITE_TURNSTILE_SITE_KEY as string | undefined

export interface GuardFields {
  company_website: string
  form_started_at: number
  turnstile_token?: string
}

/**
 * The values to send with the form.
 *
 * `startedAt` is captured on mount rather than on submit, which is the whole
 * point: the gap between the two is what says a person read the page.
 */
export function useSignupGuard() {
  const startedAt = useRef(Date.now())
  const [honeypot, setHoneypot] = useState('')
  const [token, setToken] = useState('')

  return {
    fields: {
      company_website: honeypot,
      form_started_at: startedAt.current,
      ...(token ? { turnstile_token: token } : {}),
    } as GuardFields,
    honeypot,
    setHoneypot,
    setToken,
    /** Whether a challenge is expected but not yet solved. */
    waiting: !!SITE_KEY && !token,
  }
}

/**
 * The invisible half.
 *
 * Hidden from sight AND from assistive technology: a screen reader announcing
 * "Company website" would make a real person fill in the trap. Kept out of the
 * tab order for the same reason.
 */
export function HoneypotField({
  value,
  onChange,
}: {
  value: string
  onChange: (v: string) => void
}) {
  return (
    <div aria-hidden="true" className="absolute -left-[9999px] top-0 h-0 w-0 overflow-hidden">
      <label htmlFor="company_website">Company website</label>
      <input
        id="company_website"
        name="company_website"
        type="text"
        tabIndex={-1}
        autoComplete="off"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  )
}

/**
 * Cloudflare's widget, rendered only when there is a key to render it with.
 *
 * The script is loaded on demand rather than in index.html, so a deployment
 * without a key never fetches it at all.
 */
export function TurnstileWidget({ onToken }: { onToken: (token: string) => void }) {
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!SITE_KEY || !box.current) return

    let cancelled = false

    const render = () => {
      const turnstile = (window as unknown as { turnstile?: {
        render: (el: HTMLElement, opts: Record<string, unknown>) => void
      } }).turnstile

      if (cancelled || !turnstile || !box.current) return

      turnstile.render(box.current, {
        sitekey: SITE_KEY,
        callback: onToken,
        // A token that expired while somebody filled the form is no token.
        'expired-callback': () => onToken(''),
        'error-callback': () => onToken(''),
        theme: 'auto',
      })
    }

    const existing = document.querySelector<HTMLScriptElement>('script[data-turnstile]')

    if (existing) {
      existing.addEventListener('load', render)
      render()
    } else {
      const script = document.createElement('script')
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
      script.async = true
      script.defer = true
      script.dataset.turnstile = 'true'
      script.addEventListener('load', render)
      document.head.appendChild(script)
    }

    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!SITE_KEY) return null

  return <div ref={box} className="mt-3 flex justify-center" />
}
