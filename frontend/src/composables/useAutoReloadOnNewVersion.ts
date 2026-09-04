import { onMounted, onUnmounted } from 'vue'

/**
 * Detects when a newer build has been deployed while this tab was open, and
 * reloads it automatically — otherwise a visitor keeps running whatever JS
 * happened to be loaded when they arrived, silently stale until they think
 * to hard-refresh (see vite.config.ts's writeVersionFile() plugin, which is
 * what actually produces `/version.json` — build-only, so this composable
 * is a deliberate no-op in local dev: the very first fetch 404s and nothing
 * further happens).
 *
 * Deliberately no confirmation prompt — reloads outright, matching "should
 * hard refresh auto." Checks are throttled to the tab's own visibility: an
 * interval while visible, plus once immediately whenever a backgrounded tab
 * regains focus (the common case — most reloads would otherwise wait for
 * the next interval tick after someone tabs back in).
 */
export function useAutoReloadOnNewVersion(): void {
  const CHECK_INTERVAL_MS = 5 * 60 * 1000

  let knownVersion: string | null = null
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
    if (!knownVersion || document.visibilityState !== 'visible') return

    const latest = await fetchVersion()
    if (latest && latest !== knownVersion) {
      window.location.reload()
    }
  }

  function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') void checkForNewVersion()
  }

  onMounted(async () => {
    knownVersion = await fetchVersion()
    if (!knownVersion) return // local dev, or the file is briefly missing mid-deploy — nothing to compare against yet

    intervalId = setInterval(() => void checkForNewVersion(), CHECK_INTERVAL_MS)
    document.addEventListener('visibilitychange', onVisibilityChange)
  })

  onUnmounted(() => {
    clearInterval(intervalId)
    document.removeEventListener('visibilitychange', onVisibilityChange)
  })
}
