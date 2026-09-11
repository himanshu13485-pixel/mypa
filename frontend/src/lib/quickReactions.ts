/**
 * The emoji offered first when reacting to a message.
 *
 * 👍 is not in the ordering at all - it is always first, because it is the
 * one reaction that means "seen, agreed, nothing more to add" in every chat
 * anybody has ever had, and a person reaching for it should never have to
 * look for it. The rest of the row is whatever this person actually uses,
 * counted locally and kept locally: what somebody reacts with is not
 * something the server needs to know.
 */
const KEY = 'netvork-reaction-use'

export const THUMBS_UP = '👍'

/** The starting row, for somebody who has not reacted to anything yet. */
export const DEFAULT_REACTIONS = [THUMBS_UP, '❤️', '😂', '😮', '😢', '🙏']

type Counts = Record<string, number>

function read(): Counts {
  try {
    const raw = localStorage.getItem(KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : null
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}

    // Anything that is not a positive number is not a count.
    return Object.fromEntries(
      Object.entries(parsed as Counts).filter(([, n]) => typeof n === 'number' && n > 0),
    )
  } catch {
    // A private window, cleared storage, or something else's key on ours.
    return {}
  }
}

/** One more use of this emoji. Silent if storage is unavailable. */
export function recordReaction(emoji: string): void {
  try {
    const counts = read()
    counts[emoji] = (counts[emoji] ?? 0) + 1
    localStorage.setItem(KEY, JSON.stringify(counts))
  } catch { /* the row simply stays as it was */ }
}

/**
 * The row to show: 👍, then this person's most-used, then the defaults to
 * fill. Never shorter than `count`, and never repeats one.
 */
export function quickReactions(count = 6): string[] {
  const counts = read()

  const mine = Object.entries(counts)
    .filter(([emoji]) => emoji !== THUMBS_UP)
    .sort((a, b) => b[1] - a[1])
    .map(([emoji]) => emoji)

  const row = [THUMBS_UP]
  for (const emoji of [...mine, ...DEFAULT_REACTIONS]) {
    if (row.length >= count) break
    if (!row.includes(emoji)) row.push(emoji)
  }

  return row
}
