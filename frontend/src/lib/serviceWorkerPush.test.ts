import { beforeEach, describe, expect, it, vi } from 'vitest'
// Vite hands us the file's text; no Node builtins, so this needs no @types/node.
import swSource from '../../public/sw.js?raw'

/**
 * What the service worker does when a push arrives.
 *
 * This runs the real public/sw.js — not a copy of its logic — inside a fake
 * worker global, and fires genuine push events at it. That matters here more
 * than usual: the worker is the only part of the call machinery that runs when
 * the app is closed, which is exactly when nobody is watching it, and it can
 * neither be typechecked with the app nor exercised by a browser test.
 *
 * The bug it is here to hold down: a ring is posted with requireInteraction
 * and no timeout, so it stays on screen until something takes it away. Nothing
 * did. A caller who hung up left a permanent "incoming call" offering Answer
 * and Decline for a call that had been over for hours.
 */

type Handler = (event: unknown) => void

interface FakeNotification {
  tag?: string
  options: Record<string, unknown>
  closed: boolean
}

function loadWorker() {
  const handlers = new Map<string, Handler>()
  const shown: FakeNotification[] = []

  const registration = {
    showNotification: vi.fn(async (title: string, options: Record<string, unknown>) => {
      shown.push({ tag: options.tag as string | undefined, options: { title, ...options }, closed: false })
    }),
    getNotifications: vi.fn(async ({ tag }: { tag?: string } = {}) =>
      shown.filter((n) => !n.closed && (tag === undefined || n.tag === tag)).map((n) => ({
        ...n,
        close: () => { n.closed = true },
      })),
    ),
    pushManager: { subscribe: vi.fn() },
  }

  const self = {
    addEventListener: (name: string, fn: Handler) => handlers.set(name, fn),
    skipWaiting: () => undefined,
    clients: { claim: () => undefined, matchAll: async () => [], openWindow: async () => undefined },
    registration,
  }

  const context = {
    self,
    caches: { open: async () => ({ put: async () => undefined, match: async () => undefined }), keys: async () => [], delete: async () => true, match: async () => undefined },
    clients: self.clients,
    location: { origin: 'https://netvork.app' },
    fetch: async () => ({ ok: true }),
    navigator: { serviceWorker: {} },
  }

  /*
   * Run the worker with its globals passed in as arguments.
   *
   * A worker script expects self, caches and clients to exist; handing them
   * over as parameters is enough, and avoids both a Node vm and a browser.
   */
  const run = new Function('self', 'caches', 'clients', 'location', 'fetch', 'navigator', swSource)
  run(context.self, context.caches, context.clients, context.location, context.fetch, context.navigator)

  return { handlers, shown, registration }
}

/** Fire a real push event at the worker and wait for whatever it promised. */
async function push(worker: ReturnType<typeof loadWorker>, payload: Record<string, unknown>) {
  const waits: Promise<unknown>[] = []
  worker.handlers.get('push')?.({
    data: { json: () => payload, text: () => JSON.stringify(payload) },
    waitUntil: (p: Promise<unknown>) => waits.push(p),
  })
  await Promise.all(waits)
}

describe('an incoming call', () => {
  let worker: ReturnType<typeof loadWorker>

  beforeEach(() => { worker = loadWorker() })

  it('rings, and stays on screen until something takes it away', async () => {
    await push(worker, {
      kind: 'call', title: 'Alice', body: 'Incoming call', tag: 'call-abc',
      renotify: true, requireInteraction: true, call_uuid: 'abc', url: '/calls?join=abc',
      actions: [{ action: 'answer', title: 'Answer' }, { action: 'decline', title: 'Decline' }],
    })

    expect(worker.shown).toHaveLength(1)
    // The two properties that make it a ring rather than a note — and the two
    // that make an uncancelled one a problem.
    expect(worker.shown[0].options.requireInteraction).toBe(true)
    expect(worker.shown[0].tag).toBe('call-abc')
  })
})

describe('the call ending', () => {
  let worker: ReturnType<typeof loadWorker>

  beforeEach(async () => {
    worker = loadWorker()
    // A ring is already on screen, as it would be.
    await push(worker, {
      kind: 'call', title: 'Alice', body: 'Incoming call', tag: 'call-abc',
      renotify: true, requireInteraction: true, call_uuid: 'abc', url: '/calls?join=abc',
      actions: [{ action: 'answer', title: 'Answer' }, { action: 'decline', title: 'Decline' }],
    })
  })

  it('takes the ring down when the caller gives up', async () => {
    await push(worker, {
      kind: 'call_cancel', reason: 'missed', tag: 'call-abc',
      title: 'Missed call', body: 'Alice called', url: '/calls',
    })

    // The reported bug: this stayed, offering Answer and Decline for a call
    // that was over.
    const ring = worker.shown.find((n) => n.options.requireInteraction === true)
    expect(ring?.closed).toBe(true)
  })

  it('leaves a quiet missed call in its place', async () => {
    await push(worker, {
      kind: 'call_cancel', reason: 'missed', tag: 'call-abc',
      title: 'Missed call', body: 'Alice called', url: '/calls',
    })

    const left = worker.shown.filter((n) => !n.closed)
    expect(left).toHaveLength(1)
    expect(left[0].options.title).toBe('Missed call')
    // Everything the ring was, inverted: news, not a summons. A push is also
    // meant to show something, and this is what satisfies that.
    expect(left[0].options.requireInteraction).toBe(false)
    expect(left[0].options.silent).toBe(true)
  })

  it('leaves nothing behind when it was answered elsewhere', async () => {
    await push(worker, { kind: 'call_cancel', reason: 'handled', tag: 'call-abc' })

    // Picking up on your phone should not leave your laptop claiming you
    // missed the call you are currently on.
    expect(worker.shown.every((n) => n.closed)).toBe(true)
  })

  it('only clears the call it names', async () => {
    await push(worker, {
      kind: 'call', title: 'Bob', body: 'Incoming call', tag: 'call-xyz',
      renotify: true, requireInteraction: true,
    })

    await push(worker, { kind: 'call_cancel', reason: 'handled', tag: 'call-abc' })

    const other = worker.shown.find((n) => n.tag === 'call-xyz')
    expect(other?.closed).toBe(false)
  })

  it('does not draw a second ring for the cancellation', async () => {
    await push(worker, { kind: 'call_cancel', reason: 'handled', tag: 'call-abc' })

    // Falling through to the ordinary path would post a fresh notification
    // beside the one it was sent to silence.
    expect(worker.shown.filter((n) => n.options.requireInteraction === true)).toHaveLength(1)
  })
})
