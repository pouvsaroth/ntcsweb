<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BroadcastFormModal from '@/components/admin/BroadcastFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { marketingBroadcastsService, type BroadcastStatus, type MarketingBroadcast } from '@/services/marketingBroadcasts'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

// Marketing > Broadcast: promotions sent to Telegram groups, newest first, with how each group went.
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canSend = computed(() => auth.can('broadcasts.create'))
const canDelete = computed(() => auth.can('broadcasts.delete'))

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<MarketingBroadcast>((query) => marketingBroadcastsService.list(query))

const statusVariant = (s: BroadcastStatus) => (s === 'sent' ? 'success' : s === 'partial' ? 'warning' : 'danger')
const time = (iso: string) => {
  const d = new Date(iso)
  return `${formatDate(iso.slice(0, 10))} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

const open = ref<number | null>(null)
const formOpen = ref(false)
const copyFrom = ref<MarketingBroadcast | null>(null)
function newBroadcast(from: MarketingBroadcast | null = null) {
  copyFrom.value = from
  formOpen.value = true
}

const actionError = ref<string | null>(null)
async function remove(row: MarketingBroadcast) {
  if (!(await confirmDialog.confirm({ message: t('marketing.broadcasts.deleteConfirm', { title: row.title }), danger: true }))) return
  actionError.value = null
  try {
    await marketingBroadcastsService.remove(row.id)
    fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('marketing.broadcasts.deleteFailed')
  }
}

function onSent(row: MarketingBroadcast) {
  fetch()
  open.value = row.id
}

onMounted(fetch)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('marketing.broadcasts.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('marketing.broadcasts.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('marketing.broadcasts.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canSend" class="ml-auto shrink-0" @click="newBroadcast()">{{ t('marketing.broadcasts.new') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <div v-if="loading && items.length === 0" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('marketing.broadcasts.empty') }}
    </p>

    <!-- One card per broadcast (phone and desktop); a click shows the message and each group's result. -->
    <div v-else class="space-y-2">
      <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]">
        <button type="button" class="flex w-full items-start justify-between gap-3 p-3 text-left sm:p-4" @click="open = open === row.id ? null : row.id">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ row.title }}</p>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ time(row.sent_at) }}<template v-if="row.created_by_name"> · {{ row.created_by_name }}</template>
              · {{ t('marketing.broadcasts.groupsCount', { n: row.results.length }) }}<template v-if="row.campaign"> · {{ row.campaign.name }}</template>
            </p>
          </div>
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`marketing.broadcasts.statuses.${row.status}`) }}</BaseBadge>
        </button>
        <div v-if="open === row.id" class="border-t border-neutral-100 p-3 sm:p-4">
          <p class="whitespace-pre-wrap rounded-lg bg-neutral-50 p-3 text-sm text-neutral-800">{{ row.message }}</p>
          <ul class="mt-3 space-y-1 text-sm">
            <li v-for="r in row.results" :key="r.group_id" class="flex flex-wrap items-center gap-2">
              <BaseBadge :variant="r.ok ? 'success' : 'danger'">{{ r.ok ? t('marketing.broadcasts.delivered') : t('marketing.broadcasts.notDelivered') }}</BaseBadge>
              <span class="text-neutral-900">{{ r.group_name }}</span>
              <span v-if="r.message" class="text-xs text-danger-600">{{ r.message }}</span>
            </li>
          </ul>
          <div v-if="canSend || canDelete" class="mt-3 flex justify-end gap-3">
            <button v-if="canSend" type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="newBroadcast(row)">{{ t('marketing.broadcasts.sendAgain') }}</button>
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('frontOffice.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BroadcastFormModal v-model="formOpen" :copy-from="copyFrom" @sent="onSent" />
  </div>
</template>
