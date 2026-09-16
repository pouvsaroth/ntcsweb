<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { notificationsService, type AppNotification } from '@/services/notifications'
import { formatDateTime } from '@/utils/date'

const { t } = useI18n()
const router = useRouter()

const unreadCount = ref(0)

const { items, meta, loading, setPage, fetch } = usePaginatedResource<AppNotification>(async (query) => {
  const result = await notificationsService.list(query)
  unreadCount.value = result.unreadCount
  return result
})

function typeLabel(notification: AppNotification): string {
  return t(`notifications.types.${notification.type}`, notification.data)
}

function formatWhen(value: string): string {
  return formatDateTime(value)
}

async function select(notification: AppNotification) {
  if (notification.read_at === null) {
    notification.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
    notificationsService.markRead(notification.id).catch(() => {})
  }

  if (notification.link) await router.push(notification.link)
}

async function markAllRead() {
  items.value.forEach((n) => (n.read_at = n.read_at ?? new Date().toISOString()))
  unreadCount.value = 0
  await notificationsService.markAllRead().catch(() => {})
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('notifications.bell') }}</h1>
      <BaseButton v-if="unreadCount > 0" variant="outline" size="sm" @click="markAllRead">
        {{ t('notifications.markAllRead') }}
      </BaseButton>
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
