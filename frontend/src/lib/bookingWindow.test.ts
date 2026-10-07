import { describe, expect, it } from 'vitest'
import { bookingWindow, WINDOW_DAYS } from './bookingWindow'

const DAY_MS = 24 * 60 * 60 * 1000
const days = (a: Date, b: Date) => Math.round((b.getTime() - a.getTime()) / DAY_MS)

/**
 * Paging through somebody's free time.
 *
 * The arrows moved the window a week while the window was a fortnight wide,
 * so every page showed the second half of the one before it - page forward
 * past Thursday the 15th and you arrived at a page beginning Wednesday the
 * 14th. Nothing about that looks wrong until you read the dates, which is
 * exactly why it is worth a test rather than a careful eye.
 */
describe('the fortnight a booking page shows', () => {
  const now = new Date('2026-10-08T15:30:00')

  it('shows a fortnight', () => {
    const { from, to } = bookingWindow(0, now)

    expect(days(from, to)).toBe(WINDOW_DAYS)
  })

  it('starts at midnight, not at whatever time it happens to be', () => {
    // Asking from half past three would hide this morning's free slots on
    // the first page and nowhere else.
    const { from } = bookingWindow(0, now)

    expect(from.getHours()).toBe(0)
    expect(from.getMinutes()).toBe(0)
    expect(from.getSeconds()).toBe(0)
  })

  it('moves by exactly what it shows, so no day appears on two pages', () => {
    const first = bookingWindow(0, now)
    const second = bookingWindow(1, now)

    // Touching: the next page begins where this one ended.
    expect(second.from.getTime()).toBe(first.to.getTime())
  })

  it('leaves no gap either, page after page', () => {
    // A step wider than the window would skip days instead of repeating
    // them, which is the same mistake pointing the other way.
    for (let page = 0; page < 6; page++) {
      const here = bookingWindow(page, now)
      const next = bookingWindow(page + 1, now)

      expect(next.from.getTime()).toBe(here.to.getTime())
      expect(days(here.from, here.to)).toBe(WINDOW_DAYS)
    }
  })

  it('keeps the weekday alignment, so the columns do not shuffle', () => {
    // A fortnight is two whole weeks: every page starts on the same weekday.
    for (let page = 0; page < 6; page++) {
      expect(bookingWindow(page, now).from.getDay()).toBe(bookingWindow(0, now).from.getDay())
    }
  })

  it('starts today on the first page', () => {
    const { from } = bookingWindow(0, now)

    expect(from.getDate()).toBe(8)
    expect(from.getMonth()).toBe(9)
  })
})
