import { describe, expect, it } from 'vitest'
import { bookableRange, DAYS_PER_PAGE, daysOnPage, pageCount } from './bookingWindow'

const DAY_MS = 24 * 60 * 60 * 1000
const days = (a: Date, b: Date) => Math.round((b.getTime() - a.getTime()) / DAY_MS)

/**
 * Paging through somebody's free time.
 *
 * This went wrong once already by counting two different things with one
 * number: the window asked of the server was a fortnight of calendar days,
 * the arrow moved it a week, and what the reader saw was neither - it was
 * however many of those days happened to have a time free. Page forward and
 * the same two days came round again.
 *
 * The fetch and the paging are separate now, and these are the questions
 * that keep them separate.
 */
describe('what is asked of the server', () => {
  const now = new Date('2026-10-08T15:30:00')

  it('asks for everything that can be booked, and no more', () => {
    // A page offering thirty days ahead has nothing to say about day
    // thirty-one, so asking for it is asking the server to walk it for
    // nothing.
    const { from, to } = bookableRange(30, now)

    expect(days(from, to)).toBe(31)
  })

  it('stops at the widest range the server will walk', () => {
    // It refuses past this rather than spend a minute counting through a
    // year, so asking for more is asking for an error instead of an answer.
    expect(days(...Object.values(bookableRange(3650, now)) as [Date, Date])).toBe(62)
  })

  it('starts at midnight, not at whatever time it happens to be', () => {
    // From half past three, this morning's free times would be missing.
    const { from } = bookableRange(30, now)

    expect(from.getHours()).toBe(0)
    expect(from.getMinutes()).toBe(0)
  })

  it('always asks for at least a day, whatever the page says', () => {
    // A page set to zero days ahead still has today.
    expect(days(...Object.values(bookableRange(0, now)) as [Date, Date])).toBe(1)
    expect(days(...Object.values(bookableRange(-5, now)) as [Date, Date])).toBe(1)
  })
})

describe('paging the days that have a time free', () => {
  const made = (count: number) => Array.from({ length: count }, (_, i) => `day ${i + 1}`)

  it('shows nine days to a page', () => {
    expect(DAYS_PER_PAGE).toBe(9)
    expect(daysOnPage(made(30), 0)).toEqual(made(9))
  })

  it('carries the rest to the next page, and loses none of them', () => {
    const all = made(23)
    const paged = [0, 1, 2].flatMap((p) => daysOnPage(all, p))

    // Every day appears exactly once, in order: the fault this replaced
    // showed some of them twice.
    expect(paged).toEqual(all)
  })

  it('leaves the last page short rather than padding it', () => {
    expect(daysOnPage(made(23), 2)).toEqual(['day 19', 'day 20', 'day 21', 'day 22', 'day 23'])
  })

  it('has nothing on a page past the end', () => {
    expect(daysOnPage(made(9), 1)).toEqual([])
  })

  it('counts the pages the days make', () => {
    expect(pageCount(0)).toBe(1)     // one empty page, not none to look at
    expect(pageCount(9)).toBe(1)
    expect(pageCount(10)).toBe(2)
    expect(pageCount(18)).toBe(2)
    expect(pageCount(19)).toBe(3)
  })

  it('does not count calendar days, which is the mistake it replaced', () => {
    /*
     * A fortnight of calendar days is ten working days, or nine in a week
     * with a holiday in it. Paging by the calendar while showing only the
     * days with times free is what made pages overlap.
     */
    const workingDays = made(10)

    expect(pageCount(workingDays.length)).toBe(2)
    expect(daysOnPage(workingDays, 1)).toEqual(['day 10'])
  })
})
