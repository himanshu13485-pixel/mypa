/**
 * Whether this is the installed app, and the way into its native side.
 *
 * Kept apart from nativeShell so that asking the question costs nothing.
 * nativeShell pulls in the API client and the auth store to register for
 * ringing, and a file-save helper that only wants to know which of the two
 * places it is running in has no business dragging a websocket client along
 * behind it.
 *
 * There is no Capacitor import here either: the shell injects its bridge onto
 * `window` at load, and its absence is the signal that this is an ordinary
 * browser tab.
 */

export type BridgeListener = (payload: { value?: string; notification?: { data?: Record<string, string> } }) => void

export type BridgePlugin = {
  addListener: (event: string, cb: BridgeListener) => void
  start?: (options: { label?: string }) => Promise<void>
  stop?: () => Promise<void>
  setSpeakerphone?: (options: { on: boolean }) => Promise<void>
  listAudioDevices?: () => Promise<{ devices: { kind: string; label: string }[] }>
  resetAudio?: () => Promise<void>
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

export const bridge = (): Bridge | undefined => (window as { Capacitor?: Bridge }).Capacitor

/** Inside the installed app, as opposed to a browser tab of the same site. */
export const inNativeShell = (): boolean => !!bridge()?.isNativePlatform?.()
