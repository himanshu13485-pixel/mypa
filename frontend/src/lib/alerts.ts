import { api } from '../api/client'

/**
 * Notification chime (WebAudio — no asset files) and Web Push helpers.
 * Sound preferences are per-device, stored in localStorage.
 */

const SOUND_KEY = 'mypa-notification-sound'

export interface SoundPrefs {
  enabled: boolean
  volume: number // 0..1
}

export function getSoundPrefs(): SoundPrefs {
  try {
    const raw = localStorage.getItem(SOUND_KEY)
    if (raw) return { enabled: true, volume: 0.6, ...JSON.parse(raw) }
  } catch {
    /* corrupted prefs fall back to defaults */
  }
  return { enabled: true, volume: 0.6 }
}

export function setSoundPrefs(prefs: SoundPrefs) {
  localStorage.setItem(SOUND_KEY, JSON.stringify(prefs))
}

/** Two-tone chime; volume follows the saved preference. */
export function playChime() {
  const prefs = getSoundPrefs()
  if (!prefs.enabled || prefs.volume <= 0) return
  try {
    const ctx = new AudioContext()
    const gain = ctx.createGain()
    gain.gain.value = prefs.volume * 0.3
    gain.connect(ctx.destination)

    const tone = (freq: number, start: number, duration: number) => {
      const osc = ctx.createOscillator()
      osc.type = 'sine'
      osc.frequency.value = freq
      const g = ctx.createGain()
      g.gain.setValueAtTime(0, ctx.currentTime + start)
      g.gain.linearRampToValueAtTime(1, ctx.currentTime + start + 0.02)
      g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + duration)
      osc.connect(g)
      g.connect(gain)
      osc.start(ctx.currentTime + start)
      osc.stop(ctx.currentTime + start + duration)
    }
    tone(880, 0, 0.25)
    tone(1174.66, 0.12, 0.3)
    setTimeout(() => ctx.close(), 800)
  } catch {
    /* no audio available (autoplay policy etc.) — silently skip */
  }
}

// --- Web push -------------------------------------------------------------

function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/**
 * The app shell only registers sw.js in production builds, so register it on
 * demand here — this makes push testable on the dev server too. Never await
 * navigator.serviceWorker.ready without a registration: it hangs forever.
 */
async function swRegistration(create: boolean): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator)) return null
  const existing = await navigator.serviceWorker.getRegistration()
  if (existing) return existing
  if (!create) return null
  return navigator.serviceWorker.register('/sw.js')
}

export async function getPushSubscription(): Promise<PushSubscription | null> {
  if (!pushSupported()) return null
  const reg = await swRegistration(false)
  return reg ? reg.pushManager.getSubscription() : null
}

/** Ask permission, subscribe this browser, and register it with the server. */
export async function enablePush(): Promise<void> {
  if (!pushSupported()) throw new Error('This browser does not support push notifications.')

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Notification permission was not granted.')

  const { data } = await api.get<{ data: { key: string | null } }>('/push/public-key')
  if (!data.data.key) throw new Error('Push is not configured on the server.')

  const reg = await swRegistration(true)
  if (!reg) throw new Error('Could not register the service worker.')
  const sub =
    (await reg.pushManager.getSubscription()) ??
    (await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(data.data.key) as unknown as BufferSource,
    }))

  const json = sub.toJSON()
  await api.post('/push/subscribe', {
    endpoint: sub.endpoint,
    keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
  })
}

/** Unsubscribe this browser and remove it from the server. */
export async function disablePush(): Promise<void> {
  const sub = await getPushSubscription()
  if (!sub) return
  await api.post('/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => undefined)
  await sub.unsubscribe()
}
