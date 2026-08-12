import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The native shell around the web app.
 *
 * The app inside is https://netvork.app itself, loaded live, not a copy
 * bundled into the APK. That is a deliberate trade: every web deploy updates
 * the installed app the moment it is next opened, with no store release and
 * no fleet of old versions in the field — which, for an app that shipped
 * fixes daily this week, matters more than offline boot. A meetings app has
 * nothing to offer offline anyway.
 *
 * webDir still has to point somewhere for the tooling's sake; dist is only
 * used if server.url is ever removed.
 */
const config: CapacitorConfig = {
  appId: 'app.netvork',
  appName: 'Netvork',
  webDir: 'dist',
  server: {
    url: 'https://netvork.app',
    // Everything of ours stays inside the shell; anything else (payment
    // pages, OAuth) opens where the system browser can vouch for it.
    allowNavigation: ['netvork.app', '*.netvork.app'],
  },
  android: {
    // The webview talks wss:// and https:// only; this stays off so nothing
    // in a store review reads as "allows cleartext traffic".
    allowMixedContent: false,
  },
}

export default config
