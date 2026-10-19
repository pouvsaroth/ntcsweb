<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { authService } from '@/services/auth'
import { ApiRequestError } from '@/types/api'

/**
 * Where "Open support view" in the back office lands (stage 6b-2): swap the
 * one-time ticket for a read-only session as the school user, then a full
 * reload into the app so everything starts as that user. Also the page a
 * view that has ended sends the browser to (`?ended=1`).
 */
const { t } = useI18n()
const route = useRoute()

const ended = route.query.ended === '1'
const error = ref<string | null>(null)

onMounted(async () => {
  if (ended) return
  const ticket = typeof route.query.ticket === 'string' ? route.query.ticket : ''
  // Out of the address bar and history: it works once, but still.
  window.history.replaceState(null, '', '/support-view')
  try {
    await authService.enterSupportView(ticket)
    window.location.replace('/admin')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('ticket') ?? e.message) : t('supportView.failedTitle')
  }
})

function closeTab(): void {
  window.close()
}
</script>

<template>
  <div class="mx-auto max-w-md py-10 text-center">
    <template v-if="ended">
      <h1 class="text-lg font-semibold text-neutral-900">{{ t('supportView.endedTitle') }}</h1>
      <p class="mt-2 text-sm text-neutral-600">{{ t('supportView.endedBody') }}</p>
      <button type="button" class="mt-4 text-sm text-secondary-700 hover:underline" @click="closeTab">{{ t('supportView.close') }}</button>
    </template>
    <template v-else-if="error">
      <h1 class="text-lg font-semibold text-neutral-900">{{ t('supportView.failedTitle') }}</h1>
      <BaseAlert variant="danger" class="mt-3 text-left">{{ error }}</BaseAlert>
      <button type="button" class="mt-4 text-sm text-secondary-700 hover:underline" @click="closeTab">{{ t('supportView.close') }}</button>
    </template>
    <div v-else class="flex flex-col items-center gap-3 text-sm text-neutral-600">
      <BaseSpinner />
      {{ t('supportView.entering') }}
    </div>
  </div>
</template>
