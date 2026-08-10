import { afterEach, describe, expect, it, vi } from 'vitest'
import { screenShareSupported, shareFailureMessage } from './devices'

/**
 * Telling "you changed your mind" apart from "this cannot work here".
 *
 * Both used to land in the same bare catch, so a phone — where no browser can
 * capture a screen — behaved exactly like dismissing the picker: the button
 * did nothing, silently, forever. Get it wrong the other way and every
 * cancelled share nags you about it.
 */
const err = (name: string) => Object.assign(new Error(name), { name })

function withDisplayMedia(present: boolean) {
  const media = { getDisplayMedia: present ? () => Promise.resolve(null) : undefined }
  vi.stubGlobal('navigator', { mediaDevices: media })
}

afterEach(() => vi.unstubAllGlobals())

describe('screenShareSupported', () => {
  it('is true when the browser exposes the API', () => {
    withDisplayMedia(true)
    expect(screenShareSupported()).toBe(true)
  })

  it('is false on a browser without it — every phone, today', () => {
    withDisplayMedia(false)
    expect(screenShareSupported()).toBe(false)
  })

  it('is false rather than throwing when mediaDevices is missing entirely', () => {
    // An insecure origin has no mediaDevices at all; reading through it
    // unguarded would take the whole page down instead of hiding a button.
    vi.stubGlobal('navigator', {})
    expect(screenShareSupported()).toBe(false)
  })
})

describe('shareFailureMessage', () => {
  it('says nothing when the picker was dismissed', () => {
    withDisplayMedia(true)
    // Chrome rejects with NotAllowedError whether you press Cancel or the
    // permission is refused; treating it as cancelled keeps the common case
    // quiet.
    expect(shareFailureMessage(err('NotAllowedError'))).toBeNull()
    expect(shareFailureMessage(err('AbortError'))).toBeNull()
  })

  it('explains the phone case instead of failing silently', () => {
    withDisplayMedia(false)
    const message = shareFailureMessage(err('TypeError'))
    expect(message).toBeTruthy()
    expect(message).toMatch(/phone/i)
  })

  it('names the real fault when the browser can share but did not', () => {
    withDisplayMedia(true)
    expect(shareFailureMessage(err('NotFoundError'))).toMatch(/available to share/i)
    expect(shareFailureMessage(err('NotReadableError'))).toMatch(/another app/i)
  })

  it('still says something for a fault nobody anticipated', () => {
    withDisplayMedia(true)
    expect(shareFailureMessage(err('SomeNewError'))).toBeTruthy()
    expect(shareFailureMessage(null)).toBeTruthy()
  })
})
