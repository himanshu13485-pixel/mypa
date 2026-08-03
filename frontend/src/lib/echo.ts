import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import axios from 'axios'
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
      /*
       * pusher-js 8 does not carry the legacy `auth.headers` option into the
       * channel-authorization request, so the bearer token never reached the
       * server: every private subscription failed with 401 and no call or
       * meeting signals arrived. Authorize explicitly instead.
       *
       * Plain axios, not the shared api client - that one clears the session on
       * any 401, which would sign the user out if authorization ever failed.
       */
      authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: Error | null, data: { auth: string }) => void) => {
          axios
            .post(
              '/api/broadcasting/auth',
              { socket_id: socketId, channel_name: channel.name },
              {
                headers: {
                  Authorization: `Bearer ${useAuthStore.getState().token}`,
                  Accept: 'application/json',
                },
              },
            )
            .then((response) => callback(null, response.data as { auth: string }))
            .catch((error) => {
              console.warn('[echo] channel authorization failed', channel.name, error)
              callback(error as Error, { auth: '' })
            })
        },
      }),
    })
  }

  return instance
}

export function disconnectEcho(): void {
  instance?.disconnect()
  instance = null
}
