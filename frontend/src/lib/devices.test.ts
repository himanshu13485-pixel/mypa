import { afterEach, describe, expect, it, vi } from 'vitest'
import { openMedia, preferredSpeaker } from './devices'

/*
 * Opening a camera is not one call that works or does not. A camera is often
 * exclusive, it is not free the instant it is stopped, and a saved deviceId
 * goes stale whenever the device list changes. These are the retreats that
 * turn "your camera is busy" back into a working meeting.
 */
describe('openMedia', () => {
  const err = (name: string) => Object.assign(new Error(name), { name })
  const media = () => ({ id: 'stream' } as unknown as MediaStream)

  const withGum = (impl: (c: MediaStreamConstraints) => Promise<MediaStream>) => {
    const calls: MediaStreamConstraints[] = []
    const spy = vi.fn((c: MediaStreamConstraints) => { calls.push(c); return impl(c) })
    vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: spy } })
    return calls
  }

  afterEach(() => vi.unstubAllGlobals())

  it('retries a camera that has not been let go of yet', async () => {
    // stop() returns before the OS releases the device, so the next request
    // lands in the gap. One retry is usually all it takes.
    let n = 0
    const calls = withGum(async () => {
      if (++n < 2) throw err('NotReadableError')
      return media()
    })
    await expect(openMedia({ video: true })).resolves.toBeTruthy()
    expect(calls).toHaveLength(2)
  })

  it('falls back to any camera when the saved one is gone', async () => {
    // A saved deviceId goes stale when the device list changes, and `exact`
    // turns that into a hard failure — which is why flipping camera "fixed" it.
    const calls = withGum(async (c) => {
      const v = c.video as MediaTrackConstraints
      if (v?.deviceId) throw err('OverconstrainedError')
      return media()
    })
    await expect(openMedia({ video: { deviceId: { exact: 'gone' } } })).resolves.toBeTruthy()
    expect((calls.at(-1)!.video as MediaTrackConstraints).deviceId).toBeUndefined()
  })

  it('does not retry a refused permission', async () => {
    // Asking four times over a second changes nothing and delays the message.
    const calls = withGum(async () => { throw err('NotAllowedError') })
    await expect(openMedia({ video: true })).rejects.toThrow()
    expect(calls).toHaveLength(1)
  })

  it('reports the original failure, not the fallback’s', async () => {
    const calls = withGum(async () => { throw err('NotReadableError') })
    await expect(openMedia({ video: { deviceId: { exact: 'x' } } })).rejects.toMatchObject({
      name: 'NotReadableError',
    })
    expect(calls.length).toBeGreaterThan(1)
  })
})

describe('preferredSpeaker', () => {
  const out = (label: string, deviceId = label) => ({ deviceId, label, kind: 'audiooutput' as const })

  it('always prefers a headset, in either context', () => {
    // Pairing a headset is itself the instruction; nothing outranks it.
    const devices = [out('Speakerphone'), out('Earpiece'), out('Galaxy Buds (Bluetooth)')]
    expect(preferredSpeaker(devices, 'call')).toBe('Galaxy Buds (Bluetooth)')
    expect(preferredSpeaker(devices, 'meeting')).toBe('Galaxy Buds (Bluetooth)')
  })

  it('puts the earpiece above the loudspeaker on a call', () => {
    // A call is held to the head.
    const devices = [out('Speakerphone'), out('Earpiece')]
    expect(preferredSpeaker(devices, 'call')).toBe('Earpiece')
  })

  it('puts the loudspeaker above the earpiece in a meeting', () => {
    // A meeting is watched at arm's length — the one deliberate difference.
    const devices = [out('Earpiece'), out('Speakerphone')]
    expect(preferredSpeaker(devices, 'meeting')).toBe('Speakerphone')
  })

  it('ignores microphones and cameras', () => {
    const devices = [
      { deviceId: 'mic', label: 'Headset microphone', kind: 'audioinput' as const },
      out('Earpiece'),
    ]
    expect(preferredSpeaker(devices, 'call')).toBe('Earpiece')
  })

  it('prefers an unlabelled device to the wrong known one', () => {
    // Labels are empty until permission is granted. Something unrecognised is
    // likelier to be a real output than the earpiece is to be right here.
    const devices = [out('Earpiece'), out('Speaker 2', 'unknown-id')]
    expect(preferredSpeaker(devices, 'meeting')).toBe('unknown-id')
  })

  it('says nothing when there is nothing to say', () => {
    // No devices, or none with an id — the caller leaves the browser's own
    // default alone rather than applying a guess.
    expect(preferredSpeaker([], 'call')).toBeNull()
    expect(preferredSpeaker([out('Earpiece', '')], 'call')).toBeNull()
  })
})
