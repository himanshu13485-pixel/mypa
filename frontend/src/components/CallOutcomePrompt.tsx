import { useCallback, useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { PhoneOutgoing, X } from 'lucide-react'
import { phoneCalls, type PhoneCallRow } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useToast } from './Toast'
import { Button, Input, Textarea } from './ui'

/**
 * "How did that go?", asked when somebody comes back from the dialler.
 *
 * The app can record that a call was placed and never how it went, because
 * Android does not let an app watch a cellular call. So the only way the
 * outcome and the length ever reach a lead's history is for the person who
 * made the call to say — and the only moment they will is the one where they
 * have just put the phone down and opened the app again.
 *
 * Which is exactly what this listens for. A prompt on a timer would arrive
 * mid-call; a prompt on the next page load would arrive tomorrow. Coming back
 * to the tab is the signal, and it is a good one.
 */
const OUTCOMES: { key: string; label: string; talked: boolean }[] = [
  { key: 'connected', label: 'Spoke to them', talked: true },
  { key: 'no_answer', label: 'No answer', talked: false },
  { key: 'busy', label: 'Busy', talked: false },
  { key: 'wrong_number', label: 'Wrong number', talked: false },
  { key: 'unreachable', label: 'Unreachable', talked: false },
]

export function CallOutcomePrompt() {
  const [call, setCall] = useState<PhoneCallRow | null>(null)
  const [outcome, setOutcome] = useState<string | null>(null)
  const [minutes, setMinutes] = useState('')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const { toastError } = useToast()
  const queryClient = useQueryClient()

  const close = useCallback(() => {
    setCall(null)
    setOutcome(null)
    setMinutes('')
    setNotes('')
  }, [])

  /*
   * Ask the server what is still unanswered whenever the tab comes back.
   *
   * Only ever one at a time, and only the most recent — somebody who made
   * four calls in a row wants to log the one they just finished, not to face
   * a queue of four before they can do anything else.
   */
  useEffect(() => {
    let cancelled = false

    const check = () => {
      if (document.visibilityState !== 'visible') return

      phoneCalls.pending()
        .then((rows) => {
          if (cancelled || rows.length === 0) return
          // Don't interrupt somebody already answering about another call.
          setCall((current) => current ?? rows[0])
        })
        .catch(() => undefined)
    }

    document.addEventListener('visibilitychange', check)
    window.addEventListener('focus', check)
    check()

    return () => {
      cancelled = true
      document.removeEventListener('visibilitychange', check)
      window.removeEventListener('focus', check)
    }
  }, [])

  if (!call) return null

  const talked = OUTCOMES.find((o) => o.key === outcome)?.talked ?? false

  const save = () => {
    if (!outcome) return
    setSaving(true)

    phoneCalls.logOutcome(call.uuid, {
      outcome,
      // Minutes are what a person remembers; seconds are what is stored.
      duration_seconds: talked && minutes ? Math.round(Number(minutes) * 60) : null,
      notes: notes.trim() || undefined,
    })
      .then(() => {
        queryClient.invalidateQueries({ queryKey: ['crm', 'lead-calls'] })
        queryClient.invalidateQueries({ queryKey: ['calls-history'] })
        close()
      })
      .catch((err) => toastError(errorMessage(err)))
      .finally(() => setSaving(false))
  }

  return (
    /*
     * A card at the foot rather than a modal over everything. Somebody coming
     * back from a call is usually going somewhere — the next lead, their
     * notes — and a dialog that has to be dealt with before the app will
     * respond is one people learn to dismiss without reading.
     */
    <div className="fixed inset-x-0 bottom-0 z-40 px-3 pb-3 pb-safe sm:left-auto sm:right-4 sm:w-96">
      <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-lift dark:border-slate-700 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-2">
          <p className="flex items-center gap-2 text-sm font-medium">
            <PhoneOutgoing className="size-4 text-emerald-500" />
            How did the call to {call.label || call.number} go?
          </p>
          <button
            className="tap rounded-lg p-1 text-slate-400 hover:text-slate-600"
            aria-label="Not now"
            onClick={close}
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="mt-3 flex flex-wrap gap-1.5">
          {OUTCOMES.map((o) => (
            <button
              key={o.key}
              type="button"
              onClick={() => setOutcome(o.key)}
              className={
                outcome === o.key
                  ? 'rounded-full bg-brand-600 px-3 py-1.5 text-xs font-medium text-white'
                  : 'rounded-full bg-slate-100 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'
              }
            >
              {o.label}
            </button>
          ))}
        </div>

        {/* Only where there was a conversation to have a length. */}
        {talked && (
          <div className="mt-3 flex items-center gap-2">
            <Input
              type="number"
              min={0}
              inputMode="numeric"
              placeholder="Minutes"
              value={minutes}
              onChange={(e) => setMinutes(e.target.value)}
              className="w-28"
            />
            <span className="text-xs text-slate-400">roughly — nobody is timing you</span>
          </div>
        )}

        {outcome && (
          <Textarea
            rows={2}
            placeholder="Anything worth remembering?"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="mt-3 w-full"
          />
        )}

        <div className="mt-3 flex justify-end gap-2">
          <Button variant="secondary" size="sm" onClick={close}>Not now</Button>
          <Button size="sm" disabled={!outcome || saving} onClick={save}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </div>
    </div>
  )
}
