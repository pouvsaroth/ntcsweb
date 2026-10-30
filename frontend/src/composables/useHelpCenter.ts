import { ref } from 'vue'

import { helpService, type HelpCenter } from '@/services/help'
import { ApiRequestError } from '@/types/api'

/**
 * Help & Support's data, loaded once and shared by its tabs
 * (docs/backoffice/stage-13-help-support.md).
 */
const data = ref<HelpCenter | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const forbidden = ref(false)

async function load(force = false): Promise<void> {
  if ((data.value && !force) || loading.value) return
  loading.value = true
  error.value = null
  forbidden.value = false
  try {
    data.value = await helpService.get()
  } catch (e) {
    forbidden.value = e instanceof ApiRequestError && e.status === 403
    error.value = e instanceof ApiRequestError ? e.message : 'error'
  } finally {
    loading.value = false
  }
}

export function useHelpCenter() {
  return { data, loading, error, forbidden, load }
}
