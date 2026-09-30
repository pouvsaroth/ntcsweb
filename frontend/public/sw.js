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

// Phone notifications (Web Push) — the backend's SendPushNotificationJob
// sends { title, body, url, tag } for every in-app notification to each
// device the user turned this on for (see services/pushNotifications.ts).
self.addEventListener('push', (event) => {
  let payload = {}
  try {
    payload = event.data ? event.data.json() : {}
  } catch {
    payload = { body: event.data ? event.data.text() : '' }
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'NTCSWEB', {
      body: payload.body || '',
      tag: payload.tag,
      icon: '/icons/admin-192.png',
      badge: '/icons/admin-192.png',
      data: { url: payload.url || '/admin/notifications' },
    }),
  )
})

// Tap → reuse the already-open app window if there is one (same "one
// reusable window" goal as the manifest's launch_handler), else open one.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/admin/notifications', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const existing = windows.find((client) => new URL(client.url).origin === self.location.origin)
      if (existing) {
        return existing.focus().then((client) => (client && 'navigate' in client ? client.navigate(url) : undefined))
      }
      return self.clients.openWindow(url)
    }),
  )
})
