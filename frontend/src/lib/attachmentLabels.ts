/**
 * How an attachment is described in a thread.
 *
 * Pure, and kept out of the component, so they can be tested without pulling
 * in the API client — which reaches the socket layer and touches `window` on
 * import.
 */

/** Bytes as somebody would say them. */
export function fileSize(bytes?: number | null): string {
  if (!bytes || bytes < 0) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/**
 * A long filename, shortened in the middle.
 *
 * The end of a filename is the half worth keeping — the extension says what
 * it is, and the characters before it are what tells two screenshots taken a
 * minute apart from each other. A plain truncate keeps the useless half and
 * turns four attachments into four identical lines.
 */
export function shortName(name: string, keep = 28): string {
  if (name.length <= keep) return name

  const head = Math.ceil((keep - 1) / 2)
  const tail = Math.floor((keep - 1) / 2)

  return `${name.slice(0, head)}…${name.slice(-tail)}`
}
