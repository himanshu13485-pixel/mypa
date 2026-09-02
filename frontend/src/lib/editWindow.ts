/**
 * How long a sent message stays editable, in minutes.
 *
 * The server refuses an edit past this, so the pencil has to disappear at the
 * same moment: a button that is still there after the window has closed is a
 * button that hands back an error instead of doing the thing it offered.
 * Message::EDIT_WINDOW_MINUTES is the other half of this pair.
 */
export const EDIT_WINDOW_MINUTES = 60

/**
 * Is this message still inside its edit window?
 *
 * A message with no timestamp is treated as editable rather than not: it has
 * only just been sent optimistically and has not come back from the server
 * yet, and hiding the pencil for that second reads as the feature flickering.
 */
export function withinEditWindow(sentAt: string | null | undefined, now: Date = new Date()): boolean {
  if (!sentAt) return true

  const sent = new Date(sentAt).getTime()
  if (Number.isNaN(sent)) return true

  return now.getTime() - sent < EDIT_WINDOW_MINUTES * 60_000
}
