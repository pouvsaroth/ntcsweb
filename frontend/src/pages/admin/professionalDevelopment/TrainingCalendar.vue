<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import TrainingDetailModal from '@/components/admin/TrainingDetailModal.vue'
import TrainingFormModal from '@/components/admin/TrainingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  TRAINING_STATUSES,
  trainingsService,
  type PdStaffOption,
  type Training,
  type TrainingSummary,
  type TrainingWhen,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { trainingDays, trainingStatusVariant } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Training Calendar: trainings on a month
 * grid (on a phone, just the days that have one), or as a list. A training
 * opens its details, attendance and certificates.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('professional-development.manage'))

const view = ref<'month' | 'list'>('month')
const summary = ref<TrainingSummary | null>(null)
const staff = ref<PdStaffOption[]>([])
const categories = ref<string[]>([])

async function loadSummary() {
  try {
    summary.value = await trainingsService.summary()
  } catch {
    // The cards are a convenience.
  }
}

const cards = computed(() => [
  { key: 'upcoming', label: t('proDev.cards.upcoming'), value: summary.value?.upcoming ?? 0, tone: 'text-primary-700' },
  { key: 'this_month', label: t('proDev.cards.thisMonth'), value: summary.value?.this_month ?? 0, tone: 'text-neutral-900' },
  { key: 'completed', label: t('proDev.cards.completedThisYear'), value: summary.value?.completed_this_year ?? 0, tone: 'text-green-700' },
  { key: 'staff', label: t('proDev.cards.staffTrained'), value: summary.value?.staff_trained_this_year ?? 0, tone: 'text-amber-700' },
])

// --- Month grid -------------------------------------------------------------
const today = new Date()
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const month = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const monthTrainings = ref<Training[]>([])
const monthLoading = ref(false)
const monthError = ref<string | null>(null)

async function loadMonth() {
  monthLoading.value = true
  monthError.value = null
  try {
    monthTrainings.value = await trainingsService.calendar(month.value)
  } catch (e) {
    monthError.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    monthLoading.value = false
  }
}
watch(month, () => void loadMonth())

function shiftMonth(by: number) {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const next = new Date(y, m - 1 + by, 1)
  month.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}

const monthLabel = computed(() => {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  return new Date(y, m - 1, 1).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
})
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(locale.value, { weekday: 'short' })))

interface Day {
  date: string
  day: number
  inMonth: boolean
  isToday: boolean
  trainings: Training[]
}

const days = computed<Day[]>(() => {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const first = new Date(y, m - 1, 1)
  const start = new Date(first)
  start.setDate(1 - ((first.getDay() + 6) % 7))
  const last = new Date(y, m, 0)
  const end = new Date(last)
  end.setDate(last.getDate() + (6 - ((last.getDay() + 6) % 7)))
  const todayIso = iso(today)

  const result: Day[] = []
  for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    const date = iso(d)
    result.push({
      date,
      day: d.getDate(),
      inMonth: d.getMonth() === m - 1,
      isToday: date === todayIso,
      trainings: monthTrainings.value.filter((tr) => tr.start_date <= date && tr.end_date >= date),
    })
  }
  return result
})
const busyDays = computed(() => days.value.filter((d) => d.inMonth && d.trainings.length > 0))
const MAX_CHIPS = 3

const chipClass = (tr: Training) =>
  tr.status === 'cancelled'
    ? 'bg-neutral-200 text-neutral-500 line-through'
    : tr.status === 'completed'
      ? 'bg-green-600 text-white'
      : 'bg-primary-600 text-white'

// --- List -------------------------------------------------------------------
const when = ref<TrainingWhen>('upcoming')
const statusFilter = ref('')
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Training>((query) => trainingsService.list({ ...query, when: when.value }))

function setWhen(value: TrainingWhen) {
  when.value = value
  if (value === 'upcoming' && statusFilter.value) {
    statusFilter.value = ''
    setFilter('status', undefined)
    return
  }
  setPage(1)
}
function setStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const whenOptions = computed(() => [
  { value: 'upcoming' as const, label: t('proDev.trainings.upcoming') },
  { value: 'past' as const, label: t('proDev.trainings.past') },
  { value: '' as const, label: t('proDev.all') },
])
const statusOptions = computed(() => [{ value: '', label: t('proDev.allStatuses') }, ...TRAINING_STATUSES.map((s) => ({ value: s, label: t(`proDev.statuses.${s}`) }))])

const columns = computed(() => [
  { key: 'when', label: t('proDev.trainings.when') },
  { key: 'title', label: t('proDev.trainings.title') },
  { key: 'trainer', label: t('proDev.trainings.trainer') },
  { key: 'people', label: t('proDev.trainings.participants') },
  { key: 'status', label: t('proDev.trainings.status') },
])

// --- Popups -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Training | null>(null)
const preset = ref<{ start_date?: string } | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openCreate(date?: string) {
  editing.value = null
  preset.value = date ? { start_date: date } : null
  formOpen.value = true
}
function openEdit(tr: Training) {
  editing.value = tr
  preset.value = null
  detailOpen.value = false
  formOpen.value = true
}
function openDetail(tr: Training) {
  detailId.value = tr.id
  detailOpen.value = true
}

function refresh() {
  void loadSummary()
  void loadMonth()
  if (view.value === 'list') void fetch()
}

function onSaved(saved: Training) {
  refresh()
  if (!editing.value) openDetail(saved)
}

watch(view, (v) => {
  if (v === 'list') void fetch()
})

onMounted(async () => {
  refresh()
  const [s, c] = await Promise.all([trainingsService.staffOptions().catch(() => []), trainingsService.categories().catch(() => [])])
  staff.value = s
  categories.value = c
})
</script>

<template>
  <div>
    <div class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
      <div v-for="card in cards" :key="card.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-2.5 shadow-[--shadow-card] sm:p-4">
        <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
        <p class="mt-0.5 text-xl font-semibold sm:mt-1 sm:text-2xl" :class="card.tone">{{ card.value }}</p>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="v in (['month', 'list'] as const)"
          :key="v"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="view === v ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="view === v"
          @click="view = v"
        >
          {{ t(`proDev.trainings.view.${v}`) }}
        </button>
      </div>

      <template v-if="view === 'month'">
        <div class="flex items-center gap-1">
          <BaseButton variant="outline" size="sm" :aria-label="t('proDev.previousMonth')" @click="shiftMonth(-1)">‹</BaseButton>
          <p class="min-w-40 text-center text-base font-semibold text-neutral-900">{{ monthLabel }}</p>
          <BaseButton variant="outline" size="sm" :aria-label="t('proDev.nextMonth')" @click="shiftMonth(1)">›</BaseButton>
        </div>
        <BaseSpinner v-if="monthLoading" />
      </template>

      <template v-else>
        <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
          <button
            v-for="o in whenOptions"
            :key="o.value"
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition"
            :class="when === o.value ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
            :aria-pressed="when === o.value"
            @click="setWhen(o.value)"
          >
            {{ o.label }}
          </button>
        </div>
        <input
          type="search"
          :placeholder="t('proDev.trainings.searchPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
          @input="setSearch(($event.target as HTMLInputElement).value)"
        />
        <select
          v-if="when !== 'upcoming'"
          :value="statusFilter"
          class="rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          :aria-label="t('proDev.trainings.status')"
          @change="setStatus(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </template>

      <BaseButton v-if="canManage" class="sm:ml-auto" @click="openCreate()">{{ t('proDev.trainings.add') }}</BaseButton>
    </div>

    <!-- Month view -->
    <template v-if="view === 'month'">
      <BaseAlert v-if="monthError" variant="danger" class="mb-4">{{ monthError }}</BaseAlert>

      <!-- Phone: just the days with a training -->
      <div class="sm:hidden">
        <p v-if="!monthLoading && busyDays.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('proDev.trainings.emptyMonth') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="day in busyDays" :key="day.date" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <p class="text-sm font-semibold" :class="day.isToday ? 'text-primary-700' : 'text-neutral-800'">
              {{ new Date(day.date + 'T00:00:00').toLocaleDateString(locale, { weekday: 'long' }) }} {{ formatDate(day.date) }}
            </p>
            <button v-for="tr in day.trainings" :key="tr.id" type="button" class="mt-1.5 flex w-full items-center justify-between gap-2 text-left" @click="openDetail(tr)">
              <span class="min-w-0 truncate text-sm text-neutral-800" :class="tr.status === 'cancelled' ? 'line-through text-neutral-400' : ''">{{ tr.title }}</span>
              <BaseBadge :variant="trainingStatusVariant(tr.status)">{{ t(`proDev.statuses.${tr.status}`) }}</BaseBadge>
            </button>
          </div>
        </div>
      </div>

      <!-- Month grid from sm up -->
      <div class="hidden overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50 text-xs font-medium text-neutral-500">
          <div v-for="name in weekdays" :key="name" class="px-2 py-2 text-center">{{ name }}</div>
        </div>
        <div class="grid grid-cols-7">
          <div
            v-for="day in days"
            :key="day.date"
            class="group min-h-24 border-b border-r border-neutral-100 p-1.5 [&:nth-child(7n)]:border-r-0"
            :class="day.inMonth ? 'bg-white' : 'bg-neutral-50'"
          >
            <div class="flex items-start justify-between gap-1">
              <span
                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs"
                :class="day.isToday ? 'bg-primary-600 font-semibold text-white' : day.inMonth ? 'text-neutral-700' : 'text-neutral-300'"
              >
                {{ day.day }}
              </span>
              <button
                v-if="canManage && day.inMonth"
                type="button"
                class="hidden h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 group-hover:inline-flex"
                :aria-label="t('proDev.trainings.add')"
                @click="openCreate(day.date)"
              >
                +
              </button>
            </div>
            <div class="mt-1 space-y-0.5">
              <button
                v-for="tr in day.trainings.slice(0, MAX_CHIPS)"
                :key="tr.id"
                type="button"
                :title="`${tr.title} · ${trainingDays(tr.start_date, tr.end_date, formatDate)}`"
                class="block w-full truncate rounded px-1.5 py-0.5 text-left text-[11px] font-medium"
                :class="chipClass(tr)"
                @click="openDetail(tr)"
              >
                {{ tr.start_time && tr.start_date === day.date ? `${tr.start_time} ` : '' }}{{ tr.title }}
              </button>
              <p v-if="day.trainings.length > MAX_CHIPS" class="px-1 text-[11px] text-neutral-500" :title="day.trainings.slice(MAX_CHIPS).map((x) => x.title).join('\n')">
                {{ t('proDev.more', { count: day.trainings.length - MAX_CHIPS }) }}
              </p>
            </div>
          </div>
        </div>
      </div>
      <p class="mt-2 hidden text-xs text-neutral-500 sm:block">{{ t('proDev.trainings.legend') }}</p>
    </template>

    <!-- List view -->
    <template v-else>
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('proDev.trainings.empty')">
          <template #cell-when="{ row }">{{ trainingDays(row.start_date, row.end_date, formatDate) }}</template>
          <template #cell-title="{ row }">
            <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.title }}</button>
            <span v-if="row.category" class="block text-xs text-neutral-500">{{ row.category }}</span>
          </template>
          <template #cell-trainer="{ row }">
            {{ row.trainer || '—' }}
            <span class="block text-xs text-neutral-500">{{ t(`proDev.providers.${row.provider_type}`) }}</span>
          </template>
          <template #cell-people="{ row }">
            <template v-if="row.status === 'completed'">{{ row.attended_count ?? 0 }} / </template>{{ row.participants_count ?? 0 }}
          </template>
          <template #cell-status="{ row }">
            <BaseBadge :variant="trainingStatusVariant(row.status)">{{ t(`proDev.statuses.${row.status}`) }}</BaseBadge>
          </template>
        </DataTable>
      </div>

      <div class="space-y-2 sm:hidden">
        <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.trainings.empty') }}</p>
        <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm" @click="openDetail(row)">
          <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.title }}</p>
            <BaseBadge :variant="trainingStatusVariant(row.status)">{{ t(`proDev.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">{{ trainingDays(row.start_date, row.end_date, formatDate) }}</p>
          <p class="mt-0.5 text-xs text-neutral-500">
            <template v-if="row.trainer">{{ row.trainer }} · </template>{{ t('proDev.peopleCount', { n: row.participants_count ?? 0 }) }}
          </p>
        </button>
      </div>

      <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
    </template>

    <TrainingFormModal v-model="formOpen" :training="editing" :staff="staff" :categories="categories" :preset="preset" @saved="onSaved" />
    <TrainingDetailModal v-model="detailOpen" :training-id="detailId" @changed="refresh" @edit="openEdit" />
  </div>
</template>
