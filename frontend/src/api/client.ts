import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { disconnectEcho } from '../lib/echo'
import { clearGuestPass, readGuestPass } from '../lib/guestPass'

export const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
    return config
  }

  /*
   * No account, but a pass for one meeting.
   *
   * The room calls the ordinary meeting endpoints, so rather than teach every
   * one of those call sites about guests, the pass is swapped in here and the
   * path is pointed at the narrower guest routes. Only that meeting's paths
   * are rewritten — a guest pass must not be attached to anything else.
   */
  const pass = readGuestPass()
  if (pass && config.url?.startsWith(`/meetings/${pass.code}`)) {
    config.headers.Authorization = `Bearer ${pass.token}`
    config.url = `/guest${config.url}`
    return config
  }

  /*
   * The handful of endpoints a guest needs that are not about one meeting.
   *
   * These keep their own path — there is no guest twin to point at — and are
   * resolved server-side from the pass instead. The ICE servers are the whole
   * list for now, and they are not optional: a peer connection cannot be built
   * without them, so a guest missing this saw the room fail the moment
   * somebody else was in it.
   */
  if (pass && config.url === '/calls/config') {
    config.headers.Authorization = `Bearer ${pass.token}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const body = error.response?.data as { code?: string } | undefined

    // A guest's half hour is up. Say so where they are, rather than letting it
    // fall through to the session handling below and look like a failure —
    // they have done nothing wrong and may simply want another 30 minutes.
    if (error.response?.status === 401 && body?.code === 'guest_pass_expired') {
      const pass = readGuestPass()
      disconnectEcho()
      clearGuestPass()
      if (pass && !window.location.pathname.startsWith('/join/')) {
        window.location.assign(`/join/${pass.code}?expired=1`)
      }
      return Promise.reject(error)
    }

    if (error.response?.status === 401) {
      // Drop the socket with the session: it is subscribed to this user's
      // private channel, and leaving it open would deliver their call and
      // meeting signals to whoever signs in next on this browser.
      disconnectEcho()
      useAuthStore.getState().clear()
    }

    // The account exists but its address was never confirmed. Send it to the
    // verification screen rather than surfacing a bare "forbidden" on whatever
    // page happened to make the call.
    if (error.response?.status === 403 && body?.code === 'email_unverified') {
      if (window.location.pathname !== '/verify-email') {
        window.location.assign('/verify-email')
      }
    }

    return Promise.reject(error)
  },
)

export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    /*
     * An upload the server threw away before Laravel ever saw it.
     *
     * PHP compares the request body against post_max_size in the web server,
     * so an oversized upload never reaches the application: there is no
     * validation error to read, and what comes back is a bare 413 or, on some
     * hosts, an empty 500 with the body discarded. Either way the app said
     * "Request failed with status code 413", which tells nobody anything.
     */
    const status = error.response?.status
    const upload = error.config?.data instanceof FormData
    if (status === 413 || (upload && (status === 500 || status === 0 || !error.response))) {
      return 'That file is too large to upload. Try a smaller one, or ask an admin to raise the server limit.'
    }

    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    if (data?.errors) {
      const first = Object.values(data.errors)[0]
      if (first?.length) return first[0]
    }
    if (data?.message) return data.message
    return error.message
  }
  return 'Something went wrong.'
}
