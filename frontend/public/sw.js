/* My PA service worker: cache-first for built static assets, network-first for
   everything else. API requests are never cached. */
const CACHE = 'mypa-static-v1'

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
            const copy = response.clone()
            caches.open(CACHE).then((cache) => cache.put(event.request, copy))
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
        if (event.request.mode === 'navigate') {
          const copy = response.clone()
          caches.open(CACHE).then((cache) => cache.put('/', copy))
        }
        return response
      })
      .catch(() => caches.match(event.request.mode === 'navigate' ? '/' : event.request)),
  )
})
