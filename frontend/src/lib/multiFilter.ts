/**
 * Checkbox filters, as a request sends them.
 *
 * A filter's state is `null` for everything (nothing is sent), an array for
 * exactly those values, or an empty array for nothing - sent as a value that
 * matches no row, so "cleared" shows an empty list rather than quietly
 * showing everything again.
 */
export const NOTHING = '__none__'

export function listParam(value: string[] | null): string[] | undefined {
  if (value === null) return undefined

  return value.length > 0 ? value : [NOTHING]
}

/** The one value picked, when exactly one is - for cards that toggle a single choice. */
export const onlyOne = (value: string[] | null): string | null => (value && value.length === 1 ? value[0] : null)

/** Checkbox options from a value-to-label map. */
export const optionsFrom = (labels: Record<string, string>) =>
  Object.entries(labels).map(([value, label]) => ({ value, label }))

/** Checkbox options from a plain list of names. */
export const optionsOf = (names: string[]) => names.map((name) => ({ value: name, label: name }))
