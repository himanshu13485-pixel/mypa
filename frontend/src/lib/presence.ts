import { useEffect, useRef } from 'react'
import { create } from 'zustand'
import { differenceInCalendarDays, format, isToday, isYesterday } from 'date-fns'
import { api } from '../api/client'
import { getEcho } from './echo'
import { useAuthStore } from '../stores/auth'
import type { PresenceState } from '../types'

export type { PresenceState }

/** No touch for this long and the person has stepped away. */
const AWAY_AFTER_MS = 3 * 60_000

/** Idle this long with the tab still open and they have gone home. */
const OFFLINE_AFTER_MS = 10 * 60_000

/** Often enough that the server's 150-second trust never lapses in use. */
const BEAT_EVERY_MS = 45_000

/**
 * What the sockets have told us since the page loaded.
 *
 * Keyed by user uuid and deliberately separate from the query cache: presence
 * arrives for people who may be on several screens at once — a connection row,
 * a conversation in the list, a face in the members dialog — and writing it
 * into three caches by hand is three chances to update two of them. Everything
 * that draws a dot reads here first and falls back to whatever the last
 * response said.
 */
interface PresenceStore {
  states: Record<string, PresenceState>
  set: (uuid: string, state: PresenceState) => void
  reset: () => void
}

const usePresenceStore = create<PresenceStore>((set) => ({
  states: {},
  set: (uuid, state) =>
    set((s) => (s.states[uuid] === state ? s : { states: { ...s.states, [uuid]: state } })),
  reset: () => set({ states: {} }),
}))

/**
 * Reconcile the two sources for one person.
 *
 * `served` is what the last API response said, which was right when it was
 * fetched and quietly ages afterwards; the socket is what is true now. A live
 * update therefore wins, and a page open for an hour stays correct without
 * having polled for it.
 *
 * Somebody who has hidden their status sends null, and null it stays: no
 * broadcast is ever sent about them, so there is nothing to override it with.
 */
export function resolvePresence(
  live: Record<string, PresenceState>,
  uuid?: string | null,
  served?: PresenceState | null,
): PresenceState | null {
  if (!uuid || served === null || served === undefined) return served ?? null

  return live[uuid] ?? served
}

/** The dot to draw for one person. */
export function usePresence(uuid?: string | null, served?: PresenceState | null): PresenceState | null {
  const live = usePresenceStore((s) => (uuid ? s.states[uuid] : undefined))

  if (!uuid || served === null || served === undefined) return served ?? null

  return live ?? served
}

/** Presence for a whole list, without a hook per row. */
export function usePresenceMap(): Record<string, PresenceState> {
  return usePresenceStore((s) => s.states)
}

/**
 * Tell the server where this person is, and listen for where everybody else
 * is. Mounted once, inside the shell that both the personal app and the CRM
 * put around every page.
 *
 * Two halves that belong together:
 *
 * The heartbeat, because the server cannot tell reading from having left — a
 * chat screen polls every twenty seconds all night either way, which is
 * exactly why the old "active in the last two minutes" test could only ever
 * answer online. The browser knows: it sees the keys, the pointer, and the tab
 * being hidden, so it is the one that says which of the three this is.
 *
 * The socket, because a dot that only changes on refresh is a dot nobody
 * believes. Somebody signing in now appears now, over the private channel the
 * page is already subscribed to for calls and notifications.
 */
export function usePresenceFeed(): void {
  const uuid = useAuthStore((s) => s.user?.uuid)
  const token = useAuthStore((s) => s.token)
  const setState = usePresenceStore((s) => s.set)
  const reset = usePresenceStore((s) => s.reset)

  /** When somebody last touched this page. A ref: it must not re-render. */
  const lastInputRef = useRef(Date.now())

  // Signing out must not leave the previous account's dots on screen.
  useEffect(() => {
    if (!token) reset()
  }, [token, reset])

  // --- Saying where we are ------------------------------------------------
  useEffect(() => {
    if (!uuid || !token) return

    const mark = () => {
      lastInputRef.current = Date.now()
    }
    const events: (keyof WindowEventMap)[] = ['pointerdown', 'keydown', 'wheel', 'touchstart', 'focus']
    events.forEach((e) => window.addEventListener(e, mark, { passive: true }))

    /*
     * A hidden tab is away at once; idleness is measured in minutes.
     *
     * Switching apps is a deliberate act and reads immediately. Going quiet on
     * the page is not, and a dot that flicked to amber whenever somebody
     * paused to read would end up saying nothing at all.
     */
    const currentState = (): PresenceState => {
      if (document.visibilityState === 'hidden') return 'away'
      const idle = Date.now() - lastInputRef.current
      if (idle >= OFFLINE_AFTER_MS) return 'offline'
      if (idle >= AWAY_AFTER_MS) return 'away'
      return 'online'
    }

    let stopped = false

    /*
     * Sent on every beat, not only on a change: the server believes a report
     * for 150 seconds and then falls back to guessing from request traffic,
     * so silence is not the same as "no news". It decides for itself whether
     * anything moved, and only broadcasts when it did.
     */
    const beat = () => {
      if (stopped) return
      api.post('/presence', { state: currentState() }).catch(() => undefined)
    }

    beat()
    const timer = setInterval(beat, BEAT_EVERY_MS)

    /*
     * Coming back to the tab is the case that matters — that is somebody
     * arriving, and the whole point is that it shows now rather than up to
     * three quarters of a minute later.
     */
    const onVisibility = () => {
      if (document.visibilityState === 'visible') lastInputRef.current = Date.now()
      beat()
    }
    document.addEventListener('visibilitychange', onVisibility)

    /*
     * Leaving. fetch(keepalive) rather than sendBeacon: this route is behind
     * auth like every other and a beacon cannot set an Authorization header,
     * while a keepalive fetch carries its headers and outlives the page.
     *
     * On `pagehide`, not `beforeunload` — the latter never fires on iOS and is
     * skipped whenever a page goes into the back/forward cache.
     */
    const onLeaving = () => {
      const bearer = useAuthStore.getState().token
      if (!bearer) return
      try {
        void fetch('/api/v1/presence/leaving', {
          method: 'POST',
          keepalive: true,
          headers: { Authorization: 'Bearer ' + bearer, Accept: 'application/json' },
        }).catch(() => undefined)
      } catch {
        // A browser without keepalive just goes stale instead, which is the
        // behaviour everybody had before this existed.
      }
    }
    window.addEventListener('pagehide', onLeaving)

    return () => {
      stopped = true
      clearInterval(timer)
      events.forEach((e) => window.removeEventListener(e, mark))
      document.removeEventListener('visibilitychange', onVisibility)
      window.removeEventListener('pagehide', onLeaving)
    }
  }, [uuid, token])

  // --- Hearing where everybody else is ------------------------------------
  useEffect(() => {
    if (!uuid) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private('user.' + uuid)
    const onChanged = (payload: { user_uuid: string; state: PresenceState }) => {
      setState(payload.user_uuid, payload.state)
    }
    channel.listen('.presence.changed', onChanged)

    /*
     * stopListening, never leave(): calls, meeting signals and the
     * notification bell share this channel, and leaving it would take their
     * subscriptions down along with this one.
     */
    return () => {
      channel.stopListening('.presence.changed', onChanged)
    }
  }, [uuid, setState])
}

// --- How the three states are worded and coloured ---------------------------

export const PRESENCE_LABELS: Record<PresenceState, string> = {
  online: 'Online',
  away: 'Away',
  offline: 'Not available',
}

/** Text colour for the word beside a name. */
export const PRESENCE_TEXT: Record<PresenceState, string> = {
  online: 'text-emerald-600 dark:text-emerald-400',
  away: 'text-amber-600 dark:text-amber-400',
  offline: 'text-red-600 dark:text-red-400',
}

/** Fill for the round button on the avatar. */
export const PRESENCE_DOT: Record<PresenceState, string> = {
  online: 'bg-emerald-500',
  away: 'bg-amber-500',
  offline: 'bg-red-500',
}

/** What the dot says when somebody hovers it. */
export const PRESENCE_TITLES: Record<PresenceState, string> = {
  online: 'Online now',
  away: 'Away — idle for a few minutes',
  offline: 'Not available — left or idle for a long while',
}

/**
 * "Last seen 5 min ago", and the shapes that reads better than.
 *
 * Deliberately not date-fns's distance wording all the way down. "about 14
 * hours ago" is a worse answer than "yesterday at 19:31" for the question
 * somebody is actually asking — when should I expect a reply — and by the
 * time a gap is measured in days, the day itself is the useful part.
 *
 * Null in, null out: a hidden last-seen and a person who has never opened the
 * app both leave the line off rather than filling it with a guess.
 */
export function lastSeenLabel(iso?: string | null): string | null {
  if (!iso) return null

  const at = new Date(iso)
  if (Number.isNaN(at.getTime())) return null

  const minutes = Math.floor((Date.now() - at.getTime()) / 60_000)
  if (minutes < 0) return null
  if (minutes < 1) return 'Last seen just now'
  if (minutes < 60) return `Last seen ${minutes} min ago`

  const time = format(at, 'HH:mm')
  if (isToday(at)) return `Last seen today at ${time}`
  if (isYesterday(at)) return `Last seen yesterday at ${time}`

  // Within the week the weekday is what people navigate by; beyond it the
  // date is, and a year only earns a mention once it is not this one.
  if (differenceInCalendarDays(new Date(), at) < 7) return `Last seen ${format(at, 'EEEE')} at ${time}`

  return `Last seen ${format(at, at.getFullYear() === new Date().getFullYear() ? 'd MMM' : 'd MMM yyyy')}`
}

/** Exposed for tests and for anything that needs to seed a state by hand. */
export const presenceStore = usePresenceStore
