import { describe, expect, it } from 'vitest'
import { toInstant } from './localTime'

/**
 * A datetime-local value is a bare wall clock with no timezone in it. Sending
 * it unconverted made the server guess which 14:00 was meant, using a
 * timezone saved on the account that may be nothing to do with where the
 * person is now — an event typed at 2pm came back at 7:30pm.
 */
describe('toInstant', () => {
  it('reads a bare wall clock as the browser own time', () => {
    // Whatever this machine's timezone is, the instant produced must be the
    // one that renders back as 14:00 on the same machine.
    const iso = toInstant('2026-09-03T14:00')!
    expect(new Date(iso).getHours()).toBe(14)
    expect(new Date(iso).getMinutes()).toBe(0)
  })

  it('produces an unambiguous instant, so nothing downstream has to guess', () => {
    expect(toInstant('2026-09-03T14:00')).toMatch(/Z$/)
  })

  it('leaves an empty value alone', () => {
    expect(toInstant('')).toBeNull()
    expect(toInstant(null)).toBeNull()
    expect(toInstant(undefined)).toBeNull()
  })

  it('passes nonsense through for the server to reject with a message', () => {
    // Turning it into null here would look like the field was silently lost.
    expect(toInstant('not a date')).toBe('not a date')
  })

  it('survives a value that already carries a zone', () => {
    expect(toInstant('2026-09-03T14:00:00Z')).toBe('2026-09-03T14:00:00.000Z')
  })
})
