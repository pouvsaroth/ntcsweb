<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { notificationsService, type AppNotification } from '@/services/notifications'
import { formatDateTime } from '@/utils/date'

const { t } = useI18n()
const router = useRouter()

// Opening this page counts as reading them — see notificationsService.markAllSeen().
const { items, meta, loading, setPage, fetch } = usePaginatedResource<AppNotification>(async (query) => {
  const result = await notificationsService.list(query)
  void notificationsService.markAllSeen()
  return result
})

function typeLabel(notification: AppNotification): string {
  return t(`notifications.types.${notification.type}`, notification.data)
}

function formatWhen(value: string): string {
  return formatDateTime(value)
}

async function select(notification: AppNotification) {
  if (notification.link) await router.push(notification.link)
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('notifications.bell') }}</h1>
    </div>

    <BaseSpinner v-if="loading && items.length === 0" class="mx-auto mt-8" />

    <div v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
      <p v-if="items.length === 0" class="p-6 text-center text-sm text-neutral-400">{{ t('notifications.empty') }}</p>

      <button
        v-for="notification in items"
        :key="notification.id"
        type="button"
        class="flex w-full items-start justify-between gap-3 p-4 text-left hover:bg-neutral-50"
        :class="{ 'bg-primary-50/40': notification.read_at === null }"
        @click="select(notification)"
      >
        <span class="flex min-w-0 items-start gap-2">
          <span v-if="notification.read_at === null" class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary-600" aria-hidden="true" />
          <span class="text-sm text-neutral-800">{{ typeLabel(notification) }}</span>
        </span>
        <span class="shrink-0 text-xs text-neutral-400">{{ formatWhen(notification.created_at) }}</span>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
