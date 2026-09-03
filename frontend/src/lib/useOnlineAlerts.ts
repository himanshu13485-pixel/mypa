import { useEffect } from 'react'
import { people } from '../api/endpoints'
import {
  desktopAlertsPossible,
  getOnlineAlertPrefs,
  shouldAlert,
} from './onlineAlerts'
import { presenceStore } from './presence'

/**
 * Watch the presence store and raise a desktop pop-up when somebody arrives.
 *
 * Mounted once, in CallManager, beside usePresenceFeed() — which is the thing
 * that fills the store this listens to. Deliberately a subscriber rather than
 * a second socket listener: presence already arrives on one channel and is
 * reconciled in one place, and a second listener would have to repeat that
 * reconciliation to know whether anything had actually changed.
 *
 * The whole hook is inert unless the preference is on, and it is read fresh on
 * every event rather than captured: somebody switching the toggle off in
 * Settings expects the next pop-up not to arrive, not the one after a reload.
 */
export function useOnlineAlerts(): void {
  useEffect(() => {
    if (!desktopAlertsPossible()) return

    /**
     * When each person last raised a pop-up, for the cooldown.
     *
     * Kept in the closure rather than in localStorage: it is about this page
     * being open, and a fresh tab having a clean slate is right — you have
     * just come back to the machine, and who is here is news again.
     */
    const lastAlerted = new Map<string, number>()

    return presenceStore.subscribe((state, previous) => {
      const prefs = getOnlineAlertPrefs()
      if (!prefs.enabled) return

      const now = Date.now()

      for (const [uuid, to] of Object.entries(state.states)) {
        const from = previous.states[uuid]
        if (from === to) continue

        if (!shouldAlert({ prefs, uuid, from, to, lastAlertedAt: lastAlerted.get(uuid), now })) {
          continue
        }

        lastAlerted.set(uuid, now)
        void announce(uuid)
      }
    })
  }, [])
}

/**
 * Put one person's name on screen.
 *
 * The name is fetched at the moment it is needed rather than kept in a map of
 * everybody. The broadcast carries a uuid and nothing else — on purpose, so
 * that a channel anybody is subscribed to never becomes a directory — and the
 * obvious fix, loading the address book up front, is a paginated request that
 * would quietly miss anybody past the first page. One small request behind a
 * half-hour cooldown is cheaper than that, and it is always right.
 *
 * A failure is silence. Somebody whose profile we may not read is somebody we
 * should not be announcing by name anyway.
 */
async function announce(uuid: string): Promise<void> {
  if (Notification.permission !== 'granted') return

  try {
    const person = await people.get(uuid)

    const note = new Notification(`${person.name} is online`, {
      body: 'Just came online on Netvork.',
      // One notification per person, replaced rather than stacked: three
      // arrivals from the same person should never queue up three toasts.
      tag: 'netvork-online-' + uuid,
    })

    note.onclick = () => {
      window.focus()
      note.close()
    }
  } catch {
    /* no name, no pop-up */
  }
}
