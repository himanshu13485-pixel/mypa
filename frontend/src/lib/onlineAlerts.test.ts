import { describe, expect, it } from 'vitest'
import {
  ALERT_COOLDOWN_MS,
  DEFAULT_ONLINE_ALERTS,
  shouldAlert,
  type OnlineAlertPrefs,
} from './onlineAlerts'

const on = (over: Partial<OnlineAlertPrefs> = {}): OnlineAlertPrefs => ({
  ...DEFAULT_ONLINE_ALERTS,
  enabled: true,
  ...over,
})

const NOW = 1_700_000_000_000

describe('shouldAlert', () => {
  it('says nothing at all while the preference is off', () => {
    expect(
      shouldAlert({ prefs: DEFAULT_ONLINE_ALERTS, uuid: 'a', from: 'offline', to: 'online', now: NOW }),
    ).toBe(false)
  })

  it('announces somebody arriving from offline', () => {
    expect(shouldAlert({ prefs: on(), uuid: 'a', from: 'offline', to: 'online', now: NOW })).toBe(true)
  })

  it('announces the first thing it ever hears about a person', () => {
    // The server only broadcasts changes, so hearing 'online' for a stranger
    // means they just arrived — not that the page has finally caught up.
    expect(shouldAlert({ prefs: on(), uuid: 'a', from: undefined, to: 'online', now: NOW })).toBe(true)
  })

  it('stays quiet for stepping away and for coming back to a page already open', () => {
    expect(shouldAlert({ prefs: on(), uuid: 'a', from: 'online', to: 'away', now: NOW })).toBe(false)
    expect(shouldAlert({ prefs: on(), uuid: 'a', from: 'online', to: 'offline', now: NOW })).toBe(false)
  })

  it('does not announce the same person twice within the cooldown', () => {
    // Somebody reading, pausing three minutes, then typing again crosses back
    // into online without having gone anywhere.
    expect(
      shouldAlert({
        prefs: on(),
        uuid: 'a',
        from: 'away',
        to: 'online',
        lastAlertedAt: NOW - 60_000,
        now: NOW,
      }),
    ).toBe(false)

    expect(
      shouldAlert({
        prefs: on(),
        uuid: 'a',
        from: 'away',
        to: 'online',
        lastAlertedAt: NOW - ALERT_COOLDOWN_MS - 1,
        now: NOW,
      }),
    ).toBe(true)
  })

  it('keeps to the watch list when the scope is narrowed to it', () => {
    const prefs = on({ scope: 'watched', watching: ['watched-one'] })

    expect(shouldAlert({ prefs, uuid: 'watched-one', from: 'offline', to: 'online', now: NOW })).toBe(true)
    expect(shouldAlert({ prefs, uuid: 'somebody-else', from: 'offline', to: 'online', now: NOW })).toBe(false)
  })

  it('the cooldown is counted per person, not for everybody at once', () => {
    const prefs = on()

    // One arrival must not silence the next person through the door.
    expect(
      shouldAlert({ prefs, uuid: 'b', from: 'offline', to: 'online', lastAlertedAt: undefined, now: NOW }),
    ).toBe(true)
  })
})
