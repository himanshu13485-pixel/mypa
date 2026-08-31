import { useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, Clock, Globe, Video } from 'lucide-react'
import { clsx } from 'clsx'
import { publicBookingApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import type { BookingDetail } from '../types'
import { Button, Card, ErrorNote, Input, Label, Skeleton, Textarea } from '../components/ui'

/**
 * The page a link hands to a stranger.
 *
 * No account, no sign-in, nothing to install: pick a day, pick a time, say who
 * you are. That is the whole promise of a booking link and every decision here
 * defends it — which is why this route lives outside the auth guard and
 * outside the app shell, with no sidebar to suggest there is more to join.
 *
 * Times are shown in the visitor's own timezone, taken from their browser and
 * named on screen. The server returns instants and never local strings, so the
 * conversion happens in the one place that actually knows the answer. Getting
 * this wrong is the classic failure of scheduling tools, and the symptom is
 * somebody turning up five and a half hours late.
 */

/** Whatever the browser believes, with a fallback for the ones that will not say. */
const VIEWER_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'

const DAY_MS = 24 * 60 * 60 * 1000

function startOfLocalDay(date: Date): Date {
  const copy = new Date(date)
  copy.setHours(0, 0, 0, 0)
  return copy
}

export default function PublicBookingPage() {
  const { slug = '' } = useParams()

  /*
   * A fortnight at a time.
   *
   * Long enough that most people find something without paging, short enough
   * that the server is never asked to walk a year a day at a time. The window
   * moves in whole weeks so the columns keep their weekday alignment.
   */
  const [weekOffset, setWeekOffset] = useState(0)
  const [chosen, setChosen] = useState<string | null>(null)
  const [booked, setBooked] = useState<BookingDetail | null>(null)

  const rangeStart = useMemo(
    () => new Date(startOfLocalDay(new Date()).getTime() + weekOffset * 7 * DAY_MS),
    [weekOffset],
  )
  const rangeEnd = useMemo(() => new Date(rangeStart.getTime() + 14 * DAY_MS), [rangeStart])

  const page = useQuery({
    queryKey: ['public-booking-page', slug],
    queryFn: () => publicBookingApi.page(slug),
    retry: false,
  })

  const slots = useQuery({
    queryKey: ['public-booking-slots', slug, rangeStart.toISOString()],
    queryFn: () => publicBookingApi.slots(slug, rangeStart.toISOString(), rangeEnd.toISOString()),
    enabled: !!page.data,
  })

  /** Slots grouped under the day they fall on *for the viewer*, not the host. */
  const byDay = useMemo(() => {
    const groups = new Map<string, string[]>()
    for (const iso of slots.data?.slots ?? []) {
      const key = new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
      groups.set(key, [...(groups.get(key) ?? []), iso])
    }
    return [...groups.entries()]
  }, [slots.data])

  if (page.isLoading) {
    return (
      <Shell>
        <Card className="space-y-3">
          <Skeleton className="h-5 w-48" />
          <Skeleton className="h-3 w-32" />
          <Skeleton className="h-24 w-full" />
        </Card>
      </Shell>
    )
  }

  if (page.isError || !page.data) {
    return (
      <Shell>
        <Card className="text-center">
          <p className="text-sm font-medium">This booking link is not available.</p>
          <p className="mt-1 text-xs text-slate-500">
            It may have been turned off, or the address may be wrong. Ask whoever sent it for a new one.
          </p>
        </Card>
      </Shell>
    )
  }

  if (booked) {
    return (
      <Shell>
        <Booked booking={booked} />
      </Shell>
    )
  }

  const info = page.data

  return (
    <Shell>
      <Card>
        <p className="text-xs font-medium uppercase tracking-[0.08em] text-slate-400">{info.host_name}</p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">{info.title}</h1>
        {info.description && (
          <p className="mt-2 whitespace-pre-line text-sm text-slate-500 dark:text-slate-400">{info.description}</p>
        )}
        <div className="mt-3 flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400">
          <span className="flex items-center gap-1.5">
            <Clock className="size-3.5" /> {info.duration_minutes} minutes
          </span>
          <span className="flex items-center gap-1.5">
            <Video className="size-3.5" /> Video call
          </span>
          {/* Named, not implied. A time without its zone is how people miss meetings. */}
          <span className="flex items-center gap-1.5">
            <Globe className="size-3.5" /> Times shown in {VIEWER_TZ.replace(/_/g, ' ')}
          </span>
        </div>
      </Card>

      <Card>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <CalendarDays className="size-4" /> Pick a time
          </h2>
          <div className="flex items-center gap-1">
            <Button
              size="sm"
              variant="secondary"
              onClick={() => setWeekOffset((w) => Math.max(0, w - 1))}
              disabled={weekOffset === 0}
              aria-label="Earlier dates"
            >
              <ChevronLeft className="size-4" />
            </Button>
            <Button size="sm" variant="secondary" onClick={() => setWeekOffset((w) => w + 1)} aria-label="Later dates">
              <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>

        {slots.isLoading ? (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }, (_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-8 w-full" />
              </div>
            ))}
          </div>
        ) : byDay.length === 0 ? (
          <div className="py-10 text-center">
            <p className="text-sm font-medium text-slate-700 dark:text-slate-200">Nothing free in this fortnight.</p>
            <p className="mt-1 text-xs text-slate-400">Try the arrow above to look further ahead.</p>
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {byDay.map(([day, times]) => (
              <div key={day}>
                <p className="mb-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">{day}</p>
                <div className="space-y-1.5">
                  {times.map((iso) => (
                    <button
                      key={iso}
                      onClick={() => setChosen(iso)}
                      className={clsx(
                        'tap w-full rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                        chosen === iso
                          ? 'border-brand-600 bg-brand-600 text-white'
                          : 'border-slate-200 hover:border-brand-500 hover:text-brand-600 dark:border-slate-700',
                      )}
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

      {chosen && (
        <BookingForm
          slug={slug}
          startsAt={chosen}
          onCancel={() => setChosen(null)}
          onBooked={(booking) => {
            setBooked(booking)
            setChosen(null)
          }}
          onTaken={() => {
            setChosen(null)
            void slots.refetch()
          }}
        />
      )}
    </Shell>
  )
}

/** No sidebar, no header: there is no app here to be part of yet. */
function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-dvh bg-slate-50 px-4 py-8 dark:bg-slate-950">
      <div className="mx-auto flex max-w-3xl flex-col gap-4">{children}</div>
    </div>
  )
}

function BookingForm({
  slug,
  startsAt,
  onCancel,
  onBooked,
  onTaken,
}: {
  slug: string
  startsAt: string
  onCancel: () => void
  onBooked: (booking: BookingDetail) => void
  onTaken: () => void
}) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const when = new Date(startsAt).toLocaleString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit',
  })

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      onBooked(await publicBookingApi.book(slug, { starts_at: startsAt, name, email, note, timezone: VIEWER_TZ }))
    } catch (err) {
      /*
       * 409 is not really an error, it is news: somebody else took this time
       * between the list being drawn and this being sent. Saying so and
       * refreshing the times is the only useful thing that can happen next, so
       * the form closes rather than leaving them staring at a dead slot.
       */
      const status = (err as { response?: { status?: number } })?.response?.status
      if (status === 409) {
        onTaken()
        return
      }
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <h2 className="text-sm font-semibold">Confirm {when}</h2>
      <form onSubmit={submit} className="mt-3 space-y-3">
        <div>
          <Label>Your name</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} autoFocus />
        </div>
        <div>
          <Label>Your email</Label>
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required maxLength={255} />
          <p className="mt-1 text-xs text-slate-400">
            The joining link goes here — and it is the only way back to this booking if you need to move it.
          </p>
        </div>
        <div>
          <Label>Anything they should know? (optional)</Label>
          <Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
        </div>
        <ErrorNote message={error} />
        <div className="flex items-center gap-2">
          <Button type="submit" disabled={busy}>{busy ? 'Booking…' : 'Confirm booking'}</Button>
          <Button type="button" variant="secondary" onClick={onCancel} disabled={busy}>
            Pick another time
          </Button>
        </div>
      </form>
    </Card>
  )
}

function Booked({ booking }: { booking: BookingDetail }) {
  const when = new Date(booking.starts_at).toLocaleString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit',
  })

  return (
    <Card>
      <div className="flex items-start gap-3">
        <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-500" />
        <div className="min-w-0">
          <h1 className="text-lg font-semibold">You are booked in</h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
            {when} with {booking.host_name}. A confirmation is on its way to {booking.email}.
          </p>

          {booking.meeting && (
            <div className="mt-4 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Joining</p>
              <a href={booking.meeting.join_url} className="mt-1 block break-all text-sm text-brand-600 underline">
                {booking.meeting.join_url}
              </a>
              <p className="mt-2 text-sm">
                Meeting password:{' '}
                <span className="font-mono text-base font-semibold tracking-[0.2em]">{booking.meeting.passcode}</span>
              </p>
              <p className="mt-1 text-xs text-slate-400">
                No account needed — open the link, enter the password and your name.
              </p>
            </div>
          )}
        </div>
      </div>
    </Card>
  )
}
