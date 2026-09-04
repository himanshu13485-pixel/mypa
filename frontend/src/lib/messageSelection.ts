import type { ChatMessage } from '../types'

/**
 * Selecting several messages, the way a chat app does it — pick a few, then
 * forward, copy, star or delete the lot.
 *
 * The rules live here rather than in the component because each one is a
 * small decision that is easy to get subtly wrong and impossible to see
 * afterwards: which messages a bulk delete may take from everybody, what
 * order copied text comes out in, and what the toolbar is allowed to offer.
 *
 * MessageController::MAX_FORWARD_AT_ONCE is the other half of the ceiling.
 */
export const MAX_FORWARD_AT_ONCE = 30

/** Tick or untick one message. Returns a new set; never mutates the old one. */
export function toggleSelected(selected: Set<string>, uuid: string): Set<string> {
  const next = new Set(selected)
  if (!next.delete(uuid)) next.add(uuid)

  return next
}

/**
 * The selected messages, in the order the thread holds them.
 *
 * A Set has insertion order, which is the order things were *tapped* — and
 * somebody ticking the last message first would otherwise copy or forward
 * their conversation backwards. The list is the authority on order; the
 * selection only says which.
 */
export function selectedIn(messages: ChatMessage[], selected: Set<string>): ChatMessage[] {
  return messages.filter((m) => selected.has(m.uuid))
}

/**
 * May every selected message be taken back from everybody?
 *
 * All or nothing, deliberately. A mixed selection — some inside the six-hour
 * window, some past it — could offer "delete for everyone" and then silently
 * do something else to half of them, and the half that stayed would be the
 * half somebody most wanted gone. The server decides eligibility per message
 * in can_delete_for_everyone; this only asks whether the whole selection
 * agrees.
 */
export function canUnsendAll(messages: ChatMessage[], selected: Set<string>): boolean {
  const picked = selectedIn(messages, selected)

  return picked.length > 0 && picked.every((m) => m.can_delete_for_everyone)
}

/**
 * The selection as text on a clipboard.
 *
 * Bodies only, one per line, in thread order. Deliberately without names or
 * timestamps: the overwhelmingly common reason to copy several messages is to
 * paste what was said somewhere else, and a transcript header on every line
 * is something the person then has to delete by hand.
 *
 * Messages with nothing to copy — an attachment on its own, a deleted
 * tombstone — are skipped rather than contributing a blank line.
 */
export function copyTextOf(messages: ChatMessage[], selected: Set<string>): string {
  return selectedIn(messages, selected)
    .filter((m) => !m.is_deleted && !!m.body)
    .map((m) => m.body)
    .join('\n')
}
