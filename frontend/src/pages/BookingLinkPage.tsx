import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Check, Copy, Plus, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { bookingApi, publicBookingApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import type { BookingHour, BookingPageConfig, BookingRow } from '../types'
import {
  Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, SkeletonList, Textarea,
} from '../components/ui'

/**
 * Your booking link, and what people have done with it.
 *
 * One page per person, created by the server the first time this is opened, so
 * there is nothing to set up before there is something to look at. It starts
 * switched off: a live link with no availability behind it shows a stranger an
 * empty fortnight, which reads as broken rather than as unfinished.
 */

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

/** Monday to Friday, nine to five — the answer for most people, one click away. */
const WEEKDAY_DEFAULT: BookingHour[] = [1, 2, 3, 4, 5].map((weekday) => ({
  weekday,
  start_time: '09:00',
  end_time: '17:00',
}))

export default function BookingLinkPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['booking-page'], queryFn: bookingApi.mine })

  const [form, setForm] = useState<BookingPageConfig | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  // The server's answer is the starting point; edits live here until saved.
  useEffect(() => {
    if (data) setForm(data)
  }, [data])

  const save = useMutation({
    mutationFn: (payload: Partial<BookingPageConfig> & { hours?: BookingHour[] }) => bookingApi.save(payload),
    onSuccess: (fresh) => {
      setForm(fresh)
      setError(null)
      queryClient.setQueryData(['booking-page'], fresh)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  if (isLoading || !form) {
    return (
      <div className="space-y-4">
        <h1 className="text-xl font-semibold tracking-tight">Book@Meetings</h1>
        <Card><SkeletonList rows={4} avatar={false} /></Card>
      </div>
    )
  }

  const set = <K extends keyof BookingPageConfig>(key: K, value: BookingPageConfig[K]) =>
    setForm({ ...form, [key]: value })

  const setHours = (hours: BookingHour[]) => setForm({ ...form, hours })

  const copy = () => {
    void navigator.clipboard?.writeText(form.url)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
        <CalendarClock className="size-5 text-brand-600" /> Book@Meetings
      </h1>

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-medium uppercase tracking-[0.08em] text-slate-400">Your link</p>
            <p className="mt-0.5 break-all text-sm font-medium">{form.url}</p>
          </div>
          <div className="flex items-center gap-2">
            <Button size="sm" variant="secondary" onClick={copy}>
              {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy'}
            </Button>
            <Button
              size="sm"
              variant={form.is_active ? 'secondary' : 'primary'}
              disabled={save.isPending}
              onClick={() => save.mutate({ is_active: !form.is_active })}
            >
              {form.is_active ? 'Turn off' : 'Turn on'}
            </Button>
          </div>
        </div>
        <p className="mt-2 text-xs text-slate-400">
          {form.is_active
            ? `Anyone with this link can book you. Times are offered in your timezone, ${form.timezone}.`
            : 'The link is switched off — nobody can book you until you turn it on.'}
        </p>
      </Card>

      <Card>
        <h2 className="mb-3 text-sm font-semibold">What people are booking</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Address</Label>
            <div className="flex items-center gap-1">
              <span className="shrink-0 text-xs text-slate-400">/book/</span>
              <Input
                value={form.slug}
                onChange={(e) => set('slug', e.target.value.toLowerCase())}
                maxLength={64}
                className="min-w-0 flex-1"
              />
            </div>
          </div>
          <div>
            <Label>Title</Label>
            <Input
              value={form.title ?? ''}
              onChange={(e) => set('title', e.target.value)}
              placeholder="Intro call"
              maxLength={255}
            />
          </div>
        </div>

        <div className="mt-3">
          <Label>Description</Label>
          <Textarea
            rows={2}
            value={form.description ?? ''}
            onChange={(e) => set('description', e.target.value)}
            placeholder="What this meeting is for, and anything they should bring."
            maxLength={2000}
          />
        </div>

        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <Label>Length</Label>
            <Select value={form.duration_minutes} onChange={(e) => set('duration_minutes', Number(e.target.value))}>
              {[15, 20, 30, 45, 60, 90].map((m) => <option key={m} value={m}>{m} minutes</option>)}
            </Select>
          </div>
          <div>
            <Label>Gap after each</Label>
            {/* Kept out of the slot the guest sees: they are not interested in
                your recovery time, and showing it makes every meeting look
                longer than it is. */}
            <Select value={form.buffer_minutes} onChange={(e) => set('buffer_minutes', Number(e.target.value))}>
              {[0, 5, 10, 15, 30].map((m) => <option key={m} value={m}>{m ? `${m} minutes` : 'None'}</option>)}
            </Select>
          </div>
          <div>
            <Label>Least notice</Label>
            <Select value={form.min_notice_minutes} onChange={(e) => set('min_notice_minutes', Number(e.target.value))}>
              {[0, 60, 120, 240, 720, 1440, 2880].map((m) => (
                <option key={m} value={m}>
                  {m === 0 ? 'Any time' : m < 1440 ? `${m / 60} hours` : `${m / 1440} day(s)`}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Bookable up to</Label>
            <Select value={form.max_days_ahead} onChange={(e) => set('max_days_ahead', Number(e.target.value))}>
              {[7, 14, 30, 60, 90].map((d) => <option key={d} value={d}>{d} days ahead</option>)}
            </Select>
          </div>
        </div>

        {/*
          * Where the meeting is held.
          *
          * Someone who lives in Google Meet all day should not have to send
          * guests to a second room they do not use. Netvork stays the
          * default, and the link box only appears once it is needed — an
          * empty field on a screen nobody asked it of is just noise.
          */}
        <div className="mt-4 border-t border-slate-200 pt-3 dark:border-slate-700">
          <Label>Meet in</Label>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select
              value={form.meeting_provider}
              onChange={(e) => set('meeting_provider', e.target.value as BookingPageConfig['meeting_provider'])}
            >
              <option value="netvork">A Netvork meeting</option>
              <option value="google_meet">Google Meet</option>
            </Select>
            {form.meeting_provider === 'google_meet' && (
              <Input
                value={form.external_meeting_url ?? ''}
                onChange={(e) => set('external_meeting_url', e.target.value)}
                placeholder="https://meet.google.com/abc-defg-hij"
                maxLength={512}
              />
            )}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {form.meeting_provider === 'google_meet'
              ? 'Everyone who books gets this same link. Use a room you keep open — Google Meet has no waiting room by default, so back-to-back guests could overlap.'
              : 'A fresh room with its own password is made for each booking, and the link goes out with the confirmation.'}
          </p>
        </div>
      </Card>

      <Card>
        <div className="mb-1 flex items-center justify-between">
          <h2 className="text-sm font-semibold">When you are free</h2>
          {form.hours.length === 0 && (
            <Button size="sm" variant="secondary" onClick={() => setHours(WEEKDAY_DEFAULT)}>
              Use weekdays 9–5
            </Button>
          )}
        </div>
        <p className="mb-3 text-xs text-slate-400">
          In your own timezone ({form.timezone}). Add two rows to a day to take lunch out of the middle.
        </p>

        {WEEKDAYS.map((label, weekday) => {
          const rows = form.hours.filter((h) => h.weekday === weekday)

          return (
            <div key={weekday} className="flex flex-wrap items-start gap-2 border-t border-slate-100 py-2 dark:border-slate-800">
              <span className="w-24 shrink-0 pt-1.5 text-sm font-medium">{label}</span>
              <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                {rows.length === 0 && <span className="pt-1.5 text-xs text-slate-400">Unavailable</span>}
                {rows.map((row, i) => (
                  <div key={i} className="flex items-center gap-1.5">
                    <Input
                      type="time"
                      value={row.start_time}
                      className="w-28"
                      onChange={(e) => setHours(form.hours.map((h) => (h === row ? { ...h, start_time: e.target.value } : h)))}
                    />
                    <span className="text-xs text-slate-400">to</span>
                    <Input
                      type="time"
                      value={row.end_time}
                      className="w-28"
                      onChange={(e) => setHours(form.hours.map((h) => (h === row ? { ...h, end_time: e.target.value } : h)))}
                    />
                    <button
                      className="tap rounded-lg p-1.5 text-slate-400 hover:text-red-600"
                      aria-label={`Remove ${label} window`}
                      onClick={() => setHours(form.hours.filter((h) => h !== row))}
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                ))}
              </div>
              <Button
                size="sm"
                variant="secondary"
                onClick={() => setHours([...form.hours, { weekday, start_time: '09:00', end_time: '17:00' }])}
              >
                <Plus className="size-3.5" /> Add
              </Button>
            </div>
          )
        })}

        <ErrorNote message={error} />
        <div className="mt-3 flex items-center gap-3">
          <Button
            disabled={save.isPending}
            onClick={() => save.mutate({
              slug: form.slug,
              title: form.title,
              description: form.description,
              duration_minutes: form.duration_minutes,
              buffer_minutes: form.buffer_minutes,
              min_notice_minutes: form.min_notice_minutes,
              max_days_ahead: form.max_days_ahead,
              meeting_provider: form.meeting_provider,
              external_meeting_url: form.external_meeting_url,
              hours: form.hours,
            })}
          >
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          {save.isSuccess && !save.isPending && <span className="text-xs text-slate-500">Saved.</span>}
        </div>
      </Card>

      <Bookings slug={data?.slug ?? ''} />
    </div>
  )
}

/** Who has booked you, soonest first. */
function Bookings({ slug }: { slug: string }) {
  const queryClient = useQueryClient()
  const [past, setPast] = useState(false)
  /* The booking being moved, if the host has asked to move one. */
  const [moving, setMoving] = useState<BookingRow | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['booking-page-bookings', past],
    queryFn: () => bookingApi.bookings(past),
  })

  const cancel = useMutation({
    mutationFn: (uuid: string) => bookingApi.cancel(uuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['booking-page-bookings'] })
      // The slot is free again and the calendar entry is gone, so anything
      // showing either is now wrong.
      queryClient.invalidateQueries({ queryKey: ['calendar'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">{past ? 'Past bookings' : 'Coming up'}</h2>
        <Button size="sm" variant="secondary" onClick={() => setPast((p) => !p)}>
          {past ? 'Show upcoming' : 'Show past'}
        </Button>
      </div>

      {isLoading ? (
        <SkeletonList rows={3} avatar={false} />
      ) : !data?.length ? (
        <EmptyState
          title={past ? 'Nothing yet' : 'Nobody has booked you yet'}
          hint={past ? undefined : 'Share your link and bookings will appear here.'}
        />
      ) : (
        <div className="divide-y divide-slate-100 dark:divide-slate-800">
          {data.map((booking) => (
            <div key={booking.uuid} className="flex flex-wrap items-start justify-between gap-3 py-2.5">
              <div className="min-w-0">
                <p className="text-sm font-medium">
                  {new Date(booking.starts_at).toLocaleString(undefined, {
                    weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit',
                  })}
                  {' - '}
                  {new Date(booking.ends_at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
                  {booking.status === 'cancelled' && (
                    <span className="ml-2 text-xs font-normal text-slate-400">cancelled</span>
                  )}
                </p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {booking.name} · {booking.email}
                </p>
                {/* Dialable, because the reason for asking is a host who
                    needs to reach somebody in the next two minutes. */}
                {booking.phone && (
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    <a href={`tel:${booking.phone}`} className="hover:underline">{booking.phone}</a>
                  </p>
                )}
                {booking.note && (
                  <p className="mt-1 whitespace-pre-line text-xs text-slate-400">{booking.note}</p>
                )}
                {/* Where it is held, so the host can open the room from the
                    one screen that already knows the meeting exists. */}
                {booking.status === 'confirmed' && (booking.meeting_code || booking.meeting_url) && (
                  <p className="mt-1 text-xs">
                    <a
                      className="text-brand-600 hover:underline dark:text-brand-400"
                      href={booking.meeting_url ?? `/meetings/room/${booking.meeting_code}`}
                      target={booking.meeting_url ? '_blank' : undefined}
                      rel={booking.meeting_url ? 'noreferrer' : undefined}
                    >
                      Open the meeting room
                    </a>
                  </p>
                )}
              </div>
              {booking.status === 'confirmed' && (
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => setMoving(booking)}>
                    Reschedule
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={cancel.isPending}
                    onClick={() => cancel.mutate(booking.uuid)}
                  >
                    Cancel
                  </Button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {moving && (
        <RescheduleModal
          slug={slug}
          booking={moving}
          onClose={() => setMoving(null)}
          onMoved={() => {
            setMoving(null)
            queryClient.invalidateQueries({ queryKey: ['booking-page-bookings'] })
            queryClient.invalidateQueries({ queryKey: ['calendar'] })
            queryClient.invalidateQueries({ queryKey: ['dashboard'] })
          }}
        />
      )}
    </Card>
  )
}

/**
 * Moving somebody else's booking, from the host's side.
 *
 * The guest has always been able to move their own, through the link in
 * their confirmation. The host could only cancel - so "could we make it half
 * an hour later?" meant calling off a client's meeting and asking them to
 * book again, which is a worse thing to do to a client than it sounds.
 *
 * The times offered are the host's own free slots, from the same endpoint
 * the public page asks, so a host cannot move somebody on top of something
 * else they have on. Whoever booked is emailed the moment it moves, and the
 * button says so: a host should know they are about to write to a client
 * before they press it, not after.
 */
function RescheduleModal({ slug, booking, onClose, onMoved }: {
  slug: string
  booking: BookingRow
  onClose: () => void
  onMoved: () => void
}) {
  const [day, setDay] = useState(() => new Date(booking.starts_at).toISOString().slice(0, 10))
  const [picked, setPicked] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['booking-slots', slug, day],
    queryFn: () => publicBookingApi.slots(slug, day, day),
    enabled: !!slug,
  })

  const move = useMutation({
    mutationFn: (startsAt: string) => bookingApi.reschedule(booking.uuid, startsAt),
    onSuccess: onMoved,
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Move ${booking.name}'s booking`} onClose={onClose}>
      <div className="space-y-3">
        <div>
          <Label>Day</Label>
          <Input type="date" value={day} onChange={(e) => { setDay(e.target.value); setPicked(null) }} />
        </div>

        {isLoading ? (
          <p className="text-xs text-slate-500">Looking at your diary...</p>
        ) : !data?.slots.length ? (
          <p className="text-xs text-slate-500">Nothing free that day.</p>
        ) : (
          <div className="grid grid-cols-3 gap-2">
            {data.slots.map((slot) => (
              <button
                key={slot}
                type="button"
                onClick={() => setPicked(slot)}
                className={clsx(
                  'rounded-lg px-2 py-1.5 text-xs ring-1 ring-inset',
                  picked === slot
                    ? 'bg-brand-600 text-white ring-brand-600'
                    : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                )}
              >
                {new Date(slot).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
              </button>
            ))}
          </div>
        )}

        <ErrorNote message={error} />

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button
            disabled={!picked || move.isPending}
            onClick={() => picked && move.mutate(picked)}
          >
            {move.isPending ? 'Moving...' : `Move and email ${booking.name}`}
          </Button>
        </div>
      </div>
    </Modal>
  )
}
