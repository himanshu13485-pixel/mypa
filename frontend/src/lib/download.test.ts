import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { saveFile } from './download'

/**
 * Saving a file, in the two places the app runs.
 *
 * A browser is handed the bytes and saves them itself. The Android app
 * cannot: a WebView has no downloading in it at all, and the blob the page
 * builds is dropped with no error anywhere - which is why tapping a file
 * somebody had sent did nothing in the app and worked everywhere else. There
 * the URL goes to the system downloader instead.
 *
 * So what is worth asserting is which of the two happens, and that the one
 * that could never work is not even attempted.
 *
 * The globals are stubbed rather than run under jsdom, which this project
 * does not carry - what is being tested is the choice between the two paths
 * and the order of the clicks, both of which are ours.
 */

type FakeAnchor = { href: string; download: string; click: () => void; remove: () => void }

let anchors: FakeAnchor[]
let appended: FakeAnchor[]
let revoked: string[]

const asApp = (yes: boolean) => {
  ;(globalThis as { window?: unknown }).window = {
    Capacitor: yes ? { isNativePlatform: () => true } : undefined,
    location: { href: '' },
  }
}

const location = () => (globalThis as unknown as { window: { location: { href: string } } }).window.location

beforeEach(() => {
  anchors = []
  appended = []
  revoked = []

  ;(globalThis as { document?: unknown }).document = {
    createElement: () => {
      const anchor: FakeAnchor = {
        href: '',
        download: '',
        click: vi.fn(),
        remove: vi.fn(),
      }
      anchors.push(anchor)

      return anchor
    },
    body: { appendChild: (anchor: FakeAnchor) => appended.push(anchor) },
  }
  ;(globalThis as { URL?: unknown }).URL = {
    createObjectURL: () => 'blob:made-up',
    revokeObjectURL: (url: string) => revoked.push(url),
  }
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  delete (globalThis as { window?: unknown }).window
  delete (globalThis as { document?: unknown }).document
})

describe('saveFile', () => {
  it('in a browser, saves the bytes it was given', async () => {
    asApp(false)
    const fetchBlob = vi.fn().mockResolvedValue(new Blob(['hello']))
    const link = vi.fn()

    await saveFile('quote.pdf', fetchBlob, link)

    expect(fetchBlob).toHaveBeenCalled()
    // No signed link asked for: nothing out here needs one.
    expect(link).not.toHaveBeenCalled()

    expect(anchors).toHaveLength(1)
    expect(anchors[0].download).toBe('quote.pdf')
    expect(anchors[0].click).toHaveBeenCalled()
    // In the document before it is clicked, or Firefox does not follow it.
    expect(appended).toEqual(anchors)
  })

  it('lets the object URL outlive the click', async () => {
    asApp(false)

    await saveFile('quote.pdf', async () => new Blob(['hello']), vi.fn())

    // Revoked on the spot, some browsers save nothing at all.
    expect(revoked).toEqual([])
    vi.advanceTimersByTime(1000)
    expect(revoked).toEqual(['blob:made-up'])
  })

  it('in the app, hands a signed URL to the system downloader', async () => {
    asApp(true)
    const fetchBlob = vi.fn()

    await saveFile('quote.pdf', fetchBlob, async () => 'https://netvork.app/signed')

    expect(location().href).toBe('https://netvork.app/signed')
    expect(anchors).toEqual([])
    /*
     * And the bytes are never fetched. They could not be saved in here, so
     * fetching them would spend somebody's data on nothing - which is what
     * the old code did, every time, before dropping the result.
     */
    expect(fetchBlob).not.toHaveBeenCalled()
  })

  it('lets a failure reach the caller, so it can be said out loud', async () => {
    asApp(false)

    await expect(
      saveFile('quote.pdf', async () => { throw new Error('403') }, vi.fn()),
    ).rejects.toThrow('403')
  })
})
