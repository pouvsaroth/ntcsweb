import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

/**
 * Moves an open tab onto a newer deployed build without disturbing the
 * person using it.
 *
 * - A newer build is noticed by polling `/version.json` (written by the
 *   writeVersionFile() plugin in the vite configs; build-only, so in local
 *   dev the first fetch 404s and this stays idle).
 * - It never reloads the page someone is on, so a half-filled form is never
 *   lost. The switch happens on their next page change instead: that
 *   navigation is done as a full page load, which picks up the new build.
 * - If a page's code file can't be loaded (an old tab asking for a file a
 *   deploy removed), the same full page load to that page recovers it,
 *   at most once per 10 seconds so a real outage can't cause a reload loop.
 *
 * The deploy keeps the previous build's /assets files on the server (see
 * .github/workflows/deploy.yml), so an old tab keeps working until then.
 */
export function useAutoReloadOnNewVersion(): void {
  const CHECK_INTERVAL_MS = 5 * 60 * 1000
  const RELOAD_GUARD_KEY = 'app-reload-for-missing-file'
  const router = useRouter()

  let knownVersion: string | null = null
  let newVersionReady = false
  let intervalId: ReturnType<typeof setInterval> | undefined

  async function fetchVersion(): Promise<string | null> {
    try {
      const response = await fetch(`/version.json?_=${Date.now()}`, { cache: 'no-store' })
      if (!response.ok) return null
      const body: unknown = await response.json()
      const version = (body as { version?: unknown } | null)?.version
      return typeof version === 'string' ? version : null
    } catch {
      return null
    }
  }

  async function checkForNewVersion(): Promise<void> {
    if (!knownVersion || newVersionReady || document.visibilityState !== 'visible') return

    const latest = await fetchVersion()
    if (latest && latest !== knownVersion) newVersionReady = true
  }

  function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') void checkForNewVersion()
  }

  /** Full page load of `path`, unless one was just done for a missing file. */
  function hardNavigate(path: string, guard: boolean): boolean {
    if (guard) {
      try {
        const last = Number(sessionStorage.getItem(RELOAD_GUARD_KEY) ?? 0)
        if (Date.now() - last < 10_000) return false
        sessionStorage.setItem(RELOAD_GUARD_KEY, String(Date.now()))
      } catch {
        // No sessionStorage (private mode etc.) — still try once.
      }
    }
    window.location.assign(router.resolve(path).href)
    return true
  }

  const removeGuard = router.beforeEach((to, from) => {
    // `from.matched.length === 0` is the app's first navigation — nothing to swap yet.
    if (newVersionReady && from.matched.length > 0 && to.fullPath !== from.fullPath) {
      hardNavigate(to.fullPath, false)
      return false
    }
    return true
  })

  const removeErrorHandler = router.onError((error, to) => {
    const message = error instanceof Error ? error.message : String(error)
    if (/dynamically imported module|Importing a module script failed|error loading dynamically imported/i.test(message)) {
      hardNavigate(to.fullPath, true)
    }
  })

  onMounted(async () => {
    knownVersion = await fetchVersion()
    if (!knownVersion) return // local dev, or the file is briefly missing mid-deploy — nothing to compare against yet

    intervalId = setInterval(() => void checkForNewVersion(), CHECK_INTERVAL_MS)
    document.addEventListener('visibilitychange', onVisibilityChange)
  })

  onUnmounted(() => {
    clearInterval(intervalId)
    document.removeEventListener('visibilitychange', onVisibilityChange)
    removeGuard()
    removeErrorHandler()
  })
}
