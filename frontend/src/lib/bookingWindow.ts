/**
 * How a booking page's free times are fetched, and how they are paged.
 *
 * Two different things, which is the whole lesson here. What the server is
 * asked for is a range of calendar days; what the reader pages through is
 * days that actually have a time free. They are not the same count and never
 * will be - a fortnight of calendar days is ten working days, fewer in a
 * week with a holiday in it - so paging by the first while showing the
 * second is how a page ends up repeating days it has already shown.
 *
 * So the whole bookable horizon is asked for once, and the paging is done on
 * the days that came back.
 */

const DAY_MS = 24 * 60 * 60 * 1000

/** How many days with free times are offered at once: a three by three grid. */
export const DAYS_PER_PAGE = 9

/**
 * The most days the server will walk in one request.
 *
 * It refuses a range wider than this rather than spend a minute counting
 * through a year a day at a time, so asking for more is asking for an error.
 */
const SERVER_LIMIT_DAYS = 62

/** Midnight at the start of the day this moment falls in, locally. */
export function startOfLocalDay(date: Date): Date {
  const copy = new Date(date)
  copy.setHours(0, 0, 0, 0)

  return copy
}

/**
 * Everything bookable, in one request.
 *
 * A page will not offer a time further out than max_days_ahead, so there is
 * nothing to be gained by asking past it - and asking for all of it at once
 * means paging never waits on the network. The server's own limit is the
 * backstop for a page configured with a year's notice.
 */
export function bookableRange(maxDaysAhead: number, now: Date = new Date()): { from: Date; to: Date } {
  const days = Math.min(SERVER_LIMIT_DAYS, Math.max(1, Math.floor(maxDaysAhead) + 1))
  const from = startOfLocalDay(now)

  return { from, to: new Date(from.getTime() + days * DAY_MS) }
}

/** How many pages a given number of days with free times makes. */
export function pageCount(totalDays: number): number {
  return Math.max(1, Math.ceil(totalDays / DAYS_PER_PAGE))
}

/**
 * The days shown on one page.
 *
 * Takes whatever the days are - the caller has already grouped the times
 * under them - and returns the ninth of them that belongs on this page.
 */
export function daysOnPage<T>(days: T[], pageIndex: number): T[] {
  const start = Math.max(0, pageIndex) * DAYS_PER_PAGE

  return days.slice(start, start + DAYS_PER_PAGE)
}
