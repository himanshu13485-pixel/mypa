/**
 * Turning a number somebody typed into a CRM into one a phone can dial.
 *
 * The numbers in here were entered by hand over years, so they arrive as
 * "+91 98765 43210", "098765-43210", "(022) 2758 1234" and every other shape
 * a person might write. A dialler wants the digits; the punctuation is for
 * the reader.
 *
 * RFC 3966 does permit visual separators in a tel: URI, but permitting is not
 * the same as every dialler on every Android build agreeing — and the failure
 * is silent and awkward: the dialler opens holding a number that is subtly
 * wrong, and somebody rings a stranger. Stripping is the safe half of the
 * standard.
 */

/** A leading + is meaning, not punctuation: it is what makes a number international. */
export function telHref(raw: string): string | null {
  const trimmed = raw.trim()
  if (!trimmed) return null

  const international = trimmed.startsWith('+')
  const digits = trimmed.replace(/\D/g, '')

  /*
   * Too short to be a telephone number is almost always a field being used
   * for something else — an extension, a note, a placeholder like "-". A
   * dialler opened on "12" helps nobody, and a link that visibly does nothing
   * is worse than plain text.
   */
  if (digits.length < 6) return null

  return `tel:${international ? '+' : ''}${digits}`
}

/**
 * Whether an address is worth making clickable.
 *
 * Deliberately loose. This is deciding whether to draw a link, not whether to
 * accept the value — the address is already saved either way, and a strict
 * pattern here would only mean a real address with an unusual shape rendering
 * as plain text.
 */
export function mailtoHref(raw: string): string | null {
  const trimmed = raw.trim()
  if (!trimmed || !trimmed.includes('@') || /\s/.test(trimmed)) return null

  return `mailto:${trimmed}`
}
