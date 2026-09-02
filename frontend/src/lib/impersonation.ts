import { api } from '../api/client'
import { IMPERSONATION_KEY, useAuthStore } from '../stores/auth'
import { setCrmOrg } from '../api/crm'
import type { User } from '../types'

/**
 * Sitting in a member's seat, from the browser's side.
 *
 * The admin's own session is never given up. It is set aside here, in the
 * browser, and the borrowed one takes its place until it is handed back — so
 * coming back is restoring something still held rather than asking the server
 * for a new session, and there is no endpoint anywhere that will hand
 * somebody an admin's token because they said they used to have one.
 *
 * localStorage rather than memory, because the seat has to survive a reload:
 * an admin who refreshes while inside somebody's workspace would otherwise be
 * stranded there, signed in as an employee with no way back but signing out.
 */
// Defined beside the session it shadows, so that clearing one clears both:
// there is more than one way to sign out and only the store sees them all.
const STASH_KEY = IMPERSONATION_KEY

export type ImpersonationLevel = 'crm_read' | 'crm' | 'account'

interface Stash {
  /** The admin's own session, waiting to be picked up again. */
  token: string
  user: User
  /** Whose seat is being sat in, for the banner. */
  name: string
  level: ImpersonationLevel
  employeeCode?: string | null
  /** The workspace they were looking at, restored on the way back. */
  orgUuid?: string | null
}

function read(): Stash | null {
  try {
    const raw = localStorage.getItem(STASH_KEY)
    return raw ? (JSON.parse(raw) as Stash) : null
  } catch {
    // A half-written or hand-edited stash is not worth a crash on every
    // render; treating it as "not impersonating" is the safe reading.
    return null
  }
}

/** The seat currently being borrowed, if any. */
export function borrowedSeat(): Stash | null {
  return read()
}

export function isImpersonating(): boolean {
  return read() !== null
}

/**
 * Take the seat.
 *
 * The order matters. The stash is written BEFORE the session is swapped,
 * because the swap is what makes the admin's token unreachable — do it the
 * other way round and a crash in between leaves somebody holding an employee
 * session with no record of whose it was or how to get out.
 */
export async function enterWorkspace(memberUuid: string): Promise<void> {
  const res = await api.post<{
    data: {
      token: string
      user: User
      impersonation: {
        level: ImpersonationLevel
        name: string
        employee_code?: string | null
        organization_uuid?: string | null
      }
    }
  }>(`/crm/employees/${memberUuid}/impersonate`)

  const { token, user, impersonation } = res.data.data
  const mine = useAuthStore.getState()

  if (!mine.token || !mine.user) throw new Error('Not signed in.')

  localStorage.setItem(STASH_KEY, JSON.stringify({
    token: mine.token,
    user: mine.user,
    name: impersonation.name,
    level: impersonation.level,
    employeeCode: impersonation.employee_code ?? null,
    orgUuid: impersonation.organization_uuid ?? null,
  } satisfies Stash))

  useAuthStore.getState().setAuth(token, user)
}

/**
 * Give it back.
 *
 * The borrowed token is revoked on the way out rather than merely dropped: a
 * token the browser has forgotten is still a live token, and one that nobody
 * remembers holding is the worst kind to leave lying about.
 *
 * A failure there is not allowed to strand anybody, though. If the call does
 * not go through — offline, expired, revoked from the other end — the admin
 * still gets their own session back, because being stuck inside somebody
 * else's account is a worse outcome than a token that outlives its use and
 * expires on its own.
 */
export async function leaveWorkspace(): Promise<void> {
  const stash = read()
  if (!stash) return

  try {
    await api.post('/impersonation/stop')
  } catch {
    // See above: coming back matters more than tidying up.
  }

  localStorage.removeItem(STASH_KEY)
  if (stash.orgUuid) setCrmOrg(stash.orgUuid)
  useAuthStore.getState().setAuth(stash.token, stash.user)
}

