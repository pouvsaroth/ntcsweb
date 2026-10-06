<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { departmentsService, type Department } from '@/services/departments'
import { attendanceReportService, clock, thisMonthRange, type ReportItem, type ReportSummary, type ReportType } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Late / Early leave / Absence — one page, the
 * route says which (`type`). Who, how often and how many minutes over a
 * date range, then every occurrence. Phone-friendly lists rather than a
 * wide table.
 */
const props = defineProps<{ type: Exclude<ReportType, 'overtime'> }>()

const { t } = useI18n()

const range = thisMonthRange()
const from = ref(range.from)
const to = ref(range.to)
const departmentId = ref('')
const search = ref('')
const items = ref<ReportItem[]>([])
const summary = ref<ReportSummary[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

const departments = ref<Department[]>([])
const departmentOptions = computed(() => [
  { value: '', label: t('admin.timeAttendance.allDepartments') },
  ...departments.value.map((d) => ({ value: String(d.id), label: d.name })),
])

const counted = computed(() => props.type !== 'absence')
const tone = computed(() => ({ late: 'text-amber-700', early_leave: 'text-orange-700', absence: 'text-red-700' })[props.type])

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await attendanceReportService.get({
      type: props.type,
      from: from.value,
      to: to.value,
      ...(departmentId.value ? { department_id: Number(departmentId.value) } : {}),
      ...(search.value.trim() ? { search: search.value.trim() } : {}),
    })
    items.value = result.items
    summary.value = result.summary
    total.value = result.total
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.loadFailed')
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
function onSearch(value: string) {
  search.value = value
  clearTimeout(timer)
  timer = setTimeout(() => void load(), 350)
}

onMounted(async () => {
  void load()
  departments.value = await departmentsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseInput v-model="from" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.from')" @update:model-value="load" />
      <BaseInput v-model="to" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.to')" @update:model-value="load" />
      <BaseSelect class="w-48" :model-value="departmentId" :options="departmentOptions" @update:model-value="(v: string) => { departmentId = v; load() }" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block h-[38px] w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="total === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t(`admin.timeAttendance.reports.${type}.empty`) }}
    </p>

    <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
      <!-- Per person -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white">
        <h2 class="border-b border-neutral-100 px-4 py-2 text-sm font-semibold text-neutral-800">
          {{ t('admin.timeAttendance.reports.byPerson') }} <span class="font-normal text-neutral-500">({{ summary.length }})</span>
        </h2>
        <ul class="divide-y divide-neutral-100">
          <li v-for="person in summary" :key="person.staff_id" class="flex items-center justify-between gap-3 px-4 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-800">{{ person.name }}</p>
              <p class="text-xs text-neutral-500">{{ person.employee_code }}</p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-sm font-semibold" :class="tone">{{ t(`admin.timeAttendance.reports.${type}.times`, { count: person.count }) }}</p>
              <p v-if="counted" class="text-xs text-neutral-500">{{ formatMinutes(person.minutes) }}</p>
            </div>
          </li>
        </ul>
      </section>

      <!-- Every occurrence -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white">
        <h2 class="border-b border-neutral-100 px-4 py-2 text-sm font-semibold text-neutral-800">
          {{ t('admin.timeAttendance.reports.occurrences') }} <span class="font-normal text-neutral-500">({{ total }})</span>
        </h2>
        <ul class="divide-y divide-neutral-100">
          <li v-for="item in items" :key="`${item.staff.id}-${item.date}`" class="flex items-center justify-between gap-3 px-4 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-800">{{ item.staff.name }}</p>
              <p class="text-xs text-neutral-500">
                {{ formatDate(item.date) }}<template v-if="item.shift"> · {{ item.shift.name }}</template>
                <template v-if="item.check_in_at"> · {{ clock(item.check_in_at) }} – {{ clock(item.check_out_at) }}</template>
              </p>
            </div>
            <p class="shrink-0 text-sm font-semibold" :class="tone">
              {{ counted ? `${item.minutes} min` : t('admin.timeAttendance.statuses.absent') }}
            </p>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
