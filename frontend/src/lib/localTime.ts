/**
 * Turning what somebody typed into a moment in time.
 *
 * A `datetime-local` input hands back a bare wall clock — "2026-09-03T14:00" —
 * with no timezone in it at all. Sent as-is, the server has to guess which
 * 14:00 was meant, and it guessed with the timezone saved on the account.
 *
 * That guess is wrong whenever the saved timezone is not the one the person is
 * actually looking at, which happens more easily than it sounds: the field is
 * set once at registration from the browser and never revisited, so anyone who
 * travels, changes machine, or had their account made for them by an admin is
 * typing into one timezone and reading results in another. The symptom was an
 * event entered at 2pm appearing at 7:30pm — the offset applied once on the
 * way in and never taken off, because the screen renders in browser time while
 * the server had stored something else entirely.
 *
 * Converting here removes the guess. The browser knows exactly which instant
 * "14:00" means to the person who typed it, because it is the same clock they
 * are reading the answer from.
 */
export function toInstant(localValue: string | null | undefined): string | null {
  if (!localValue) return null

  const at = new Date(localValue)
  // An unparseable value is passed along rather than turned into "Invalid
  // Date": the server's validation should be what rejects it, with a message,
  // instead of this quietly sending null and appearing to lose the field.
  return Number.isNaN(at.getTime()) ? localValue : at.toISOString()
}
