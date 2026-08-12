/**
 * The little the web app does differently inside the Android shell.
 *
 * The shell (frontend/android, built with Capacitor) loads https://netvork.app
 * live, so this file ships to every browser — and must therefore cost the
 * browser nothing. There is no Capacitor import here: the shell injects its
 * bridge onto `window` at load, and its absence is the signal that this is an
 * ordinary tab. Everything below is a no-op outside the app.
 */

type BridgePlugin = { addListener: (event: string, cb: () => void) => void; minimizeApp?: () => void }
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
}
