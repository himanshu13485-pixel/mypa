import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Phone } from 'lucide-react'
import { telHref } from '../lib/contactLinks'
import { Button } from '../components/ui'

/**
 * Where a "call this" notification lands when it is tapped.
 *
 * The websocket handles the case where the app was already open; this is the
 * other one — the phone was in a pocket, the notification arrived, and
 * tapping it opened the app here. The shell's existing tap handler navigates
 * to whatever url the push carried, so this page needed no native change at
 * all: it is an ordinary route that happens to dial.
 *
 * It tries once on arrival and then stops, leaving a button. Retrying would
 * mean a page that reopens the dialler every time somebody backs out of it,
 * which is a trap rather than a convenience — and a browser that blocks the
 * automatic attempt still leaves a working way to place the call.
 */
export default function DialPage() {
  const [params] = useSearchParams()
  const raw = params.get('number') ?? ''
  const href = raw ? telHref(raw) : null
  const [tried, setTried] = useState(false)

  useEffect(() => {
    if (!href || tried) return
    setTried(true)
    window.location.href = href
  }, [href, tried])

  return (
    <div className="mx-auto flex max-w-sm flex-col items-center gap-4 px-4 py-16 text-center">
      <Phone className="size-10 text-brand-600" />

      {href ? (
        <>
          <p className="text-lg font-semibold tracking-tight">{raw}</p>
          <p className="text-sm text-slate-500">
            Your dialler should have opened. If it did not, use the button.
          </p>
          <Button onClick={() => { window.location.href = href }}>
            <Phone className="size-4" /> Call {raw}
          </Button>
        </>
      ) : (
        <p className="text-sm text-slate-500">
          There is no number to call here.
        </p>
      )}
    </div>
  )
}
