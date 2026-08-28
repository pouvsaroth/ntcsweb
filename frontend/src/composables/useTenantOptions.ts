import { ref } from 'vue'

import { tenantDirectoryService, type TenantOption } from '@/services/tenantDirectory'

// Module-scope, not inside the composable function: Login and ForgotPassword
// both use this on the same page load, and the school list doesn't change
// mid-session, so there is no reason to fetch it twice.
const options = ref<TenantOption[]>([])
const loading = ref(false)
const error = ref(false)
let loadedOnce = false

async function load(): Promise<void> {
  if (loadedOnce || loading.value) return
  loading.value = true
  error.value = false

  try {
    options.value = await tenantDirectoryService.list()
    loadedOnce = true
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

/** Lets a failed fetch be retried (e.g. after a transient network error) without a full page reload. */
function retry(): void {
  loadedOnce = false
  void load()
}

export function useTenantOptions() {
  void load()
  return { options, loading, error, retry }
}
