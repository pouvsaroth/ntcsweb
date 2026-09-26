// Minimal service worker for the admin app — exists only so browsers treat
// "Add to Home Screen" as installing a real app (one standalone window that
// gets reused) rather than a bookmark that opens a new browser tab on every
// tap. Some Android Chrome versions still require a service worker with a
// fetch handler for that. Deliberately caches nothing: every request still
// goes to the network, so a deploy is never hidden behind a stale copy (see
// docker/nginx/snippets/spa-caching.conf and useAutoReloadOnNewVersion).

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

// Page loads only — API calls, assets, downloads etc. are never touched and
// go straight to the network exactly as without a service worker.
self.addEventListener('fetch', (event) => {
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request))
  }
})
