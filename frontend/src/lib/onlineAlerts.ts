import type { PresenceState } from '../types'

/**
 * "Tell me when they get here."
 *
 * Everything else in the app answers the question after you ask it — you open
 * Connections, you look for a green dot. This is the one alert that answers it
 * before you ask, which is what people actually want when they are waiting on
 * somebody to come back to their desk.
 *
 * Kept per device, in localStorage, beside the notification sound in
 * alerts.ts, and for the same reason: this is a fact about a machine, not
 * about a person. Your desk is where you want a pop-up when your colleague
 * signs in; your phone in your pocket at the weekend is emphatically not, and
 * a preference synced to the account would put it in both.
 */

const KEY = 'mypa-online-alerts'

export type AlertScope = 'all' | 'watched'

export interface OnlineAlertPrefs {
  enabled: boolean
  /** Everyone who can broadcast to you, or only the people you picked. */
  scope: AlertScope
  /** Uuids of the people being watched, when scope is 'watched'. */
  watching: string[]
}

/**
 * Off until somebody asks for it — deliberately the opposite of the notification
 * defaults on the account, which are all on.
 *
 * Those are about things addressed to you: a message, a share, a bill. Silence
 * there loses information that was meant for you, so on is the safe default.
 * This one is about somebody else opening their laptop, which was never
 * addressed to anyone, and switching it on for people who did not ask would
 * hand them a running log of when their colleagues start and stop work.
 */
export const DEFAULT_ONLINE_ALERTS: OnlineAlertPrefs = {
  enabled: false,
  scope: 'all',
  watching: [],
}

/**
 * The same person will not raise a second pop-up for half an hour.
 *
 * Presence is not a switch, it is a ladder: reading is 'online', pausing for
 * three minutes to read something long is 'away', and touching the page again
 * is 'online' once more. Somebody working normally therefore crosses back into
 * online several times an hour without having gone anywhere, and without a
 * cooldown this feature is a stream of pop-ups saying a person who never left
 * has arrived — which is how a notification everybody switches off on the
 * first afternoon gets built.
 */
export const ALERT_COOLDOWN_MS = 30 * 60_000

export function getOnlineAlertPrefs(): OnlineAlertPrefs {
  try {
    const raw = localStorage.getItem(KEY)
    if (raw) {
      const saved = JSON.parse(raw) as Partial<OnlineAlertPrefs>

      return {
        ...DEFAULT_ONLINE_ALERTS,
        ...saved,
        // A hand-edited or half-written value must not make `.includes` throw
        // inside the presence subscriber, which runs on every socket event.
        watching: Array.isArray(saved.watching) ? saved.watching : [],
      }
    }
  } catch {
    /* corrupted prefs fall back to the defaults */
  }

  return { ...DEFAULT_ONLINE_ALERTS }
}

export function setOnlineAlertPrefs(prefs: OnlineAlertPrefs): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs))
  } catch {
    /* private mode, quota — the preference just does not persist */
  }
}

export function isWatching(uuid: string, prefs = getOnlineAlertPrefs()): boolean {
  return prefs.watching.includes(uuid)
}

/** Add or remove one person from the watch list. Returns the saved prefs. */
export function toggleWatching(uuid: string): OnlineAlertPrefs {
  const prefs = getOnlineAlertPrefs()
  const next: OnlineAlertPrefs = {
    ...prefs,
    watching: prefs.watching.includes(uuid)
      ? prefs.watching.filter((u) => u !== uuid)
      : [...prefs.watching, uuid],
  }

  setOnlineAlertPrefs(next)

  return next
}

/**
 * Can this machine show one at all?
 *
 * Desktop only, as asked, and the reason is not snobbery about phones. A phone
 * already has a way to tell you something happened while the app was closed —
 * push, which goes through the server and through a notification channel the
 * person can silence per category. This goes nowhere near either: it is a
 * pop-up drawn by an open tab, so on a phone it can only fire while the app is
 * in front of you, which is exactly when you do not need telling.
 *
 * The Capacitor check is the installed Android app; the media query is a
 * phone-shaped browser. Both read from `window` directly rather than through
 * nativeShell.ts and useMediaQuery.ts so that this file stays free of the app's
 * API client, which is what lets the rules below be unit-tested.
 */
export function desktopAlertsPossible(): boolean {
  if (typeof window === 'undefined' || !('Notification' in window)) return false

  const capacitor = (window as { Capacitor?: { isNativePlatform?: () => boolean } }).Capacitor
  if (capacitor?.isNativePlatform?.()) return false

  if (typeof window.matchMedia === 'function') {
    if (window.matchMedia('(max-width: 639px), (pointer: coarse) and (max-width: 1024px)').matches) {
      return false
    }
  }

  return true
}

export interface AlertDecision {
  prefs: OnlineAlertPrefs
  uuid: string
  /** What we thought a moment ago. Undefined when we had never heard of them. */
  from: PresenceState | undefined
  to: PresenceState
  /** When this person last raised a pop-up on this page. */
  lastAlertedAt?: number
  now: number
}

/**
 * Does this presence change deserve a pop-up?
 *
 * `from` being undefined still counts as an arrival. The server only ever
 * broadcasts a change — a heartbeat that says the same thing as the last one
 * goes nowhere — so the first word we hear about somebody is, on the server's
 * side, news. The alternative would be to stay quiet about everybody until
 * they had already come and gone once, which would make the feature useless
 * for the first hour of every session.
 */
export function shouldAlert({ prefs, uuid, from, to, lastAlertedAt, now }: AlertDecision): boolean {
  if (!prefs.enabled) return false
  if (to !== 'online') return false

  // Away → online is somebody stretching, not arriving; only the crossing
  // from a state that is not online counts, and 'away' is one of those. The
  // cooldown below is what keeps that from being noisy.
  if (from === 'online') return false

  if (prefs.scope === 'watched' && !prefs.watching.includes(uuid)) return false

  if (lastAlertedAt !== undefined && now - lastAlertedAt < ALERT_COOLDOWN_MS) return false

  return true
}
