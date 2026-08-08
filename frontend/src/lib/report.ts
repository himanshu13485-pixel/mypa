/**
 * Telling the server that something broke in here.
 *
 * There was no error tracking of any kind: a white screen produced no signal
 * at all, and you found out when somebody got round to complaining. This is
 * the smallest thing that fixes that — no third-party account, no data leaving
 * your own server.
 *
 * It uses fetch directly rather than the API client on purpose. The client
 * carries an auth token, redirects on 401 and can itself be the thing that
 * failed; a reporter that depends on the machinery it is reporting on is no
 * reporter at all.
 */

/** Same fault, same session, reported once. */
const seen = new Set<string>()

/** Hard stop, so a render loop cannot turn one bug into a denial of service. */
const MAX_PER_SESSION = 10
let sent = 0

export function reportError(error: unknown, context?: string): void {
  try {
    if (sent >= MAX_PER_SESSION) return

    const err = error instanceof Error ? error : new Error(String(error))
    const message = [context, err.message].filter(Boolean).join(' — ').slice(0, 1000)
    const stack = err.stack?.slice(0, 8000)

    const key = message + (stack?.split('\n')[1] ?? '')
    if (seen.has(key)) return
    seen.add(key)
    sent++

    void fetch('/api/v1/client-errors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        message,
        stack,
        url: window.location.pathname + window.location.search,
        release: import.meta.env.MODE,
      }),
      // Survives the page being closed a moment later, which is exactly what
      // happens after the sort of error worth hearing about.
      keepalive: true,
    }).catch(() => undefined)
  } catch {
    /* Reporting must never be the thing that throws. */
  }
}

/**
 * Catch what React cannot.
 *
 * An ErrorBoundary only sees errors thrown during render. Everything else —
 * an event handler, a failed import, a rejected promise nobody awaited — goes
 * to the console and no further.
 */
export function installErrorReporting(): void {
  window.addEventListener('error', (event) => {
    // Failed <img>/<script> loads raise this with no error object; they are
    // noise, not faults.
    if (!event.error) return
    reportError(event.error, 'uncaught')
  })

  window.addEventListener('unhandledrejection', (event) => {
    reportError(event.reason, 'unhandled promise')
  })
}
