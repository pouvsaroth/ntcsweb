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
import { enrollmentStatusesManageable, type EnrollmentStatus } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

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
/** 'all' = every class (see AttendanceController::summaryAcrossClasses()). */
const classId = ref<number | 'all' | null>(null)
const studentId = ref<number | null>(null)
const dateFrom = ref(firstOfThisMonth())
const dateTo = ref(today())

const classOptions = computed(() => [
  { value: 'all', label: t('admin.attendance.allClasses') },
  ...classes.value.map((c) => ({ value: String(c.id), label: c.name })),
])

/** Enrollment status — 'active' (Studying) by default, the summary's long-standing scope. */
const statusFilter = ref<EnrollmentStatus | 'all'>('active')
const onlyAbsent = ref(false)

function enrollmentStatusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusOptions = computed(() => [
  { value: 'all', label: t('admin.attendance.allStudentStatuses') },
  ...enrollmentStatusesManageable.map((status) => ({ value: status, label: t(`admin.enrollments.status${enrollmentStatusKey(status)}`) })),
])
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

/**
 * Total hours missed: absent + permission (excused) + late minutes as hours
 * — the figure the "total absence" filter below compares against.
 */
function totalAbsenceHours(row: AttendanceSummaryRow): number {
  return Math.round((row.absent_hours + row.permission_hours + row.late_minutes / 60) * 10) / 10
}

/** e.g. ">=6", "<6", "= 4.5", or a bare "6" (meaning 6 hours or more). */
const absenceHoursFilter = ref('')

type Comparison = { op: '<' | '<=' | '>' | '>=' | '='; value: number }

const absenceComparison = computed<Comparison | null | 'invalid'>(() => {
  const text = absenceHoursFilter.value.trim()
  if (text === '') return null
  const match = /^(<=|>=|<|>|=)?\s*(\d+(?:\.\d+)?)$/.exec(text)
  if (!match) return 'invalid'
  return { op: (match[1] ?? '>=') as Comparison['op'], value: Number(match[2]) }
})

function matchesAbsence(row: AttendanceSummaryRow): boolean {
  const comparison = absenceComparison.value
  if (comparison === null || comparison === 'invalid') return true
  const hours = totalAbsenceHours(row)
  switch (comparison.op) {
    case '<':
      return hours < comparison.value
    case '<=':
      return hours <= comparison.value
    case '>':
      return hours > comparison.value
    case '=':
      return hours === comparison.value
    default:
      return hours >= comparison.value
  }
}

const filteredRows = computed(() =>
  rows.value.filter(
    (row) =>
      (studentId.value === null || row.student.id === studentId.value) &&
      (!onlyAbsent.value || row.absent_days > 0) &&
      matchesAbsence(row),
  ),
)

const columns = computed(() => [
  { key: 'student', label: t('admin.attendance.columnStudent') },
  // Only needed when rows come from more than one class.
  ...(classId.value === 'all' ? [{ key: 'school_class', label: t('admin.attendance.columnClass') }] : []),
  { key: 'course', label: t('admin.attendance.columnCourse') },
  { key: 'present_days', label: t('admin.attendance.columnPresentDays'), align: 'text-right' },
  { key: 'present_hours', label: t('admin.attendance.columnPresentHours'), align: 'text-right' },
  { key: 'permission_days', label: t('admin.attendance.columnPermissionDays'), align: 'text-right' },
  { key: 'permission_hours', label: t('admin.attendance.columnPermissionHours'), align: 'text-right' },
  { key: 'absent_days', label: t('admin.attendance.columnAbsentDays'), align: 'text-right' },
  { key: 'absent_hours', label: t('admin.attendance.columnAbsentHours'), align: 'text-right' },
  { key: 'late_minutes', label: t('admin.attendance.columnLateMinutes'), align: 'text-right' },
  { key: 'total_absence_hours', label: t('admin.attendance.columnTotalAbsenceHours'), align: 'text-right' },
])

async function loadSummary() {
  if (!classId.value) {
    rows.value = []
    return
  }

  loading.value = true
  loadError.value = null
  try {
    rows.value = await attendanceService.summary({
      class_id: classId.value === 'all' ? undefined : classId.value,
      date_from: dateFrom.value,
      date_to: dateTo.value,
      status: statusFilter.value,
    })
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch([classId, dateFrom, dateTo, statusFilter], () => {
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
      filter: { enrollment_id: String(row.enrollment_id), date_from: dateFrom.value, date_to: dateTo.value },
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
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <BaseSelect
          :model-value="classId !== null ? String(classId) : ''"
          :options="classOptions"
          :disabled="loadingClasses"
          :placeholder="t('admin.attendance.selectClass')"
          :label="t('admin.attendance.class')"
          @update:model-value="classId = $event === 'all' ? 'all' : $event ? Number($event) : null"
        />
        <BaseSelect
          :model-value="statusFilter"
          :options="statusOptions"
          :label="t('admin.attendance.filterStudentStatus')"
          @update:model-value="statusFilter = ($event || 'active') as EnrollmentStatus | 'all'"
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
      <div class="mt-4 grid grid-cols-1 items-start gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <BaseInput
          v-model="absenceHoursFilter"
          :label="t('admin.attendance.totalAbsenceFilter')"
          :placeholder="t('admin.attendance.totalAbsenceFilterPlaceholder')"
          :hint="absenceComparison === 'invalid' ? undefined : t('admin.attendance.totalAbsenceFilterHint')"
          :error="absenceComparison === 'invalid' ? t('admin.attendance.totalAbsenceFilterInvalid') : undefined"
        />
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700 sm:mt-8">
          <input v-model="onlyAbsent" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.attendance.onlyAbsent') }}
        </label>
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
        <template #cell-total_absence_hours="{ row }">
          <span class="font-medium" :class="totalAbsenceHours(row) > 0 ? 'text-danger-600' : 'text-neutral-700'">{{ totalAbsenceHours(row) }}</span>
        </template>
        <template #cell-school_class="{ row }">{{ row.school_class?.name ?? '—' }}</template>
        <template #cell-course="{ row }">{{ row.course_package?.name ?? '—' }}</template>
      </DataTable>
    </template>
    <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.pickClassPrompt') }}</p>

    <BaseModal
      v-model="detailOpen"
      :title="detailRow ? `${t('admin.attendance.historyTitle')} — ${detailRow.student.name}` : undefined"
      size="lg"
    >
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
            <td class="py-2 pr-3 text-neutral-700">{{ formatDate(record.date) }}</td>
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
