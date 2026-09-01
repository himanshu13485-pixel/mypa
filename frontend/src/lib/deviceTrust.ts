const KEY = 'netvork-device-token'

/**
 * The token that says this browser has already answered a sign-in code.
 *
 * Kept in localStorage on purpose: it has to outlive the session, or every
 * new tab would be a new device and the code would be asked for forever,
 * which is the failure that makes people turn two-step off. It is not a
 * credential on its own — it skips the code, it does not skip the password.
 *
 * Cleared when the person says "don't remember this device", and when a
 * sign-in is refused, so a shared machine does not keep the trust.
 */
export function readDeviceToken(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null // private windows and locked-down browsers: ask for a code
  }
}

export function rememberDevice(token: string): void {
  try {
    localStorage.setItem(KEY, token)
  } catch { /* nothing to do — the code is simply asked for next time */ }
}

export function forgetDevice(): void {
  try {
    localStorage.removeItem(KEY)
  } catch { /* as above */ }
}
