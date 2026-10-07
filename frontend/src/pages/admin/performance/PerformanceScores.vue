<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { performanceCyclesService, performanceReviewsService, scoreLabel, type PerformanceCycle, type PerformanceScores } from '@/services/performance'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Performance Management > Performance score — a cycle's completed
 * reviews ranked by final score (1–5), with each part, how far along the
 * cycle is, its averages and how many staff fall in each band. Cards on a
 * phone, a table from `sm` up.
 */
const { t } = useI18n()

const cycles = ref<PerformanceCycle[]>([])
const cycleId = ref('')
const data = ref<PerformanceScores | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const cycleOptions = computed(() => cycles.value.map((c) => ({ value: String(c.id), label: c.name })))

watch(cycleId, async (id) => {
  data.value = null
  if (!id) return
  loading.value = true
  error.value = null
  try {
    data.value = await performanceReviewsService.scores(Number(id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.performance.loadFailed')
  } finally {
    loading.value = false
  }
})

/** The tallest band is the full bar. */
const bandMax = computed(() => Math.max(1, ...Object.values(data.value?.bands ?? {})))
const bandColor: Record<string, string> = { '5': 'bg-green-500', '4': 'bg-lime-500', '3': 'bg-amber-400', '2': 'bg-orange-500', '1': 'bg-red-500' }
const done = computed(() => (data.value && data.value.counts.total > 0 ? Math.round((data.value.counts.completed / data.value.counts.total) * 100) : 0))
const fixed = (score: number | null) => (score === null ? '—' : score.toFixed(2))

onMounted(async () => {
  cycles.value = await performanceCyclesService.listAll().catch(() => [])
  const latest = cycles.value.find((c) => c.status === 'active') ?? cycles.value.find((c) => c.status === 'closed') ?? cycles.value[0]
  cycleId.value = latest ? String(latest.id) : ''
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <BaseSelect v-model="cycleId" class="w-full sm:w-64" :options="cycleOptions" :placeholder="t('admin.performance.review.pickCycle')" />
      <p class="text-sm text-neutral-500">{{ t('admin.performance.score.hint') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="!cycleId" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.review.noCycle') }}</p>
    <div v-else-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="data">
      <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <!-- Progress -->
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <p class="text-sm text-neutral-500">{{ t('admin.performance.score.progress') }}</p>
          <p class="text-2xl font-semibold text-neutral-900">{{ data.counts.completed }} / {{ data.counts.total }}</p>
          <div class="mt-2 h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-primary-500" :style="{ width: `${done}%` }" /></div>
          <p class="mt-1 text-xs text-neutral-500">{{ t('admin.performance.score.waiting', { self: data.counts.self_assessment, manager: data.counts.manager_assessment }) }}</p>
        </div>
        <!-- Averages -->
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <p class="text-sm text-neutral-500">{{ t('admin.performance.score.average') }}</p>
          <p class="text-2xl font-semibold text-neutral-900">{{ scoreLabel(data.averages.final_score) }}</p>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('admin.performance.tabs.kpi') }} {{ fixed(data.averages.kpi_score) }} · {{ t('admin.performance.tabs.goals') }} {{ fixed(data.averages.goal_score) }} · {{ t('admin.performance.weights.manager') }} {{ fixed(data.averages.manager_score) }}
          </p>
        </div>
        <!-- Bands -->
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <p class="mb-2 text-sm text-neutral-500">{{ t('admin.performance.score.spread') }}</p>
          <div v-for="band in ['5', '4', '3', '2', '1']" :key="band" class="mb-1 flex items-center gap-2 text-xs">
            <span class="w-24 shrink-0 text-neutral-600">{{ t(`admin.performance.rating.${band}`) }}</span>
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-neutral-100"><div class="h-full" :class="bandColor[band]" :style="{ width: `${(data.bands[band as '1'] / bandMax) * 100}%` }" /></div>
            <span class="w-6 text-right tabular-nums text-neutral-700">{{ data.bands[band as '1'] }}</span>
          </div>
        </div>
      </div>

      <p v-if="data.rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.score.noneCompleted') }}</p>
      <template v-else>
        <div class="space-y-2 sm:hidden">
          <div v-for="(row, index) in data.rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800"><span class="mr-1 text-neutral-400">#{{ index + 1 }}</span>{{ row.staff?.name }}</p>
              <span class="shrink-0 text-sm font-bold text-neutral-900">{{ fixed(row.final_score) }}</span>
            </div>
            <p class="text-xs text-neutral-500">{{ [row.staff?.position, row.staff?.department].filter(Boolean).join(' · ') || '—' }}</p>
            <p class="text-xs text-neutral-600">{{ t('admin.performance.tabs.kpi') }} {{ fixed(row.kpi_score) }} · {{ t('admin.performance.tabs.goals') }} {{ fixed(row.goal_score) }} · {{ t('admin.performance.weights.manager') }} {{ fixed(row.manager_score) }} · {{ t('admin.performance.score.self') }} {{ fixed(row.self_score) }}</p>
          </div>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">#</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.staff') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.tabs.kpi') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.tabs.goals') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.weights.manager') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.score.self') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.score.final') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, index) in data.rows" :key="row.id">
                <td class="px-4 py-3 text-neutral-400">{{ index + 1 }}</td>
                <td class="px-4 py-3">
                  <p class="font-medium text-neutral-800">{{ row.staff?.name }}</p>
                  <p class="text-xs text-neutral-500">{{ [row.staff?.position, row.staff?.department].filter(Boolean).join(' · ') || row.staff?.employee_code }}</p>
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ fixed(row.kpi_score) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ fixed(row.goal_score) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ fixed(row.manager_score) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-neutral-500">{{ fixed(row.self_score) }}</td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ scoreLabel(row.final_score) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
