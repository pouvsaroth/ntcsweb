<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { teachersService, type TeacherOptions, type TeachingLoad } from '@/services/teachers'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Teacher > Teaching load: per teacher, the weekly hours of the classes
 * they teach (class schedules), the hours set on their assignments, their
 * classes and homeroom.
 */
const { t } = useI18n()

const years = ref<TeacherOptions['academic_years']>([])
const yearId = ref('')
const rows = ref<TeachingLoad[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const yearOptions = computed(() => [{ value: '', label: t('teacherMgmt.allYears') }, ...years.value.map((y) => ({ value: String(y.id), label: y.name }))])
const total = computed(() => rows.value.reduce((sum, r) => sum + r.scheduled_hours, 0))
const average = computed(() => (rows.value.length ? Math.round((total.value / rows.value.length) * 10) / 10 : 0))

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await teachersService.load(yearId.value ? Number(yearId.value) : undefined)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function onYear(value: string) {
  yearId.value = value
  void load()
}

const columns = computed(() => [
  { key: 'name', label: t('teacherMgmt.teacher') },
  { key: 'scheduled', label: t('teacherMgmt.load.scheduled') },
  { key: 'assigned', label: t('teacherMgmt.load.assigned') },
  { key: 'classes', label: t('teacherMgmt.load.classes') },
  { key: 'homeroom', label: t('teacherMgmt.tabs.homeroom') },
])
/** A simple bar against the busiest teacher. */
const max = computed(() => Math.max(1, ...rows.value.map((r) => r.scheduled_hours)))

onMounted(async () => {
  try {
    years.value = (await teachersService.options()).academic_years
  } catch {
    /* the list still loads for every year */
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-48" :model-value="yearId" :options="yearOptions" @update:model-value="onYear" />
      <p class="text-sm text-neutral-500">{{ t('teacherMgmt.load.summary', { teachers: rows.length, hours: total, average }) }}</p>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>
    <p class="mb-3 text-xs text-neutral-500">{{ t('teacherMgmt.load.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.noTeachers') }}</p>
      <div v-for="row in rows" :key="row.staff_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ row.name }}</p>
          <span class="text-sm font-semibold text-neutral-900">{{ t('teacherMgmt.hoursWeekN', { hours: row.scheduled_hours }) }}</span>
        </div>
        <div class="mt-1 h-1.5 rounded-full bg-neutral-100"><div class="h-1.5 rounded-full bg-primary-500" :style="{ width: `${(row.scheduled_hours / max) * 100}%` }" /></div>
        <p class="mt-1 text-xs text-neutral-500">{{ row.classes.join(', ') || '—' }}</p>
        <p v-if="row.homeroom.length" class="text-xs text-neutral-500">{{ t('teacherMgmt.tabs.homeroom') }}: {{ row.homeroom.join(', ') }}</p>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="staff_id" :loading="loading" :empty-message="t('teacherMgmt.noTeachers')">
        <template #cell-name="{ row }">
          <p class="font-medium text-neutral-800">{{ row.name }}</p>
          <p v-if="row.employee_code" class="text-xs text-neutral-500">{{ row.employee_code }}</p>
        </template>
        <template #cell-scheduled="{ row }">
          <div class="flex items-center gap-2">
            <div class="h-1.5 w-24 rounded-full bg-neutral-100"><div class="h-1.5 rounded-full bg-primary-500" :style="{ width: `${(row.scheduled_hours / max) * 100}%` }" /></div>
            <span class="font-medium">{{ row.scheduled_hours }}</span>
          </div>
        </template>
        <template #cell-assigned="{ row }">{{ row.assigned_hours || '—' }}</template>
        <template #cell-classes="{ row }"><span class="text-sm">{{ row.classes.join(', ') || '—' }}</span></template>
        <template #cell-homeroom="{ row }">{{ row.homeroom.join(', ') || '—' }}</template>
      </DataTable>
    </div>
  </div>
</template>
