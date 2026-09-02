import { describe, expect, it } from 'vitest'
import { EDIT_WINDOW_MINUTES, withinEditWindow } from './editWindow'

const at = (minutesAgo: number) => new Date(Date.now() - minutesAgo * 60_000).toISOString()

describe('withinEditWindow', () => {
  it('allows a message just sent', () => {
    expect(withinEditWindow(at(0))).toBe(true)
  })

  it('allows one sent well inside the window', () => {
    expect(withinEditWindow(at(EDIT_WINDOW_MINUTES - 1))).toBe(true)
  })

  it('refuses one sent past the window', () => {
    expect(withinEditWindow(at(EDIT_WINDOW_MINUTES + 1))).toBe(false)
  })

  it('refuses a message from yesterday', () => {
    expect(withinEditWindow(at(60 * 24))).toBe(false)
  })

  /*
   * The boundary itself is closed, matching the server's `lt` check: at
   * exactly sixty minutes the server refuses, so the button must be gone.
   */
  it('closes exactly on the boundary', () => {
    const now = new Date('2026-09-01T12:00:00Z')
    const sent = new Date('2026-09-01T11:00:00Z').toISOString()
    expect(withinEditWindow(sent, now)).toBe(false)
  })

  it('treats a message with no timestamp as still editable', () => {
    // Sent optimistically, not yet acknowledged — the pencil must not flicker.
    expect(withinEditWindow(null)).toBe(true)
    expect(withinEditWindow(undefined)).toBe(true)
    expect(withinEditWindow('not a date')).toBe(true)
  })
})
