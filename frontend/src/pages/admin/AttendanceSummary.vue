<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AttendanceTabs from '@/components/admin/AttendanceTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import DataTable from '@/components/ui/DataTable.vue'
import {
  attendanceService,
  type AttendanceRecord,
  type AttendanceStatusValue,
  type AttendanceSummaryRow,
} from '@/services/attendance'
import { classesService, type SchoolClass } from '@/services/classes'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()

const statusVariant: Record<AttendanceStatusValue, 'success' | 'danger' | 'warning' | 'neutral'> = {
  PRESENT: 'success',
  ABSENT: 'danger',
  LATE: 'warning',
  EXCUSED: 'neutral',
}

function statusLabel(status: AttendanceStatusValue): string {
  return t(`admin.attendance.status${status.charAt(0)}${status.slice(1).toLowerCase()}`)
}

/** yyyy-MM-dd, local time. */
function toDateInput(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function firstOfThisMonth(): string {
  const now = new Date()
  return toDateInput(new Date(now.getFullYear(), now.getMonth(), 1))
}

function today(): string {
  return toDateInput(new Date())
}

// --- Filters -------------------------------------------------------------

const classes = ref<SchoolClass[]>([])
const loadingClasses = ref(true)
const classId = ref<number | null>(null)
const studentId = ref<number | null>(null)
const dateFrom = ref(firstOfThisMonth())
const dateTo = ref(today())

const classOptions = computed(() => classes.value.map((c) => ({ value: String(c.id), label: c.name })))
const studentOptions = computed(() => {
  const seen = new Set<number>()
  const options = []
  for (const row of rows.value) {
    if (seen.has(row.student.id)) continue
    seen.add(row.student.id)
    options.push({ value: String(row.student.id), label: row.student.name })
  }
  return [{ value: '', label: t('admin.attendance.allStudents') }, ...options]
})

// --- Summary rows ----------------------------------------------------------

const rows = ref<AttendanceSummaryRow[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)

const filteredRows = computed(() =>
  studentId.value === null ? rows.value : rows.value.filter((row) => row.student.id === studentId.value),
)

const columns = [
  { key: 'student', label: t('admin.attendance.columnStudent') },
  { key: 'course', label: t('admin.attendance.columnCourse') },
  { key: 'present_days', label: t('admin.attendance.columnPresentDays'), align: 'text-right' },
  { key: 'present_hours', label: t('admin.attendance.columnPresentHours'), align: 'text-right' },
  { key: 'permission_days', label: t('admin.attendance.columnPermissionDays'), align: 'text-right' },
  { key: 'permission_hours', label: t('admin.attendance.columnPermissionHours'), align: 'text-right' },
  { key: 'absent_days', label: t('admin.attendance.columnAbsentDays'), align: 'text-right' },
  { key: 'absent_hours', label: t('admin.attendance.columnAbsentHours'), align: 'text-right' },
  { key: 'late_minutes', label: t('admin.attendance.columnLateMinutes'), align: 'text-right' },
]

async function loadSummary() {
  if (!classId.value) {
    rows.value = []
    return
  }

  loading.value = true
  loadError.value = null
  try {
    rows.value = await attendanceService.summary(classId.value, { date_from: dateFrom.value, date_to: dateTo.value })
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch([classId, dateFrom, dateTo], () => {
  studentId.value = null
  void loadSummary()
})

// --- Detail drill-down -----------------------------------------------------

const detailOpen = ref(false)
const detailRow = ref<AttendanceSummaryRow | null>(null)
const detailRecords = ref<AttendanceRecord[]>([])
const detailLoading = ref(false)
const detailError = ref<string | null>(null)

async function openDetail(row: AttendanceSummaryRow) {
  detailRow.value = row
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null

  try {
    const result = await attendanceService.list({
      page: 1,
      per_page: 200,
      sort: 'date',
      filter: { enrollment_id: row.enrollment_id, date_from: dateFrom.value, date_to: dateTo.value },
    })
    detailRecords.value = result.data
  } catch (error) {
    detailError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    detailRecords.value = []
  } finally {
    detailLoading.value = false
  }
}

onMounted(async () => {
  loadingClasses.value = true
  try {
    classes.value = await classesService.listAll()
  } finally {
    loadingClasses.value = false
  }

  const classIdFromQuery = Number(route.query.class_id)
  if (Number.isInteger(classIdFromQuery) && classes.value.some((c) => c.id === classIdFromQuery)) {
    classId.value = classIdFromQuery
    await loadSummary()
  }
})
</script>

<template>
  <div>
    <AttendanceTabs />

    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.attendance.summaryTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.attendance.summarySubtitle') }}</p>
    </div>

    <div class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-5">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseSelect
          :model-value="classId !== null ? String(classId) : ''"
          :options="classOptions"
          :disabled="loadingClasses"
          :placeholder="t('admin.attendance.selectClass')"
          :label="t('admin.attendance.class')"
          @update:model-value="classId = $event ? Number($event) : null"
        />
        <BaseSelect
          :model-value="studentId !== null ? String(studentId) : ''"
          :options="studentOptions"
          :disabled="!classId"
          :label="t('admin.attendance.filterStudent')"
          @update:model-value="studentId = $event ? Number($event) : null"
        />
        <BaseInput v-model="dateFrom" type="date" :label="t('admin.attendance.dateFrom')" />
        <BaseInput v-model="dateTo" type="date" :label="t('admin.attendance.dateTo')" />
      </div>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <template v-if="classId">
      <DataTable
        :columns="columns"
        :rows="filteredRows"
        row-key="enrollment_id"
        :loading="loading"
        :empty-message="t('admin.attendance.summaryEmptyMessage')"
      >
        <template #cell-student="{ row }">
          <button type="button" class="font-medium text-primary-700 hover:underline" @click="openDetail(row)">
            {{ row.student.name }}
          </button>
        </template>
        <template #cell-course="{ row }">{{ row.course_package?.name ?? '—' }}</template>
      </DataTable>
    </template>
    <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.pickClassPrompt') }}</p>

    <BaseModal v-model="detailOpen" :title="detailRow?.student.name" size="lg">
      <BaseAlert v-if="detailError" variant="danger" class="mb-4">{{ detailError }}</BaseAlert>

      <div v-if="detailLoading" class="py-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}</div>

      <table v-else-if="detailRecords.length > 0" class="w-full text-left text-sm">
        <thead class="border-b border-neutral-200 text-neutral-500">
          <tr>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnDate') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnStatus') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnLateMinutes') }}</th>
            <th class="py-2 font-medium">{{ t('admin.attendance.columnRemarks') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="record in detailRecords" :key="record.id">
            <td class="py-2 pr-3 text-neutral-700">{{ record.date }}</td>
            <td class="py-2 pr-3">
              <BaseBadge :variant="statusVariant[record.status]">{{ statusLabel(record.status) }}</BaseBadge>
            </td>
            <td class="py-2 pr-3 text-neutral-700">{{ record.late_minutes ?? '—' }}</td>
            <td class="py-2 text-neutral-700">{{ record.remarks ?? '—' }}</td>
          </tr>
        </tbody>
      </table>

      <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.summaryEmptyMessage') }}</p>
    </BaseModal>
  </div>
</template>
