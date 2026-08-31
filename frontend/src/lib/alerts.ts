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

/*
 * One audio context, woken at the first touch of the page.
 *
 * A context built before the page has been interacted with is born suspended,
 * and a suspended context does not throw or fail — it simply plays nothing,
 * for as long as you ask it to. A tab opened in the morning and left alone was
 * therefore silent for every call that came in, ringing its full loop with no
 * error anywhere to show for it.
 *
 * Nothing here can make sound on a page that has never been touched; browsers
 * do not allow it, and that is what the notification is for. What this does is
 * make sure a page that HAS been touched, at any point, actually rings — and
 * that a ring already in progress comes alive the moment somebody clicks.
 */
let shared: AudioContext | null = null

function audio(): AudioContext | null {
  try {
    shared ??= new AudioContext()
    if (shared.state === 'suspended') void shared.resume().catch(() => undefined)
    return shared
  } catch {
    return null
  }
}

if (typeof window !== 'undefined') {
  // Not `once`: a context can be suspended again later, and asking a running
  // one to resume costs nothing.
  const wake = () => void audio()
  window.addEventListener('pointerdown', wake)
  window.addEventListener('keydown', wake)
}

/** Two-tone chime; volume follows the saved preference. */
export function playChime() {
  const prefs = getSoundPrefs()
  if (!prefs.enabled || prefs.volume <= 0) return
  const ctx = audio()
  if (!ctx) return
  try {
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
    // Let go of the nodes, not the context — it is shared now, and closing it
    // would silence everything that came after.
    setTimeout(() => gain.disconnect(), 800)
  } catch {
    /* no audio available (autoplay policy etc.) — silently skip */
  }
}

/**
 * Looping call tone. 'incoming' = classic double-ring, 'outgoing' = softer
 * ring-back. Returns a stop function. Volume follows the sound preference,
 * but an incoming call always rings at least quietly — a silent phone hides calls.
 */
export function startRingtone(kind: 'incoming' | 'outgoing'): () => void {
  let stopped = false
  let timer: ReturnType<typeof setInterval> | null = null
  let master: GainNode | null = null
  const ctx = audio()

  try {
    if (!ctx) throw new Error('no audio')
    const prefs = getSoundPrefs()
    const volume = Math.max(kind === 'incoming' ? 0.15 : 0.05, prefs.enabled ? prefs.volume : 0) * 0.3
    // Held locally as well: the closure below cannot see through a `let` that
    // the stop function also clears.
    const bus = ctx.createGain()
    bus.gain.value = volume
    bus.connect(ctx.destination)
    master = bus

    const burst = () => {
      if (stopped || !ctx) return
      // Each repeat asks again. A ring that began on a page nobody had touched
      // yet becomes audible from the next burst, rather than staying silent
      // for the whole call because of how it started.
      if (ctx.state === 'suspended') void ctx.resume().catch(() => undefined)
      const freqs = kind === 'incoming' ? [880, 960] : [440, 480]
      const beep = (start: number, duration: number) => {
        if (!ctx) return
        const osc1 = ctx.createOscillator()
        const osc2 = ctx.createOscillator()
        osc1.frequency.value = freqs[0]
        osc2.frequency.value = freqs[1]
        const g = ctx.createGain()
        g.gain.setValueAtTime(0, ctx.currentTime + start)
        g.gain.linearRampToValueAtTime(1, ctx.currentTime + start + 0.03)
        g.gain.setValueAtTime(1, ctx.currentTime + start + duration - 0.05)
        g.gain.linearRampToValueAtTime(0, ctx.currentTime + start + duration)
        osc1.connect(g)
        osc2.connect(g)
        g.connect(bus)
        osc1.start(ctx.currentTime + start)
        osc2.start(ctx.currentTime + start)
        osc1.stop(ctx.currentTime + start + duration)
        osc2.stop(ctx.currentTime + start + duration)
      }
      if (kind === 'incoming') {
        beep(0, 0.4)
        beep(0.6, 0.4)
      } else {
        beep(0, 1.0)
      }
    }

    burst()
    timer = setInterval(burst, kind === 'incoming' ? 2500 : 3000)
  } catch {
    /* audio unavailable */
  }

  return () => {
    stopped = true
    if (timer) clearInterval(timer)
    master?.disconnect()
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

/**
 * Subscribe this browser and tell the server, assuming permission is settled.
 *
 * Split out from enablePush so the silent path below can reuse it without
 * going anywhere near Notification.requestPermission().
 */
async function subscribeAndRegister(): Promise<void> {
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

/** Ask permission, subscribe this browser, and register it with the server. */
export async function enablePush(): Promise<void> {
  if (!pushSupported()) throw new Error('This browser does not support push notifications.')

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Notification permission was not granted.')

  await subscribeAndRegister()
}

/**
 * Keep an already-permitted browser registered, silently, on every load.
 *
 * Notifications are meant to be on by default, and for the phone and the bell
 * they are. Browser push was the exception: it stayed off until somebody found
 * the toggle in Settings, so people who had already granted permission — who
 * had, in other words, already said yes — went on receiving nothing.
 *
 * It also repairs the case that is otherwise invisible. A subscription can
 * stop working while the browser still believes in it: the endpoint expires,
 * the server gets a 410 on the next send and prunes the row, and from then on
 * the toggle reads "on" and nothing ever arrives again. Re-registering on each
 * load puts the row back, and re-subscribes outright if the browser has since
 * dropped its own.
 *
 * Deliberately silent: it returns immediately unless permission is ALREADY
 * granted, so it can never produce a permission prompt out of nowhere. Asking
 * remains something a person does on purpose, in Settings.
 *
 * Failures are ignored. This is opportunistic repair on a page load; if it
 * does not work the Settings toggle still reports its errors properly.
 */
export async function ensurePushRegistered(): Promise<void> {
  if (!pushSupported() || Notification.permission !== 'granted') return

  try {
    await subscribeAndRegister()
  } catch {
    /* nothing here is worth interrupting a page load for */
  }
}

/** Unsubscribe this browser and remove it from the server. */
export async function disablePush(): Promise<void> {
  const sub = await getPushSubscription()
  if (!sub) return
  await api.post('/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => undefined)
  await sub.unsubscribe()
}
