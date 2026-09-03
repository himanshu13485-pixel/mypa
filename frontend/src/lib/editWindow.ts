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

/**
 * How long a sent message can still be unsent for everybody, in hours.
 *
 * Longer than the edit window, and for a different reason. An edit rewrites
 * what the conversation was told, so it has to be short enough that nobody has
 * replied to the old wording yet. Unsending removes the message and leaves a
 * mark saying it was there, which is honest about having happened — so it can
 * afford to reach back to the case people actually need it for: the message
 * sent to the wrong chat and noticed after lunch.
 *
 * Only used for the wording on the dialog. Whether the option is offered at
 * all is answered by the server, per message, in `can_delete_for_everyone` —
 * because the other half of the rule is whether the reader runs the group, and
 * the browser has no business guessing at that.
 *
 * Message::DELETE_WINDOW_HOURS is the other half of this pair.
 */
export const DELETE_WINDOW_HOURS = 6
