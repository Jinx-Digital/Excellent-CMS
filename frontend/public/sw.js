/*
 * Service worker of the admin app (installable as a PWA).
 *
 * - Pages: network first, the cached app shell when offline.
 * - Built files under /_nuxt/ (names contain a hash, never change): cache first. Only those: files of
 *   `nuxt dev` (/_nuxt/components/…, /_nuxt/@fs/…, with ?v=…) keep their names and must not be cached.
 * - API (/api/) and uploaded files (/media/) are never cached - content and logins stay current.
 */
const CACHE = 'excellent-cms-v2'
const SHELL = ['/', '/200.html', '/favicon.svg', '/manifest.webmanifest', '/icon-192.png']
const MAX_ASSETS = 150

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/media/')) {
    return
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => (await caches.match(request)) || (await caches.match('/200.html')) || (await caches.match('/')))
    )
    return
  }

  // A built file: /_nuxt/<hash>.js|css (no folders, no query)
  if (/^\/_nuxt\/[\w-]+\.(js|css|woff2?|svg|png)$/.test(url.pathname) && !url.search) {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone()
          caches.open(CACHE).then(cache => cache.put(request, copy).then(() => trim(cache)))
        }
        return response
      }))
    )
  }
})

// Old builds leave their files behind - keep the newest ones only
async function trim(cache) {
  const keys = (await cache.keys()).filter(key => new URL(key.url).pathname.startsWith('/_nuxt/'))
  await Promise.all(keys.slice(0, Math.max(0, keys.length - MAX_ASSETS)).map(key => cache.delete(key)))
}
