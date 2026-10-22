<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { authService } from '@/services/auth'
import { ApiRequestError } from '@/types/api'

/**
 * Where "Try the demo" on the website lands (docs/backoffice/stage-12-demo.md):
 * swap the one-time ticket for a session as the visitor's demo account,
 * then a full reload into the app so everything starts as that user. The
 * router sends each role to its own home from /admin.
 */
const { t } = useI18n()
const route = useRoute()

const error = ref<string | null>(null)

onMounted(async () => {
  const ticket = typeof route.query.ticket === 'string' ? route.query.ticket : ''
  // Out of the address bar and history: it works once, but still.
  window.history.replaceState(null, '', '/demo')
  try {
    await authService.enterDemo(ticket)
    window.location.replace('/admin')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('ticket') ?? e.message) : t('demo.enterFailed')
  }
})

function back(): void {
  window.history.length > 1 ? window.history.back() : window.location.replace('/login')
}
</script>

<template>
  <div class="mx-auto max-w-md py-10 text-center">
    <template v-if="error">
      <h1 class="text-lg font-semibold text-neutral-900">{{ t('demo.enterFailed') }}</h1>
      <BaseAlert variant="danger" class="mt-3 text-left">{{ error }}</BaseAlert>
      <button type="button" class="mt-4 text-sm text-secondary-700 hover:underline" @click="back">{{ t('demo.back') }}</button>
    </template>
    <div v-else class="flex flex-col items-center gap-3 text-sm text-neutral-600">
      <BaseSpinner />
      {{ t('demo.entering') }}
    </div>
  </div>
</template>
