/**
 * How many messages arrived while the reader was looking further up.
 *
 * Counted by identity, not by how long the list got. The messages endpoint
 * hands back a fixed window of the most recent messages, so when two arrive
 * two others fall off the top and the length is exactly what it was — a
 * counter watching the length would report nothing had happened, forever.
 *
 * Recomputing from the set each time also makes this idempotent: a refetch
 * that returns the same messages adds nothing, where an incrementing counter
 * would count them again.
 */
export function countUnseen(
  messages: { uuid: string; is_own?: boolean }[] | undefined,
  seen: Set<string>,
): number {
  if (!messages?.length) return 0

  /*
   * Your own messages are never news to you. Sending one scrolls the list
   * down anyway, but a message sent from your phone while this tab sits
   * scrolled up would otherwise announce itself back to you.
   */
  return messages.filter((m) => !m.is_own && !seen.has(m.uuid)).length
}

/** Everything currently on screen, taken as read. */
export function seenIdsOf(messages: { uuid: string }[] | undefined): Set<string> {
  return new Set((messages ?? []).map((m) => m.uuid))
}
