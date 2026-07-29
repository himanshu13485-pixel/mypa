import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '../stores/auth'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

window.Pusher = Pusher

let instance: Echo<'reverb'> | null = null

/** Lazily connect to the Reverb WebSocket server with the current token. */
export function getEcho(): Echo<'reverb'> | null {
  const token = useAuthStore.getState().token
  if (!token) return null

  if (!instance) {
    instance = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: '/api/broadcasting/auth',
      auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
    })
  }

  return instance
}

export function disconnectEcho(): void {
  instance?.disconnect()
  instance = null
}
