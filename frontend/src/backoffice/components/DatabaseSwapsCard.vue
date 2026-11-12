<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import { formatDateTime } from '@/utils/date'

import { dataApi, type DatabaseSwapRow } from '../api'

/** The latest "Use for a school" runs, with their steps; looks again while one is going. */
const emit = defineEmits<{ finished: [] }>()
const { t } = useI18n()

const swaps = ref<DatabaseSwapRow[]>([])
const statusVariant = { queued: 'neutral', running: 'warning', done: 'success', failed: 'danger' } as const
const stepVariant = { ok: 'success', failed: 'danger' } as const

let timer: ReturnType<typeof setTimeout> | undefined

async function load(): Promise<void> {
  clearTimeout(timer)
  const wasBusy = swaps.value.some((s) => s.status === 'queued' || s.status === 'running')
  try {
    swaps.value = await dataApi.swaps()
  } catch {
    // The list of databases above shows any error.
  }
  const busy = swaps.value.some((s) => s.status === 'queued' || s.status === 'running')
  if (wasBusy && !busy) emit('finished')
  if (busy) timer = setTimeout(() => void load(), 3000)
}

onMounted(() => void load())
onBeforeUnmount(() => clearTimeout(timer))
defineExpose({ load })

const seconds = (ms: number) => (ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`)
</script>

<template>
  <section v-if="swaps.length" class="mt-6">
    <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('bo.data.swap.history') }}</h3>
    <div class="space-y-2">
      <div v-for="swap in swaps" :key="swap.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm font-medium text-neutral-900">{{ swap.school?.name ?? '—' }}</p>
            <p class="text-xs break-all text-neutral-600">
              <span class="font-mono">{{ swap.restored_database }}</span> → <span class="font-mono">{{ swap.live_database }}</span>
              · {{ t('bo.data.swap.keptAs') }} <span class="font-mono">{{ swap.kept_as }}</span>
            </p>
            <p class="text-xs text-neutral-500">
              {{ swap.created_at ? formatDateTime(swap.created_at) : '' }}{{ swap.staff ? ` · ${swap.staff}` : '' }}{{ swap.backup_id ? ` · ${t('bo.data.swap.backupId', { id: swap.backup_id })}` : '' }}
            </p>
          </div>
          <BaseBadge :variant="statusVariant[swap.status]">{{ t(`bo.data.swap.status.${swap.status}`) }}</BaseBadge>
        </div>
        <div v-if="swap.steps.length" class="mt-2 flex flex-wrap gap-1">
          <BaseBadge v-for="(step, i) in swap.steps" :key="i" :variant="stepVariant[step.status]" :title="step.message ?? ''">
            {{ t(`bo.data.swap.stepNames.${step.step}`) }} · {{ seconds(step.ms) }}
          </BaseBadge>
        </div>
        <p v-if="swap.error" class="mt-2 font-mono text-xs break-all text-red-700">{{ swap.error }}</p>
      </div>
    </div>
  </section>
</template>
