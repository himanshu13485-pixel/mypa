/**
 * Splice text into a string at a cursor position, rather than always at the
 * end.
 *
 * Pulled out of the emoji picker's onPick handler so it can be tested without
 * a DOM: the interesting part is the string arithmetic (three slices and an
 * offset), not the `<input>` it is normally wired to. "Typing 😊 and finishing
 * the sentence" is the ordinary case a mid-message emoji button exists for,
 * and appending to the end instead would move every emoji picked mid-sentence
 * to the wrong place the moment typing continued.
 *
 * `start`/`end` come from `HTMLInputElement.selectionStart/End`, which is a
 * *range* rather than a point whenever text is selected — the insertion
 * replaces the selection, matching what typing over a selection does.
 */
export function insertAtCursor(
  text: string,
  insertion: string,
  start: number,
  end: number,
): { text: string; cursor: number } {
  return {
    text: text.slice(0, start) + insertion + text.slice(end),
    cursor: start + insertion.length,
  }
}
