<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { dataApi, formatBytes, type PlatformHealth } from '../../api'

/** Queue, scheduler and disk at a glance. Re-read every 30 s while open. */
const { t } = useI18n()

const health = ref<PlatformHealth | null>(null)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    health.value = await dataApi.health()
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

const timer = setInterval(() => void load(), 30_000)
onBeforeUnmount(() => clearInterval(timer))
onMounted(load)
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="!health && !error" class="flex justify-center py-16"><BaseSpinner /></div>

    <div v-if="health" class="grid gap-4 lg:grid-cols-3">
      <section class="rounded-[--radius-card] border bg-white p-5 shadow-[--shadow-card]" :class="health.scheduler.healthy ? 'border-neutral-200' : 'border-red-300'">
        <h2 class="mb-2 font-semibold text-neutral-900">{{ t('bo.data.scheduler') }}</h2>
        <p class="flex items-start gap-2 text-sm" :class="health.scheduler.healthy ? 'text-green-700' : 'text-red-700'">
          <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="health.scheduler.healthy ? 'bg-green-500' : 'bg-red-500'" />
          <span>
            <template v-if="!health.scheduler.last_tick_at">{{ t('bo.data.schedulerNever') }}</template>
            <template v-else-if="health.scheduler.healthy">{{ t('bo.data.schedulerOk', { when: formatDateTime(health.scheduler.last_tick_at) }) }}</template>
            <template v-else>{{ t('bo.data.schedulerDown', { when: formatDateTime(health.scheduler.last_tick_at) }) }}</template>
          </span>
        </p>
      </section>

      <section class="rounded-[--radius-card] border bg-white p-5 shadow-[--shadow-card]" :class="health.queue.failed ? 'border-amber-300' : 'border-neutral-200'">
        <h2 class="mb-2 font-semibold text-neutral-900">{{ t('bo.data.queue') }}</h2>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <dt class="text-neutral-500">{{ t('bo.data.waiting') }}</dt>
          <dd class="text-right tabular-nums">{{ health.queue.waiting ?? '—' }}</dd>
          <dt class="text-neutral-500">{{ t('bo.data.failedJobs') }}</dt>
          <dd class="text-right tabular-nums" :class="health.queue.failed ? 'font-semibold text-amber-700' : ''">{{ health.queue.failed }}</dd>
        </dl>
      </section>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="mb-2 font-semibold text-neutral-900">{{ t('bo.data.disk') }}</h2>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <dt class="text-neutral-500">{{ t('bo.data.freeSpace') }}</dt>
          <dd class="text-right tabular-nums">{{ formatBytes(health.disk.free_bytes) }}</dd>
          <dt class="text-neutral-500">{{ t('bo.data.backupsUse') }}</dt>
          <dd class="text-right tabular-nums">{{ formatBytes(health.disk.backups_bytes) }}</dd>
        </dl>
        <p class="mt-3 text-xs text-neutral-500">
          {{ health.disk.copy_disk ? t('bo.data.secondCopy', { disk: health.disk.copy_disk }) : t('bo.data.secondCopyOff') }}
        </p>
      </section>

      <section v-if="health.queue.latest_failures.length" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card] lg:col-span-3">
        <h2 class="mb-3 font-semibold text-neutral-900">{{ t('bo.data.latestFailures') }}</h2>
        <ul class="space-y-2">
          <li v-for="f in health.queue.latest_failures" :key="f.id" class="text-sm">
            <p class="font-mono text-xs break-all text-red-700">{{ f.message }}</p>
            <p class="text-xs text-neutral-400">{{ formatDateTime(f.failed_at) }} · {{ f.queue }}</p>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
