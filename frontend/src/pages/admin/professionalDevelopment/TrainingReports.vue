<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { trainingReportsService, type TrainingReport } from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * HRM > Professional Development > Training reports: for a date range, the
 * totals, hours by month, a category breakdown and one row per staff member
 * (including people with no training yet), downloadable as CSV.
 */
const { t, locale } = useI18n()

const year = new Date().getFullYear()
const from = ref(`${year}-01-01`)
const to = ref(`${year}-12-31`)
const report = ref<TrainingReport | null>(null)
const loading = ref(false)
const downloading = ref(false)
const error = ref<string | null>(null)

async function load() {
  if (!from.value || !to.value) return
  loading.value = true
  error.value = null
  try {
    report.value = await trainingReportsService.get(from.value, to.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    loading.value = false
  }
}

function setYear(y: number) {
  from.value = `${y}-01-01`
  to.value = `${y}-12-31`
  void load()
}

async function downloadCsv() {
  downloading.value = true
  try {
    await trainingReportsService.downloadCsv(from.value, to.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    downloading.value = false
  }
}

const cards = computed(() => {
  const s = report.value?.totals
  if (!s) return []
  return [
    { key: 'trainings', label: t('proDev.reports.cards.trainings'), value: String(s.trainings), sub: t('proDev.reports.cards.completedOf', { n: s.completed }) },
    { key: 'hours', label: t('proDev.reports.cards.hours'), value: String(s.hours), sub: t('proDev.reports.cards.staffTrained', { n: s.staff_trained }) },
    {
      key: 'attendance',
      label: t('proDev.reports.cards.attendance'),
      value: s.attendance_rate != null ? `${s.attendance_rate}%` : '—',
      sub: t('proDev.reports.cards.attendedOf', { n: s.attended, total: s.assigned }),
    },
    { key: 'cost', label: t('proDev.reports.cards.cost'), value: formatMoney(s.cost, 'USD'), sub: t('proDev.reports.cards.completedOnly') },
    { key: 'certificates', label: t('proDev.reports.cards.certificates'), value: String(s.certificates), sub: '' },
    {
      key: 'observations',
      label: t('proDev.reports.cards.observations'),
      value: String(s.observations),
      sub: s.average_rating != null ? t('proDev.reports.cards.averageRating', { n: s.average_rating }) : '',
    },
    { key: 'coaching', label: t('proDev.reports.cards.coachingSessions'), value: String(s.coaching_sessions), sub: '' },
    { key: 'plans', label: t('proDev.reports.cards.activePlans'), value: String(s.active_plans), sub: t('proDev.reports.cards.goalsDone', { n: s.plan_goals_done }) },
  ]
})

// --- Hours by month (single series: one hue, no legend; values on hover/focus) ---
const months = computed(() => report.value?.by_month ?? [])
const maxHours = computed(() => Math.max(0, ...months.value.map((m) => m.hours)))
function monthLabel(ym: string, long = false): string {
  const [y, m] = ym.split('-').map(Number)
  return new Date(y!, m! - 1, 1).toLocaleDateString(locale.value, long ? { month: 'long', year: 'numeric' } : { month: 'short' })
}
const hovered = ref<string | null>(null)

// --- Per staff ------------------------------------------------------------------
const teachersOnly = ref(false)
const untrainedOnly = ref(false)
const staffSearch = ref('')
const shown = ref(25)
const staffRows = computed(() => {
  const q = staffSearch.value.trim().toLowerCase()
  return (report.value?.by_staff ?? []).filter(
    (r) =>
      (!teachersOnly.value || r.is_teacher) &&
      (!untrainedOnly.value || r.trainings === 0) &&
      (!q || r.name.toLowerCase().includes(q) || (r.employee_code ?? '').toLowerCase().includes(q) || (r.department ?? '').toLowerCase().includes(q)),
  )
})
const untrainedCount = computed(() => (report.value?.by_staff ?? []).filter((r) => r.trainings === 0).length)

const staffColumns = computed(() => [
  { key: 'name', label: t('proDev.reports.staff') },
  { key: 'department', label: t('proDev.reports.department') },
  { key: 'trainings', label: t('proDev.reports.trainingsAttended') },
  { key: 'hours', label: t('proDev.reports.hours') },
  { key: 'certificates', label: t('proDev.reports.certificates') },
  { key: 'last', label: t('proDev.reports.lastTraining') },
  { key: 'observations', label: t('proDev.reports.observations') },
])
const categoryColumns = computed(() => [
  { key: 'category', label: t('proDev.reports.category') },
  { key: 'trainings', label: t('proDev.reports.trainings') },
  { key: 'attended', label: t('proDev.reports.attended') },
  { key: 'hours', label: t('proDev.reports.hours') },
  { key: 'cost', label: t('proDev.reports.cost') },
])
const categoryRows = computed(() => (report.value?.by_category ?? []).map((r, i) => ({ ...r, key: i })))

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-5 flex flex-wrap items-end gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="y in [year - 1, year]"
          :key="y"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="from === `${y}-01-01` && to === `${y}-12-31` ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          @click="setYear(y)"
        >
          {{ y }}
        </button>
      </div>
      <BaseInput v-model="from" type="date" class="w-[calc(50%-0.25rem)] sm:w-40" :label="t('proDev.reports.from')" />
      <BaseInput v-model="to" type="date" class="w-[calc(50%-0.25rem)] sm:w-40" :label="t('proDev.reports.to')" />
      <BaseButton variant="outline" :loading="loading" @click="load">{{ t('proDev.reports.show') }}</BaseButton>
      <BaseButton variant="outline" class="sm:ml-auto" :loading="downloading" :disabled="!report" @click="downloadCsv">{{ t('proDev.reports.downloadCsv') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-if="report">
      <div class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <div v-for="card in cards" :key="card.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-2.5 shadow-[--shadow-card] sm:p-4">
          <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
          <p class="mt-0.5 text-xl font-semibold text-neutral-900 sm:mt-1 sm:text-2xl">{{ card.value }}</p>
          <p v-if="card.sub" class="truncate text-xs text-neutral-500">{{ card.sub }}</p>
        </div>
      </div>

      <!-- Hours by month -->
      <section class="mb-5 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card] sm:p-4">
        <h2 class="text-sm font-semibold text-neutral-900">{{ t('proDev.reports.hoursByMonth') }}</h2>
        <p class="text-xs text-neutral-500">{{ t('proDev.reports.hoursByMonthHint') }}</p>
        <p v-if="maxHours === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.reports.noHours') }}</p>
        <!-- Top padding leaves room for the hover tooltip inside the scroll box. -->
        <div v-else class="overflow-x-auto pt-16">
          <div class="flex h-44 min-w-[28rem] items-end gap-[2px] border-b border-neutral-200" role="list" :aria-label="t('proDev.reports.hoursByMonth')">
            <div
              v-for="m in months"
              :key="m.month"
              role="listitem"
              tabindex="0"
              class="group relative flex h-full flex-1 flex-col items-center justify-end outline-none"
              :aria-label="`${monthLabel(m.month, true)}: ${t('proDev.hoursCount', { n: m.hours })}, ${t('proDev.reports.trainingsN', { n: m.trainings })}`"
              @mouseenter="hovered = m.month"
              @mouseleave="hovered = null"
              @focus="hovered = m.month"
              @blur="hovered = null"
            >
              <div
                v-if="hovered === m.month"
                class="pointer-events-none absolute bottom-full z-10 mb-1 whitespace-nowrap rounded-md border border-neutral-200 bg-white px-2 py-1 text-xs text-neutral-700 shadow-lg"
              >
                <p class="font-medium text-neutral-900">{{ monthLabel(m.month, true) }}</p>
                <p>{{ t('proDev.hoursCount', { n: m.hours }) }} · {{ t('proDev.reports.attendedN', { n: m.attended }) }}</p>
                <p>{{ t('proDev.reports.trainingsN', { n: m.trainings }) }}</p>
              </div>
              <span v-if="m.hours > 0 && m.hours === maxHours" class="mb-0.5 text-[11px] font-medium text-neutral-700">{{ m.hours }}</span>
              <div
                class="w-full max-w-8 rounded-t bg-primary-500 transition-opacity"
                :class="hovered && hovered !== m.month ? 'opacity-50' : ''"
                :style="{ height: `${m.hours > 0 ? Math.max((m.hours / maxHours) * 85, 2) : 0}%` }"
              />
            </div>
          </div>
          <div class="flex min-w-[28rem] gap-[2px] pt-1">
            <span v-for="m in months" :key="m.month" class="flex-1 truncate text-center text-[11px] text-neutral-500">{{ monthLabel(m.month) }}</span>
          </div>
        </div>
      </section>

      <!-- By category -->
      <section class="mb-5">
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('proDev.reports.byCategory') }}</h2>
        <div class="hidden sm:block">
          <DataTable :columns="categoryColumns" :rows="categoryRows" row-key="key" :empty-message="t('proDev.reports.noTrainings')">
            <template #cell-category="{ row }">{{ row.category ?? t('proDev.reports.noCategory') }}</template>
            <template #cell-cost="{ row }">{{ formatMoney(row.cost, 'USD') }}</template>
          </DataTable>
        </div>
        <div class="space-y-2 sm:hidden">
          <p v-if="categoryRows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.reports.noTrainings') }}</p>
          <div v-for="row in categoryRows" :key="row.key" class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm">
            <p class="font-medium text-neutral-900">{{ row.category ?? t('proDev.reports.noCategory') }}</p>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t('proDev.reports.trainingsN', { n: row.trainings }) }} · {{ t('proDev.reports.attendedN', { n: row.attended }) }} · {{ t('proDev.hoursCount', { n: row.hours }) }} ·
              {{ formatMoney(row.cost, 'USD') }}
            </p>
          </div>
        </div>
      </section>

      <!-- By staff -->
      <section>
        <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-2">
          <h2 class="text-sm font-semibold text-neutral-900">{{ t('proDev.reports.byStaff') }}</h2>
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="teachersOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('proDev.reports.teachersOnly') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="untrainedOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('proDev.reports.untrainedOnly', { n: untrainedCount }) }}
          </label>
          <input
            v-model="staffSearch"
            type="search"
            :placeholder="t('proDev.reports.searchStaff')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:ml-auto sm:max-w-xs"
          />
        </div>
        <div class="hidden sm:block">
          <DataTable :columns="staffColumns" :rows="staffRows.slice(0, shown)" row-key="staff_id" :empty-message="t('proDev.reports.noStaff')">
            <template #cell-name="{ row }">
              <span class="font-medium text-neutral-900">{{ row.name }}</span>
              <span v-if="row.employee_code" class="block text-xs text-neutral-500">{{ row.employee_code }}</span>
            </template>
            <template #cell-department="{ row }">{{ row.department ?? '—' }}</template>
            <template #cell-trainings="{ row }"><span :class="row.trainings === 0 ? 'text-amber-700' : ''">{{ row.trainings }}</span></template>
            <template #cell-last="{ row }">{{ row.last_training ? formatDate(row.last_training) : '—' }}</template>
            <template #cell-observations="{ row }">
              <template v-if="row.observations">{{ row.observations }}<span v-if="row.average_rating != null" class="text-xs text-neutral-500"> · ★ {{ row.average_rating }}</span></template>
              <template v-else>—</template>
            </template>
          </DataTable>
        </div>
        <div class="space-y-2 sm:hidden">
          <p v-if="staffRows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.reports.noStaff') }}</p>
          <div v-for="row in staffRows.slice(0, shown)" :key="row.staff_id" class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.name }}</p>
              <span class="shrink-0 text-sm font-medium" :class="row.trainings === 0 ? 'text-amber-700' : 'text-neutral-900'">{{ t('proDev.hoursCount', { n: row.hours }) }}</span>
            </div>
            <p class="mt-0.5 text-xs text-neutral-500">
              <template v-if="row.department">{{ row.department }} · </template>{{ t('proDev.reports.trainingsN', { n: row.trainings }) }} ·
              {{ t('proDev.reports.certificatesN', { n: row.certificates }) }}<template v-if="row.last_training"> · {{ formatDate(row.last_training) }}</template>
            </p>
          </div>
        </div>
        <div v-if="staffRows.length > shown" class="mt-3 text-center">
          <BaseButton variant="outline" size="sm" @click="shown += 50">{{ t('proDev.reports.showMore', { n: staffRows.length - shown }) }}</BaseButton>
        </div>
      </section>
    </template>
  </div>
</template>
