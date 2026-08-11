/* My PA service worker: cache-first for built static assets, network-first for
   everything else. API requests are never cached.

   Bumped to v3 to throw away what v2 collected. Until the server learned to
   404 a missing build asset, a request for a deleted chunk came back as
   index.html with a 200 — and this worker stored that HTML under the .js URL,
   permanently, because hashed filenames are treated as immutable. Anyone who
   hit that once kept hitting it, cached, with no way to clear it themselves.
   Renaming the cache is what frees them: activate deletes every cache that is
   not this one. */
const CACHE = 'mypa-static-v3'

self.addEventListener('install', (event) => {
  self.skipWaiting()
  event.waitUntil(caches.open(CACHE))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))),
    ).then(() => self.clients.claim()),
  )
})

/**
 * Is this response worth keeping forever?
 *
 * Two ways it is not: it failed, or it is the wrong kind of thing. The second
 * is the one that bit us — a request for a chunk that no longer exists used to
 * return the SPA shell, and HTML cached under a .js URL breaks that page for
 * good on that device.
 */
function storable(url, response) {
  if (!response || !response.ok || response.type === 'opaque') return false

  const type = response.headers.get('content-type') || ''
  if (/\.js$/.test(url.pathname)) return /javascript|ecmascript/i.test(type)
  if (/\.css$/.test(url.pathname)) return /text\/css/i.test(type)

  return !/text\/html/i.test(type)
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)

  // Never touch API, websocket upgrades, or cross-origin requests.
  if (event.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api')) {
    return
  }

  // Hashed build assets: cache-first (immutable filenames).
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.match(event.request).then(
        (cached) =>
          cached ??
          fetch(event.request).then((response) => {
            // Only ever store a real answer. Storing an error — or the SPA
            // fallback page arriving where a script was asked for — under an
            // immutable URL makes one bad moment permanent.
            if (storable(url, response)) {
              const copy = response.clone()
              caches.open(CACHE).then((cache) => cache.put(event.request, copy))
            }
            return response
          }),
      ),
    )
    return
  }

  // App shell / navigation: network-first with cache fallback for offline.
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Only a working page is worth keeping as the offline shell — an
        // error page cached here is what everyone would see next outage.
        if (event.request.mode === 'navigate' && response.ok) {
          const copy = response.clone()
          caches.open(CACHE).then((cache) => cache.put('/', copy))
        }
        return response
      })
      .catch(() => caches.match(event.request.mode === 'navigate' ? '/' : event.request)),
  )
})

/* ---- Web push: system notifications with sound, even when the tab is closed. */
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'My PA', body: event.data ? event.data.text() : '' }
  }
  /*
   * A call is not a message.
   *
   * It stays on screen until it is dealt with, re-alerts rather than being
   * swapped in silently, carries Answer and Decline, and vibrates in the
   * long-short pattern of a ringing phone. This is as close to a ringtone as
   * a web app is allowed: no page is running, so nothing can play audio on a
   * loop — the device's own notification sound is what rings.
   */
  const call = data.kind === 'call'

  event.waitUntil(
    self.registration.showNotification(data.title || 'My PA', {
      body: data.body || '',
      tag: data.tag || undefined,
      icon: '/icons/icon.svg',
      badge: '/icons/icon.svg',
      data: { url: data.url || '/', kind: data.kind, callUuid: data.call_uuid },
      renotify: data.renotify === true,
      requireInteraction: data.requireInteraction === true,
      actions: Array.isArray(data.actions) ? data.actions : undefined,
      // Sound is the device's own; vibration is ours to choose.
      vibrate: call ? [400, 200, 400, 200, 400] : [200, 100, 200],
    }),
  )
})

/*
 * The browser moved this device's push subscription.
 *
 * Chrome rotates a subscription on its own — after a browser update, under
 * storage pressure, or simply with age. The old endpoint stops working and no
 * new one is registered, so the phone quietly stops receiving anything and the
 * server keeps posting to an address nobody reads. Nothing on the device says
 * so; the person just notices they stopped being told about calls while
 * everyone else still was.
 *
 * Re-subscribing here and telling the server is what keeps them.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const previous = event.oldSubscription
    // The key the old subscription was made with. Without it there is nothing
    // to re-subscribe against — the next visit to the app re-registers, so
    // giving up here loses a window rather than the subscription.
    const key = previous?.options?.applicationServerKey
    if (!key) return

    try {
      const fresh = event.newSubscription
        ?? (await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key,
        }))
      const json = fresh.toJSON()

      // Identified by the endpoint it replaces: a service worker holds no
      // session, and knowing the old endpoint is what proves this is the same
      // device rather than somebody claiming to be.
      await fetch('/api/v1/push/rotate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          old_endpoint: previous.endpoint,
          endpoint: json.endpoint,
          keys: json.keys,
        }),
      })
    } catch (err) {
      console.warn('[sw] could not move the push subscription', err)
    }
  })())
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const info = event.notification.data || {}

  // Declining must not open the app — the whole point is to silence it from
  // the lock screen. The call reaper ends it as a missed call either way.
  if (event.action === 'decline') {
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
        for (const win of wins) win.postMessage({ type: 'call-decline', uuid: info.callUuid })
      }),
    )
    return
  }

  const url = info.url || '/'
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const win of wins) {
        if ('focus' in win) {
          win.focus()
          if ('navigate' in win) win.navigate(url)
          return
        }
      }
      return clients.openWindow(url)
    }),
  )
})
