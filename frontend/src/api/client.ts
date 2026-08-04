import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { disconnectEcho } from '../lib/echo'

export const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
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
