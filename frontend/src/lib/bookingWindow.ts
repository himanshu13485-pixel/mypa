/**
 * Which fortnight of the diary a booking page is showing.
 *
 * Pulled out of the page because it is arithmetic, and because getting it
 * wrong is invisible: the window was a fortnight wide and the arrow moved it
 * a week, so every page quietly showed the second half of the one before it.
 * Paging forward past Thursday the 15th landed on a page starting Wednesday
 * the 14th, and nothing about that looks like a bug until you read the dates.
 *
 * One number decides both how much is shown and how far a page moves, so the
 * two cannot drift apart again.
 */

/** How many days a page shows, and therefore how far the arrows move it. */
export const WINDOW_DAYS = 14

const DAY_MS = 24 * 60 * 60 * 1000

/** Midnight at the start of the day this moment falls in, locally. */
export function startOfLocalDay(date: Date): Date {
  const copy = new Date(date)
  copy.setHours(0, 0, 0, 0)

  return copy
}

/**
 * The range to ask the server for.
 *
 * `to` is the midnight that ends the window, and the server offers nothing
 * at or after it - so page 0 covers today through the thirteenth day, and
 * page 1 takes up at the fourteenth. Touching, never overlapping.
 */
export function bookingWindow(pageOffset: number, now: Date = new Date()): { from: Date; to: Date } {
  const from = new Date(startOfLocalDay(now).getTime() + pageOffset * WINDOW_DAYS * DAY_MS)

  return { from, to: new Date(from.getTime() + WINDOW_DAYS * DAY_MS) }
}
