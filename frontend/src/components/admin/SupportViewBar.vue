<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAuthStore } from '@/stores/auth'

/**
 * The red bar on every page while NewTech staff look at the school as this
 * user (back office stage 6b-2): who, read-only, when it ends, and End. The
 * server enforces all of it; this only says so, and leaves when time is up.
 */
const { t } = useI18n()
const auth = useAuthStore()

const view = computed(() => auth.supportView)
const ending = ref(false)

const endsAt = computed(() =>
  view.value ? new Date(view.value.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '',
)

let timer: ReturnType<typeof setTimeout> | undefined
watch(
  view,
  (v) => {
    clearTimeout(timer)
    if (!v) return
    const left = new Date(v.expires_at).getTime() - Date.now()
    // setTimeout caps at ~24 days; a view lasts at most 4 hours.
    timer = setTimeout(() => window.location.assign('/support-view?ended=1'), Math.max(0, left) + 1000)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearTimeout(timer))

async function end(): Promise<void> {
  ending.value = true
  try {
    await auth.logout()
  } finally {
    window.location.assign('/support-view?ended=1')
  }
}
</script>

<template>
  <div
    v-if="view"
    role="status"
    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 bg-red-600 px-4 py-2 text-sm font-medium text-white sm:px-6"
  >
    <span class="min-w-0">{{ t('supportView.bar', { staff: view.staff ?? 'NewTech', time: endsAt }) }}</span>
    <button
      type="button"
      class="shrink-0 rounded border border-white/70 px-3 py-0.5 text-white hover:bg-white/10 disabled:opacity-60"
      :disabled="ending"
      @click="end"
    >
      {{ t('supportView.end') }}
    </button>
  </div>
</template>
