<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { notificationsService, type AppNotification } from '@/services/notifications'

const { t } = useI18n()
const router = useRouter()

const open = ref(false)
const items = ref<AppNotification[]>([])
const unreadCount = ref(0)
const loading = ref(false)

/** The dropdown's own small preview — the full list lives at /admin/notifications (see "View all" below). */
async function load() {
  loading.value = true
  try {
    const result = await notificationsService.list({ page: 1, per_page: 8 })
    items.value = result.data
    unreadCount.value = result.unreadCount
  } finally {
    loading.value = false
  }
}

function typeLabel(notification: AppNotification): string {
  return t(`notifications.types.${notification.type}`, notification.data)
}

function formatWhen(value: string): string {
  return new Date(value).toLocaleString()
}

async function toggle() {
  open.value = !open.value
  if (open.value) await load()
}

async function select(notification: AppNotification) {
  open.value = false

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

// A lightweight poll for the badge count — no websocket infra in this app
// (see NotificationService's own docblock), so this is the simplest way an
// admin who leaves the tab open still notices a new one arriving.
let pollHandle: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  void load()
  pollHandle = setInterval(() => void load(), 60_000)
})

onBeforeUnmount(() => {
  if (pollHandle) clearInterval(pollHandle)
})
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="relative rounded-lg p-2 text-neutral-600 hover:bg-neutral-100"
      :aria-label="t('notifications.bell')"
      :aria-expanded="open"
      @click="toggle"
    >
      <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
        />
      </svg>
      <span
        v-if="unreadCount > 0"
        class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger-600 px-1 text-[10px] font-semibold leading-none text-white"
      >
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
    >
      <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 rounded-lg border border-neutral-200 bg-white shadow-lg">
        <div class="flex items-center justify-between border-b border-neutral-100 px-3 py-2">
          <span class="text-sm font-semibold text-neutral-800">{{ t('notifications.bell') }}</span>
          <button
            v-if="unreadCount > 0"
            type="button"
            class="text-xs font-medium text-secondary-600 hover:text-secondary-700"
            @click="markAllRead"
          >
            {{ t('notifications.markAllRead') }}
          </button>
        </div>

        <div class="max-h-96 overflow-y-auto">
          <p v-if="!loading && items.length === 0" class="px-3 py-6 text-center text-sm text-neutral-400">
            {{ t('notifications.empty') }}
          </p>

          <button
            v-for="notification in items"
            :key="notification.id"
            type="button"
            class="flex w-full flex-col items-start gap-0.5 border-b border-neutral-50 px-3 py-2.5 text-left hover:bg-neutral-50"
            :class="{ 'bg-primary-50/40': notification.read_at === null }"
            @click="select(notification)"
          >
            <span class="flex w-full items-center gap-1.5 text-sm text-neutral-800">
              <span v-if="notification.read_at === null" class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary-600" aria-hidden="true" />
              <span class="min-w-0 flex-1">{{ typeLabel(notification) }}</span>
            </span>
            <span class="text-xs text-neutral-400">{{ formatWhen(notification.created_at) }}</span>
          </button>
        </div>

        <RouterLink
          to="/admin/notifications"
          class="block border-t border-neutral-100 px-3 py-2 text-center text-sm font-medium text-secondary-600 hover:bg-neutral-50 hover:text-secondary-700"
          @click="open = false"
        >
          {{ t('notifications.viewAll') }}
        </RouterLink>
      </div>
    </Transition>
  </div>
</template>
