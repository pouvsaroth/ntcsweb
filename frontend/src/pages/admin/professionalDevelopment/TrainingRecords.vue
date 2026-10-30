<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import TrainingDetailModal from '@/components/admin/TrainingDetailModal.vue'
import TrainingFormModal from '@/components/admin/TrainingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  ATTENDANCES,
  trainingRecordsService,
  trainingsService,
  type PdStaffOption,
  type RecordTotals,
  type Training,
  type TrainingParticipant,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'
import { attendanceVariant, resultVariant, trainingDays } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Training Records: every training each
 * staff member was on — pick a person to see their history and totals. A
 * course they went to outside the school can be added here.
 */
const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('professional-development.manage'))

const staffId = ref('')
const year = ref('')
const attendance = ref('')
const search = ref('')

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<TrainingParticipant>((query) =>
  trainingRecordsService.list({
    ...query,
    staff_id: staffId.value ? Number(staffId.value) : null,
    year: year.value ? Number(year.value) : null,
    attendance: attendance.value || null,
  }),
)

const totals = ref<RecordTotals | null>(null)
async function loadTotals() {
  try {
    totals.value = await trainingRecordsService.totals({
      staff_id: staffId.value ? Number(staffId.value) : null,
      year: year.value ? Number(year.value) : null,
      attendance: attendance.value || null,
      search: search.value || undefined,
    })
  } catch {
    totals.value = null
  }
}

function refresh() {
  void fetch()
  void loadTotals()
}

function onFilter() {
  setPage(1)
  void loadTotals()
}

let totalsTimer: ReturnType<typeof setTimeout> | undefined
function onSearch(value: string) {
  search.value = value
  setSearch(value)
  clearTimeout(totalsTimer)
  totalsTimer = setTimeout(() => void loadTotals(), 350)
}

const staff = ref<PdStaffOption[]>([])
const categories = ref<string[]>([])
const staffOptions = computed(() => [
  { value: '', label: t('proDev.records.allStaff') },
  ...staff.value.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])
const thisYear = new Date().getFullYear()
const yearOptions = computed(() => [{ value: '', label: t('proDev.allYears') }, ...Array.from({ length: 6 }, (_, i) => ({ value: String(thisYear + 1 - i), label: String(thisYear + 1 - i) }))])
const attendanceOptions = computed(() => [{ value: '', label: t('proDev.records.allAttendance') }, ...ATTENDANCES.map((a) => ({ value: a, label: t(`proDev.attendance.${a}`) }))])

const cards = computed(() => [
  { key: 'trainings', label: t('proDev.records.cards.trainings'), value: totals.value?.trainings ?? 0, tone: 'text-neutral-900' },
  { key: 'attended', label: t('proDev.records.cards.attended'), value: totals.value?.attended ?? 0, tone: 'text-green-700' },
  { key: 'hours', label: t('proDev.records.cards.hours'), value: totals.value?.hours ?? 0, tone: 'text-primary-700' },
  { key: 'certificates', label: t('proDev.records.cards.certificates'), value: totals.value?.certificates ?? 0, tone: 'text-amber-700' },
])

const columns = computed(() => [
  { key: 'when', label: t('proDev.trainings.when') },
  { key: 'staff', label: t('proDev.records.staff') },
  { key: 'training', label: t('proDev.records.training') },
  { key: 'hours', label: t('proDev.trainings.hours'), align: 'text-right' },
  { key: 'attendance', label: t('proDev.records.attendance') },
  { key: 'result', label: t('proDev.records.result') },
  { key: 'certificate', label: t('proDev.records.certificate') },
])

// --- Popups -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Training | null>(null)
const preset = ref<{ status: 'completed'; provider_type: 'external'; staff_id: number | null } | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openAddRecord() {
  editing.value = null
  preset.value = { status: 'completed', provider_type: 'external', staff_id: staffId.value ? Number(staffId.value) : null }
  formOpen.value = true
}
function openDetail(row: TrainingParticipant) {
  detailId.value = row.training_id
  detailOpen.value = true
}
function openEdit(tr: Training) {
  editing.value = tr
  preset.value = null
  detailOpen.value = false
  formOpen.value = true
}

onMounted(async () => {
  refresh()
  const [s, c] = await Promise.all([trainingsService.staffOptions().catch(() => []), trainingsService.categories().catch(() => [])])
  staff.value = s
  categories.value = c
})

const selectClass =
  'rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <SearchableSelect v-model="staffId" class="w-full sm:w-64" :options="staffOptions" :placeholder="t('proDev.records.pickStaff')" @update:model-value="onFilter" />
      <select v-model="year" :class="selectClass" :aria-label="t('proDev.year')" @change="onFilter">
        <option v-for="o in yearOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select v-model="attendance" :class="selectClass" :aria-label="t('proDev.records.attendance')" @change="onFilter">
        <option v-for="o in attendanceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <input
        type="search"
        :placeholder="t('proDev.records.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" variant="outline" class="sm:ml-auto" @click="openAddRecord">{{ t('proDev.records.addPast') }}</BaseButton>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
      <div v-for="card in cards" :key="card.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-2.5 shadow-[--shadow-card] sm:p-4">
        <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
        <p class="mt-0.5 text-xl font-semibold sm:mt-1 sm:text-2xl" :class="card.tone">{{ card.value }}</p>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('proDev.records.empty')">
        <template #cell-when="{ row }">{{ row.training ? trainingDays(row.training.start_date, row.training.end_date, formatDate) : '—' }}</template>
        <template #cell-staff="{ row }">
          <span class="font-medium text-neutral-900">{{ row.staff?.name ?? '—' }}</span>
          <span v-if="row.staff?.employee_code" class="block text-xs text-neutral-500">{{ row.staff.employee_code }}</span>
        </template>
        <template #cell-training="{ row }">
          <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.training?.title }}</button>
          <span class="block text-xs text-neutral-500">
            {{ row.training ? t(`proDev.providers.${row.training.provider_type}`) : '' }}<template v-if="row.training?.trainer"> · {{ row.training.trainer }}</template>
          </span>
        </template>
        <template #cell-hours="{ row }">{{ row.training?.hours ?? '—' }}</template>
        <template #cell-attendance="{ row }">
          <BaseBadge :variant="attendanceVariant(row.attendance)">{{ t(`proDev.attendance.${row.attendance}`) }}</BaseBadge>
        </template>
        <template #cell-result="{ row }">
          <BaseBadge v-if="row.result" :variant="resultVariant(row.result)">{{ t(`proDev.results.${row.result}`) }}</BaseBadge>
          <span v-else class="text-neutral-400">—</span>
          <span v-if="row.score != null" class="ml-1 text-xs text-neutral-500">{{ row.score }}</span>
        </template>
        <template #cell-certificate="{ row }">
          <span v-if="row.certificate" class="text-sm text-neutral-800">{{ row.certificate.no }}</span>
          <span v-else class="text-neutral-400">—</span>
        </template>
      </DataTable>
    </div>

    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.records.empty') }}</p>
      <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm" @click="openDetail(row)">
        <div class="flex items-start justify-between gap-2">
          <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.training?.title }}</p>
          <BaseBadge :variant="attendanceVariant(row.attendance)">{{ t(`proDev.attendance.${row.attendance}`) }}</BaseBadge>
        </div>
        <p class="mt-1 text-sm text-neutral-700">{{ row.staff?.name }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">
          {{ row.training ? trainingDays(row.training.start_date, row.training.end_date, formatDate) : '' }}
          <template v-if="row.training?.hours"> · {{ t('proDev.hoursCount', { n: row.training.hours }) }}</template>
          <template v-if="row.result"> · {{ t(`proDev.results.${row.result}`) }}</template>
          <template v-if="row.certificate"> · {{ row.certificate.no }}</template>
        </p>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <TrainingFormModal v-model="formOpen" :training="editing" :staff="staff" :categories="categories" :preset="preset" @saved="refresh" />
    <TrainingDetailModal v-model="detailOpen" :training-id="detailId" @changed="refresh" @edit="openEdit" />
  </div>
</template>
