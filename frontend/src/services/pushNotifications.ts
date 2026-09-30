import { apiDelete, apiGet, apiPost } from '@/services/http'

/**
 * Phone notifications (Web Push) for this browser/device — the backend
 * pushes every in-app notification to each device a user enabled here (see
 * SendPushNotificationJob and public/sw.js, which displays them).
 *
 * iPhone/iPad only allow this from the app added to the Home Screen (iOS
 * 16.4+) — in a Safari tab PushManager simply doesn't exist, which is what
 * `needs-install` reports so the UI can say so instead of just "unsupported".
 */
export type PushState = 'unsupported' | 'needs-install' | 'unconfigured' | 'denied' | 'off' | 'on'

function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
}

function isSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** main-admin.ts only registers sw.js in production builds — this makes sure one exists either way. */
async function registration(): Promise<ServiceWorkerRegistration> {
  const existing = await navigator.serviceWorker.getRegistration('/')
  if (!existing) await navigator.serviceWorker.register('/sw.js')
  return navigator.serviceWorker.ready
}

async function currentSubscription(): Promise<PushSubscription | null> {
  if (!isSupported()) return null
  const existing = await navigator.serviceWorker.getRegistration('/')
  return existing ? existing.pushManager.getSubscription() : null
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

async function saveToServer(subscription: PushSubscription): Promise<void> {
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings
  await apiPost('/my-push-subscriptions', {
    ...subscription.toJSON(),
    content_encoding: encodings?.includes('aes128gcm') ? 'aes128gcm' : (encodings?.[0] ?? 'aes128gcm'),
  })
}

export async function pushState(): Promise<PushState> {
  if (!isSupported()) return isIos() && !isStandalone() ? 'needs-install' : 'unsupported'
  if (Notification.permission === 'denied') return 'denied'

  const { public_key: publicKey } = await apiGet<{ public_key: string | null }>('/my-push-subscriptions/config')
  if (!publicKey) return 'unconfigured'

  return Notification.permission === 'granted' && (await currentSubscription()) ? 'on' : 'off'
}

/**
 * Must be called straight from a tap — iOS only shows the permission prompt
 * for a request made directly inside the user's gesture, so permission is
 * asked for first, before any network call.
 */
export async function enablePush(): Promise<PushState> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off'

  const { public_key: publicKey } = await apiGet<{ public_key: string | null }>('/my-push-subscriptions/config')
  if (!publicKey) return 'unconfigured'

  const reg = await registration()
  const subscription =
    (await reg.pushManager.getSubscription()) ??
    (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) }))

  await saveToServer(subscription)
  return 'on'
}

export async function disablePush(): Promise<PushState> {
  const subscription = await currentSubscription()
  if (subscription) {
    await apiDelete('/my-push-subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => {})
    await subscription.unsubscribe()
  }
  return 'off'
}

/**
 * Re-links this device to whoever is signed in now — a device enabled by a
 * previous user of a shared phone is taken over rather than still
 * delivering their notifications. Called on every app load.
 */
export async function syncPush(): Promise<void> {
  if (!isSupported() || Notification.permission !== 'granted') return
  const subscription = await currentSubscription()
  if (subscription) await saveToServer(subscription)
}

/**
 * On sign-out: stop this device receiving the signed-out user's
 * notifications. The browser keeps its subscription, so the next person to
 * sign in here gets it re-linked by syncPush() without being asked again.
 */
export async function detachPushOnLogout(): Promise<void> {
  const subscription = await currentSubscription().catch(() => null)
  if (subscription) await apiDelete('/my-push-subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => {})
}
