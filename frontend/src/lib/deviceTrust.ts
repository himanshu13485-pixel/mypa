const KEY = 'netvork-device-token'

/**
 * How many accounts' trust this browser will keep at once.
 *
 * A machine on a front desk is signed into by several people; each of them
 * earns a token of their own. Ten is more than any real shared machine and
 * keeps the login header small.
 */
const LIMIT = 10

/**
 * The tokens saying this browser has already answered a sign-in code.
 *
 * One per ACCOUNT, not one per browser. Trust is a fact about a person on a
 * machine — the server files it under the user who answered the code — so a
 * machine two people share holds two tokens, and a single slot meant each
 * sign-in quietly overwrote the last. Two people alternating on one computer
 * were asked for a code every single time, which is the failure that makes
 * people turn two-step off.
 *
 * Kept in localStorage on purpose: it has to outlive the session, or every
 * new tab would be a new device. None of it is a credential on its own — it
 * skips the code, it does not skip the password.
 */
function stored(): string[] {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return []

    /*
     * A browser that last signed in before this held one bare token rather
     * than a list. It is still a good token, so it is read as a list of one
     * rather than thrown away — nobody should be asked for a code merely
     * because the app learned to remember more than one person.
     */
    if (!raw.startsWith('[')) return [raw]

    const list: unknown = JSON.parse(raw)

    return Array.isArray(list) ? list.filter((t): t is string => typeof t === 'string' && t !== '') : []
  } catch {
    return [] // private windows, locked-down browsers, torn JSON: ask for a code
  }
}

export function readDeviceTokens(): string[] {
  return stored()
}

/** Keep this account's token, newest first, without losing anybody else's. */
export function rememberDevice(token: string): void {
  try {
    const next = [token, ...stored().filter((t) => t !== token)].slice(0, LIMIT)
    localStorage.setItem(KEY, JSON.stringify(next))
  } catch { /* nothing to do — the code is simply asked for next time */ }
}

/**
 * Drop every remembered account on this machine.
 *
 * For somebody handing the computer on, not for a single sign-in: there is
 * no way to tell which stored token belongs to which person, which is the
 * price of not writing their addresses down beside them.
 */
export function forgetDevices(): void {
  try {
    localStorage.removeItem(KEY)
  } catch { /* as above */ }
}
