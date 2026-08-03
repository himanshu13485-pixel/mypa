/**
 * Repair an SDP that lost its line endings in transit.
 *
 * Session descriptions are CRLF-delimited and must end with a terminator.
 * Anything that trims strings on the way through (Laravel's TrimStrings
 * middleware did exactly this) removes the final CRLF, and Chrome then
 * rejects the whole description with "Invalid SDP line" - while Firefox
 * accepts it, so the failure only shows up between different browsers.
 */
export function normalizeSdp(sdp: string): string {
  const body = sdp.replace(/\r\n/g, '\n').replace(/\n/g, '\r\n')
  return body.endsWith('\r\n') ? body : `${body}\r\n`
}
