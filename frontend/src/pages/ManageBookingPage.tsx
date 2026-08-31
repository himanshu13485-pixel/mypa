import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, Video, XCircle } from 'lucide-react'
import { clsx } from 'clsx'
import { publicBookingApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, Card, ErrorNote, Skeleton } from '../components/ui'

/**
 * A booking, to whoever holds its link.
 *
 * The person who booked has no account, so the 64-character token in their
 * confirmation email is the whole credential — which makes this page both the
 * receipt and the only way to change anything. What it can do is therefore
 * deliberately small: see it, move it, call it off.
 *
 * An unknown token is a flat "we could not find that", the same answer a real
 * token for a deleted booking gives, so nothing is learned by trying.
 */

const VIEWER_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
const DAY_MS = 24 * 60 * 60 * 1000

function longWhen(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit',
  })
}

export default function ManageBookingPage() {
  const { token = '' } = useParams()
  const [moving, setMoving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const booking = useQuery({
    queryKey: ['managed-booking', token],
    queryFn: () => publicBookingApi.detail(token),
    retry: false,
  })

  const act = async (run: () => Promise<unknown>) => {
    setBusy(true)
    setError(null)
    try {
      await run()
      await booking.refetch()
      setMoving(false)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  if (booking.isLoading) {
    return (
      <Shell>
        <Card className="space-y-3">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-3 w-56" />
        </Card>
      </Shell>
    )
  }

  if (booking.isError || !booking.data) {
    return (
      <Shell>
        <Card className="text-center">
          <p className="text-sm font-medium">We could not find that booking.</p>
          <p className="mt-1 text-xs text-slate-500">
            The link may be incomplete — try copying it from your confirmation email again.
          </p>
        </Card>
      </Shell>
    )
  }

  const it = booking.data
  const cancelled = it.status === 'cancelled'
  const past = new Date(it.starts_at).getTime() < Date.now()

  return (
    <Shell>
      <Card>
        <div className="flex items-start gap-3">
          {cancelled ? (
            <XCircle className="mt-0.5 size-6 shrink-0 text-slate-400" />
          ) : (
            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-500" />
          )}
          <div className="min-w-0 flex-1">
            <h1 className={clsx('text-lg font-semibold', cancelled && 'text-slate-400 line-through')}>
              {longWhen(it.starts_at)}
            </h1>
            <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
              With {it.host_name} · shown in {VIEWER_TZ.replace(/_/g, ' ')}
            </p>

            {cancelled ? (
              <p className="mt-3 text-sm text-slate-500">
                This booking was cancelled.{' '}
                <a href={`/book/${it.slug}`} className="text-brand-600 underline">Book another time</a>.
              </p>
            ) : it.meeting ? (
              <div className="mt-4 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  <Video className="size-3.5" /> Joining
                </p>
                <a href={it.meeting.join_url} className="mt-1 block break-all text-sm text-brand-600 underline">
                  {it.meeting.join_url}
                </a>
                <p className="mt-2 text-sm">
                  Password:{' '}
                  <span className="font-mono text-base font-semibold tracking-[0.2em]">{it.meeting.passcode}</span>
                </p>
              </div>
            ) : null}
          </div>
        </div>

        <ErrorNote message={error} />

        {/* A meeting that has already started is nobody's to move any more. */}
        {!cancelled && !past && (
          <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            <Button size="sm" variant="secondary" onClick={() => setMoving((m) => !m)} disabled={busy}>
              <CalendarDays className="size-4" /> {moving ? 'Keep this time' : 'Move it'}
            </Button>
            <Button size="sm" variant="danger" disabled={busy} onClick={() => act(() => publicBookingApi.cancel(token))}>
              Cancel booking
            </Button>
          </div>
        )}
      </Card>

      {moving && !cancelled && (
        <Reschedule slug={it.slug} busy={busy} onPick={(iso) => act(() => publicBookingApi.reschedule(token, iso))} />
      )}
    </Shell>
  )
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-dvh bg-slate-50 px-4 py-8 dark:bg-slate-950">
      <div className="mx-auto flex max-w-2xl flex-col gap-4">{children}</div>
    </div>
  )
}

/**
 * The same fortnight of slots the booking page offers.
 *
 * Reusing the public slots endpoint rather than a special one means a moved
 * booking obeys exactly the same rules as a new one — including its own
 * meeting and calendar entry being stood down, so the commonest move (half an
 * hour later, overlapping where it currently sits) is offered rather than
 * refused as a clash with itself.
 */
function Reschedule({ slug, busy, onPick }: { slug: string; busy: boolean; onPick: (iso: string) => void }) {
  const from = new Date()
  from.setHours(0, 0, 0, 0)
  const to = new Date(from.getTime() + 14 * DAY_MS)

  const slots = useQuery({
    queryKey: ['reschedule-slots', slug, from.toDateString()],
    queryFn: () => publicBookingApi.slots(slug, from.toISOString(), to.toISOString()),
  })

  const byDay = new Map<string, string[]>()
  for (const iso of slots.data?.slots ?? []) {
    const key = new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
    byDay.set(key, [...(byDay.get(key) ?? []), iso])
  }

  return (
    <Card>
      <h2 className="mb-3 text-sm font-semibold">Pick a new time</h2>
      {slots.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : byDay.size === 0 ? (
        <p className="py-6 text-center text-sm text-slate-500">Nothing free in the next fortnight.</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[...byDay.entries()].map(([day, times]) => (
            <div key={day}>
              <p className="mb-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">{day}</p>
              <div className="space-y-1.5">
                {times.map((iso) => (
                  <button
                    key={iso}
                    disabled={busy}
                    onClick={() => onPick(iso)}
                    className="tap w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium transition-colors hover:border-brand-500 hover:text-brand-600 disabled:opacity-50 dark:border-slate-700"
                  >
                    {new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}
