/**
 * The pass held by somebody in a meeting without a Netvork account.
 *
 * Kept in sessionStorage rather than localStorage: it belongs to this tab and
 * this sitting, lasts 30 minutes, and should not outlive either. One place
 * reads and writes it so the API client, the realtime connection and the room
 * cannot disagree about who the guest is.
 */
export interface GuestPass {
  code: string
  token: string
  uuid: string
  name: string
  expiresAt: string
}

const KEY = 'mypa-guest-pass'

export function readGuestPass(): GuestPass | null {
  try {
    const raw = sessionStorage.getItem(KEY)
    if (!raw) return null
    const pass = JSON.parse(raw) as GuestPass
    return pass.token && pass.code && pass.uuid ? pass : null
  } catch {
    return null
  }
}

export function saveGuestPass(pass: GuestPass): void {
  sessionStorage.setItem(KEY, JSON.stringify(pass))
}

export function clearGuestPass(): void {
  sessionStorage.removeItem(KEY)
}

/** Their half hour is up — the server will refuse them from here on. */
export function guestPassExpired(pass: GuestPass | null): boolean {
  return pass !== null && new Date(pass.expiresAt).getTime() <= Date.now()
}

/**
 * The share link, as sent to everyone: /meetings/room/abc-defg-hij
 *
 * Shape-checked but not alphabet-checked. Codes are generated from lowercase
 * letters, and a pattern that insisted on exactly that would quietly send real
 * invitees to a sign-in form the day the generator gained a digit. Case is
 * forgiven too — links get retyped out of documents and messages.
 */
const MEETING_ROOM = /^\/meetings\/room\/([a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3})\/?$/i

/**
 * Where a signed-out visitor on a meeting link should be sent.
 *
 * There is one invite link and everybody gets the same one, so the guard
 * cannot simply bounce whoever is not signed in to a sign-in form: a meeting
 * invite regularly reaches people with no account who are not about to make
 * one. Returns null for any other path, meaning "not my business".
 */
export function guestRouteFor(pathname: string, pass: GuestPass | null): string | null {
  const room = pathname.match(MEETING_ROOM)
  if (!room) return null

  const code = room[1].toLowerCase()
  // Already holding a live pass for this meeting: that is a reload, not a new
  // arrival, and asking for the password again would be asking twice.
  const live = pass?.code === code && !guestPassExpired(pass)

  return live ? `/guest/room/${code}` : `/join/${code}`
}
