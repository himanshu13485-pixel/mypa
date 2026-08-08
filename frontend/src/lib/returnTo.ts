/**
 * Where somebody should land once they have signed in or registered.
 *
 * A meeting invite is the deep link that most often reaches a signed-out
 * person, and it used to be dropped at the door: the sign-in form always
 * finished at the dashboard, so an account holder who followed a link had to
 * go and find that link a second time. The intended destination rides along in
 * router state instead, and is read back here.
 *
 * Only in-app paths are honoured. A sign-in form that forwards anywhere it is
 * told is an open redirect — a link that carried somebody through our own
 * login and out to a copy of it would be the obvious use — so anything with a
 * scheme or a host is refused, including the protocol-relative "//host" and
 * the "/\host" that browsers read the same way.
 */
export function returnTo(state: unknown, fallback = '/'): string {
  const to = (state as { from?: unknown } | null | undefined)?.from

  if (typeof to !== 'string' || !/^\/(?![/\\])/.test(to)) return fallback

  return to
}

/** The router state that gets somebody back to `path` after they sign in. */
export function returnState(path: string): { from: string } {
  return { from: path }
}
