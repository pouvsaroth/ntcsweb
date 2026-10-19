<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { supportApi, type School, type SupportView } from '../api'
import { useSession } from '../session'
import { stateVariant, supportableStatuses } from '../support'
import OpenSupportViewModal from './OpenSupportViewModal.vue'
import SupportViewDetail from './SupportViewDetail.vue'

/** A school's support views (stage 6b): open one, and the latest few. */
const props = defineProps<{ school: School }>()

const { t } = useI18n()
const session = useSession()

const canOpen = computed(() => session.can('platform.support.view') && supportableStatuses.includes(props.school.status as string))

const views = ref<SupportView[]>([])
const total = ref(0)
const error = ref<string | null>(null)
const opening = ref(false)
const shownId = ref<number | null>(null)

async function load(): Promise<void> {
  try {
    const result = await supportApi.list({ page: 1, per_page: 5, filter: { tenant_id: String(props.school.id) } })
    views.value = result.data
    total.value = result.pagination?.type === 'length_aware' ? result.pagination.total : result.data.length
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-3 flex items-start justify-between gap-2">
      <div>
        <h2 class="font-semibold text-neutral-900">{{ t('bo.support.panelTitle') }}</h2>
        <p class="text-xs text-neutral-500">{{ t('bo.support.panelHint') }}</p>
      </div>
      <BaseButton v-if="canOpen" size="sm" class="shrink-0 whitespace-nowrap" @click="opening = true">{{ t('bo.support.openShort') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>

    <p v-if="!error && views.length === 0" class="text-sm text-neutral-500">{{ t('bo.support.emptySchool') }}</p>
    <ul v-else class="divide-y divide-neutral-100">
      <li v-for="view in views" :key="view.id">
        <button type="button" class="w-full py-2.5 text-left hover:bg-neutral-50" @click="shownId = view.id">
          <div class="flex items-center justify-between gap-2">
            <p class="truncate text-sm text-neutral-900">{{ view.user?.name }}</p>
            <BaseBadge :variant="stateVariant[view.state]">{{ t(`bo.support.states.${view.state}`) }}</BaseBadge>
          </div>
          <p class="truncate text-xs text-neutral-500">{{ view.reason }}</p>
          <p class="text-xs text-neutral-400">{{ view.staff?.name }} · {{ formatDateTime(view.started_at) }} · {{ t('bo.support.pagesShort', { n: view.events_count }) }}</p>
        </button>
      </li>
    </ul>

    <RouterLink
      v-if="total > views.length && session.can('platform.support.read')"
      :to="{ path: '/support/views', query: { school: String(school.id) } }"
      class="mt-2 inline-block text-sm text-secondary-700 hover:underline"
    >
      {{ t('bo.support.seeAll', { n: total }) }}
    </RouterLink>

    <OpenSupportViewModal v-model="opening" :school="school" @opened="load" />
    <SupportViewDetail :view-id="shownId" @close="shownId = null" @ended="load" />
  </section>
</template>
