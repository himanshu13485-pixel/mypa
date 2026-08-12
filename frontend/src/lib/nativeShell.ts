/**
 * The little the web app does differently inside the Android shell.
 *
 * The shell (frontend/android, built with Capacitor) loads https://netvork.app
 * live, so this file ships to every browser — and must therefore cost the
 * browser nothing. There is no Capacitor import here: the shell injects its
 * bridge onto `window` at load, and its absence is the signal that this is an
 * ordinary tab. Everything below is a no-op outside the app.
 */
import { api } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Listener = (payload: { value?: string; notification?: { data?: Record<string, string> } }) => void
type BridgePlugin = {
  addListener: (event: string, cb: Listener) => void
  minimizeApp?: () => void
  requestPermissions?: () => Promise<{ receive?: string }>
  register?: () => Promise<void>
  createChannel?: (channel: {
    id: string
    name: string
    description?: string
    importance: number
    visibility?: number
    vibration?: boolean
    /** Filename in the shell's res/raw, extension included. */
    sound?: string
  }) => Promise<void>
}
type Bridge = {
  isNativePlatform?: () => boolean
  Plugins?: Record<string, BridgePlugin>
}

const bridge = (): Bridge | undefined => (window as { Capacitor?: Bridge }).Capacitor

/** Inside the installed app, as opposed to a browser tab of the same site. */
export const inNativeShell = (): boolean => !!bridge()?.isNativePlatform?.()

export function installNativeShell(): void {
  if (!inNativeShell()) return

  /*
   * The Android back button. Capacitor's default when nobody listens is to
   * close the activity — so the most reflexive gesture on the platform would
   * tear down the webview, and with it any meeting or call in progress, with
   * no confirmation. Instead it walks the app's own history, and at the
   * history's end it minimises: the app goes to the background with the
   * meeting still running, which is what every native meetings app does.
   */
  bridge()?.Plugins?.App?.addListener('backButton', () => {
    if (window.history.length > 1) window.history.back()
    else bridge()?.Plugins?.App?.minimizeApp?.()
  })

  /*
   * Deep links into the shell — including the ringing notification's Answer
   * button, whose intent carries the full join URL. Capacitor reports the
   * launch URL here whether the app was warm or cold; only our own host is
   * honoured, and only its path travels into the SPA.
   */
  bridge()?.Plugins?.App?.addListener('appUrlOpen', (payload) => {
    const raw = (payload as { url?: string }).url
    if (!raw) return
    try {
      const target = new URL(raw)
      if (target.hostname.endsWith('netvork.app')) {
        window.location.assign(target.pathname + target.search)
      }
    } catch { /* not a URL; nothing to open */ }
  })

  void installNativeRinging()
}

/**
 * How the app rings when it is closed.
 *
 * The WebView has no Push API — that is a Chrome feature, not a WebView one —
 * so the web push every browser tab uses simply does not exist in here.
 * Instead the shell registers with Firebase, hands the token to the server,
 * and the server rings this device over FCM alongside every web push it sends.
 *
 * The 'calls' channel is created here, by the app, at maximum importance:
 * Android displays incoming-call notifications on it even when the app
 * process is dead, which is the entire point. Note the sound is the system
 * notification sound, loud and heads-up but once — a rolling 30-second
 * ringtone with Answer/Decline on the lock screen needs a native
 * ConnectionService, which is future work, not this.
 */
async function installNativeRinging(): Promise<void> {
  const push = bridge()?.Plugins?.PushNotifications
  if (!push) return

  try {
    /*
     * calls2, because Android notification channels are immutable: the first
     * build shipped 'calls' without a sound, and once a channel exists its
     * settings belong to the device — recreating it with a ringtone changes
     * nothing. A new id starts fresh on every phone, the old channel is
     * removed so Settings does not show a dead duplicate, and the server
     * addresses rings to the new name.
     *
     * The sound is res/raw/ringtone.wav in the shell — a 20-second dual-tone
     * telephone ring, synthesized (no licence to carry). Android plays a
     * channel sound in full, so a closed-app call rings like a phone rather
     * than dinging like a text. It still will not loop until answered or
     * take over the lock screen; that is ConnectionService work, still ahead.
     */
    await (push as unknown as { deleteChannel?: (c: { id: string }) => Promise<void> })
      .deleteChannel?.({ id: 'calls' })?.catch(() => undefined)
    await push.createChannel?.({
      id: 'calls2',
      name: 'Incoming calls',
      description: 'Rings when somebody calls you',
      importance: 5,
      visibility: 1,
      vibration: true,
      sound: 'ringtone.wav',
    })
    await push.createChannel?.({
      id: 'default',
      name: 'Notifications',
      importance: 4,
    })
  } catch (err) {
    console.warn('[shell] could not create notification channels', err)
  }

  /*
   * The token arrives whenever Firebase pleases, and signing in happens
   * whenever the user pleases — the registration POST needs both. Whichever
   * comes second triggers the send.
   */
  let token: string | null = null
  let sent: string | null = null

  const flush = () => {
    if (!token || token === sent || !useAuthStore.getState().token) return
    const current = token
    api.post('/push/fcm-token', { token: current, platform: 'android' })
      .then(() => { sent = current })
      .catch((err) => console.warn('[shell] could not register for ringing', err))
  }

  push.addListener('registration', (payload) => {
    token = payload.value ?? null
    flush()
  })
  push.addListener('registrationError', (err) => console.warn('[shell] push registration failed', err))
  useAuthStore.subscribe(flush)

  /*
   * A tapped notification is a person answering: take them straight to what
   * rang. The url is the same one the web push carries, so both transports
   * land in the same place.
   */
  push.addListener('pushNotificationActionPerformed', (payload) => {
    const url = payload.notification?.data?.url
    if (url && url.startsWith('/')) window.location.assign(url)
  })

  try {
    const status = await push.requestPermissions?.()
    if (status?.receive === 'granted') await push.register?.()
  } catch (err) {
    console.warn('[shell] push permission request failed', err)
  }
}
