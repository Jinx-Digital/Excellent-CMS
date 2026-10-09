// Installable as a PWA: registers the service worker (public/sw.js) in the built app - not in
// `nuxt dev`, where a cached app shell would hide changes. One registered before on the same address
// (e.g. by `nuxt preview` on the dev port) is removed there, with its caches - it would serve old files.
export default defineNuxtPlugin(() => {
  if (!('serviceWorker' in navigator)) return
  if (import.meta.dev) {
    navigator.serviceWorker.getRegistrations().then(async (registrations) => {
      if (!registrations.length) return
      await Promise.all(registrations.map(registration => registration.unregister()))
      if ('caches' in window) await Promise.all((await caches.keys()).map(key => caches.delete(key)))
      // The files of this page came from the old cache
      location.reload()
    }).catch(() => {})
    return
  }
  const register = () => navigator.serviceWorker.register('/sw.js').catch(() => {})
  // The app may start after the page has loaded already
  if (document.readyState === 'complete') register()
  else window.addEventListener('load', register, { once: true })
})
