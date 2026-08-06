import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { disconnectEcho } from '../lib/echo'

export const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

/**
 * A meeting pass held by somebody with no account.
 *
 * Kept in sessionStorage: it belongs to this tab and this sitting, lasts 30
 * minutes, and should not outlive either.
 */
function guestPass(): { code: string; token: string } | null {
  try {
    const raw = sessionStorage.getItem('mypa-guest-pass')
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

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
  const pass = guestPass()
  if (pass && config.url?.startsWith(`/meetings/${pass.code}`)) {
    config.headers.Authorization = `Bearer ${pass.token}`
    config.url = `/guest${config.url}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
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
    const data = error.response?.data as { code?: string } | undefined
    if (error.response?.status === 403 && data?.code === 'email_unverified') {
      if (window.location.pathname !== '/verify-email') {
        window.location.assign('/verify-email')
      }
    }

    return Promise.reject(error)
  },
)

export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
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
