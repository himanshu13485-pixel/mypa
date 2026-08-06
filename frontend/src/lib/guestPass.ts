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
