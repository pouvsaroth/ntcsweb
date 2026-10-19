<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { supportApi, type SupportView } from '../api'
import { useSession } from '../session'
import { stateVariant } from '../support'

/** One support view: who, as whom, why, when, and every page it loaded. */
const props = defineProps<{ viewId: number | null }>()
const emit = defineEmits<{ close: []; ended: [view: SupportView] }>()

const { t } = useI18n()
const session = useSession()

const view = ref<SupportView | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const ending = ref(false)

const canEnd = computed(() => view.value?.state === 'open' && session.can('platform.support.view'))

watch(
  () => props.viewId,
  async (id) => {
    view.value = null
    error.value = null
    if (id === null) return
    loading.value = true
    try {
      view.value = await supportApi.show(id)
    } catch (e) {
      error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

async function end(): Promise<void> {
  if (!view.value) return
  ending.value = true
  error.value = null
  try {
    const ended = await supportApi.end(view.value.id)
    view.value = { ...view.value, ...ended, events: view.value.events }
    emit('ended', ended)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    ending.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="viewId !== null" :title="t('bo.support.detailTitle')" size="lg" @update:model-value="emit('close')">
    <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>

    <div v-if="view" class="space-y-4">
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-xs text-neutral-500">{{ t('bo.support.school') }}</dt>
          <dd class="text-neutral-900">{{ view.school?.name }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('bo.support.state') }}</dt>
          <dd><BaseBadge :variant="stateVariant[view.state]">{{ t(`bo.support.states.${view.state}`) }}</BaseBadge></dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('bo.support.staff') }}</dt>
          <dd class="text-neutral-900">{{ view.staff?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('bo.support.viewedAs') }}</dt>
          <dd class="break-words text-neutral-900">{{ view.user?.name }} <span v-if="view.user?.email" class="text-neutral-500">· {{ view.user.email }}</span></dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-xs text-neutral-500">{{ t('bo.support.reason') }}</dt>
          <dd class="break-words text-neutral-900">{{ view.reason }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('bo.support.started') }}</dt>
          <dd class="text-neutral-900">{{ formatDateTime(view.started_at) }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ view.state === 'open' ? t('bo.support.endsAt') : t('bo.support.ended') }}</dt>
          <dd class="text-neutral-900">
            {{ formatDateTime(view.state === 'open' ? view.expires_at : view.ended_at) }}
            <span v-if="view.ended_by" class="text-neutral-500">
              · {{ t(`bo.support.endedBy.${view.ended_by}`, { name: view.ended_by_staff ?? '' }) }}
            </span>
          </dd>
        </div>
      </dl>

      <p v-if="!view.entered" class="text-sm text-neutral-500">{{ t('bo.support.notEntered') }}</p>

      <div>
        <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('bo.support.pages', { n: view.events?.length ?? 0 }) }}</h3>
        <p v-if="!view.events?.length" class="text-sm text-neutral-500">{{ t('bo.support.noPages') }}</p>
        <ul v-else class="max-h-80 divide-y divide-neutral-100 overflow-y-auto rounded border border-neutral-200">
          <li v-for="(event, i) in view.events" :key="i" class="flex items-baseline gap-2 px-3 py-1.5 text-xs">
            <span class="w-14 shrink-0 font-mono font-semibold" :class="event.method === 'GET' ? 'text-neutral-600' : 'text-red-600'">{{ event.method }}</span>
            <span class="min-w-0 flex-1 break-all font-mono text-neutral-800">{{ event.path }}</span>
            <span class="shrink-0 font-mono" :class="(event.status ?? 0) >= 400 ? 'text-red-600' : 'text-neutral-500'">{{ event.status ?? '' }}</span>
            <span class="hidden shrink-0 text-neutral-400 sm:inline">{{ formatDateTime(event.at) }}</span>
          </li>
        </ul>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('close')">{{ t('bo.common.close') }}</BaseButton>
      <BaseButton v-if="canEnd" variant="danger" :loading="ending" @click="end">{{ t('bo.support.end') }}</BaseButton>
    </template>
  </BaseModal>
</template>
