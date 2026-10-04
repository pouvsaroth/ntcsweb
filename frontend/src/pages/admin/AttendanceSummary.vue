<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AttendanceTabs from '@/components/admin/AttendanceTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
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
import { exportTableAsImage } from '@/utils/tableImage'

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

// --- Filters -------------------------------------------------------------

const classes = ref<SchoolClass[]>([])
const loadingClasses = ref(true)
/**
 * Opens on every class, every day, Studying students who have missed time —
 * 'all' = every class (see AttendanceController::summaryAcrossClasses()); a
 * blank date means no limit on that side.
 */
const classId = ref<number | 'all' | null>('all')
const studentId = ref<number | null>(null)
const dateFrom = ref('')
const dateTo = ref('')

const classOptions = computed(() => [
  { value: 'all', label: t('admin.attendance.allClasses') },
  ...classes.value.map((c) => ({ value: String(c.id), label: c.name })),
])

/** Enrollment status — 'active' (Studying) by default, the summary's long-standing scope. */
const statusFilter = ref<EnrollmentStatus | 'all'>('active')
const onlyAbsent = ref(true)

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
    options.push({ value: String(row.student.id), label: row.student.name, hint: row.student.student_code ?? undefined })
  }
  // "All students" is SearchableSelect's placeholder (its × clears back to it).
  return options
})

// --- Summary rows ----------------------------------------------------------

const rows = ref<AttendanceSummaryRow[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)

/**
 * Hours missed: absent + permission (excused) + late minutes as hours — the
 * figure the "total absence" filter below compares against.
 */
function totalAbsenceHours(row: AttendanceSummaryRow): number {
  return Math.round((row.absent_hours + row.permission_hours + row.late_minutes / 60) * 10) / 10
}

/** Still to make up: missed hours − approved make-up hours, never below 0. */
function remainingHours(row: AttendanceSummaryRow): number {
  return Math.max(0, Math.round((totalAbsenceHours(row) - row.make_up_hours) * 10) / 10)
}

/** Above this many hours still to make up, the Total cell is highlighted red. */
const REMAINING_HOURS_ALERT = 6

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

/** Most hours still to make up first; ties by name. */
const filteredRows = computed(() =>
  rows.value
    .filter(
      (row) =>
        (studentId.value === null || row.student.id === studentId.value) &&
        (!onlyAbsent.value || totalAbsenceHours(row) > 0) &&
        matchesAbsence(row),
    )
    .sort((a, b) => remainingHours(b) - remainingHours(a) || a.student.name.localeCompare(b.student.name)),
)

function enrollmentLabel(row: AttendanceSummaryRow): string {
  return [row.course_package?.name, row.school_class?.name].filter(Boolean).join(' — ')
}

// --- Export as image ---------------------------------------------------------

// Ticked students (by enrollment). Exporting with any ticked exports just
// those — still in the table's order, and only ones the current filters show;
// with none ticked it exports every row on screen, as before.
const selectedIds = ref<number[]>([])
const selectedRows = computed(() => filteredRows.value.filter((row) => selectedIds.value.includes(row.enrollment_id)))
const exportRows = computed(() => (selectedRows.value.length ? selectedRows.value : filteredRows.value))

const exporting = ref(false)
const exportError = ref<string | null>(null)

/** What the image's subtitle says was included — class, student status, dates. */
function filterSummary(): string {
  const classLabel = classOptions.value.find((o) => o.value === String(classId.value))?.label ?? ''
  const statusLabel = statusOptions.value.find((o) => o.value === statusFilter.value)?.label ?? ''
  const dates =
    dateFrom.value || dateTo.value
      ? `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}`
      : t('admin.attendance.allDays')
  return [classLabel, statusLabel, dates, `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`].filter(Boolean).join('  ·  ')
}

async function exportImage() {
  exporting.value = true
  exportError.value = null
  try {
    await exportTableAsImage({
      title: t('admin.attendance.summaryTitle'),
      subtitle: filterSummary(),
      columns: [
        { label: t('admin.attendance.columnStudent'), width: 340 },
        { label: t('admin.attendance.columnMissedHours'), align: 'right', width: 130 },
        { label: t('admin.attendance.columnMakeUpHours'), align: 'right', width: 130 },
        { label: t('admin.attendance.columnRemainingHours'), align: 'right', width: 130 },
        { label: t('admin.attendance.columnPresentHours'), align: 'right', width: 130 },
      ],
      rows: exportRows.value.map((row) => [
        { text: row.student.name, subtext: enrollmentLabel(row) || undefined, bold: true },
        { text: String(totalAbsenceHours(row)) },
        { text: String(row.make_up_hours) },
        { text: String(remainingHours(row)), alert: remainingHours(row) > REMAINING_HOURS_ALERT, bold: true },
        { text: String(row.present_hours) },
      ]),
      emptyText: t('admin.attendance.summaryEmptyMessage'),
      fileName: `attendance-summary-${new Date().toISOString().slice(0, 10)}.png`,
    })
  } catch {
    exportError.value = t('admin.attendance.exportImageFailed')
  } finally {
    exporting.value = false
  }
}

const columns = computed(() => [
  { key: 'student', label: t('admin.attendance.columnStudent') },
  { key: 'missed_hours', label: t('admin.attendance.columnMissedHours'), align: 'text-right' },
  { key: 'make_up_hours', label: t('admin.attendance.columnMakeUpHours'), align: 'text-right' },
  { key: 'remaining_hours', label: t('admin.attendance.columnRemainingHours'), align: 'text-right' },
  { key: 'present_hours', label: t('admin.attendance.columnPresentHours'), align: 'text-right' },
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
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
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

/**
 * On by default each time the popup opens. "Absent" here means the days
 * behind the Absent(h) column — absent, permission and late — so the rows
 * shown add up to that figure.
 */
const detailAbsentOnly = ref(true)
const ABSENCE_STATUSES: AttendanceStatusValue[] = ['ABSENT', 'EXCUSED', 'LATE']
const visibleDetailRecords = computed(() =>
  detailAbsentOnly.value ? detailRecords.value.filter((record) => ABSENCE_STATUSES.includes(record.status)) : detailRecords.value,
)

async function openDetail(row: AttendanceSummaryRow) {
  detailRow.value = row
  detailAbsentOnly.value = true
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null

  try {
    const result = await attendanceService.list({
      page: 1,
      per_page: 200,
      sort: 'date',
      filter: {
        enrollment_id: String(row.enrollment_id),
        ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
        ...(dateTo.value ? { date_to: dateTo.value } : {}),
      },
    })
    detailRecords.value = result.data
  } catch (error) {
    detailError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    detailRecords.value = []
  } finally {
    detailLoading.value = false
  }
}

const detailExporting = ref(false)

/** The popup's records as an image — same rows as on screen (honours "absent only"). */
async function exportDetailImage() {
  const row = detailRow.value
  if (!row) return
  detailExporting.value = true
  detailError.value = null
  try {
    const dates =
      dateFrom.value || dateTo.value
        ? `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}`
        : t('admin.attendance.allDays')
    await exportTableAsImage({
      title: `${t('admin.attendance.historyTitle')} — ${row.student.name}`,
      subtitle: [
        enrollmentLabel(row),
        dates,
        detailAbsentOnly.value ? t('admin.attendance.detailAbsentOnly') : '',
        `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`,
      ]
        .filter(Boolean)
        .join('  ·  '),
      columns: [
        { label: t('admin.attendance.columnDate'), width: 130 },
        { label: t('admin.attendance.columnClass'), width: 220, maxWidth: 320 },
        { label: t('admin.attendance.columnHours'), align: 'right', width: 80 },
        { label: t('admin.attendance.columnStatus'), width: 130 },
        { label: t('admin.attendance.columnLateMinutes'), width: 110 },
        { label: t('admin.attendance.columnRemarks'), width: 240, maxWidth: 420 },
      ],
      rows: visibleDetailRecords.value.map((record) => [
        { text: formatDate(record.date) },
        { text: record.class?.name ?? '—' },
        { text: record.class?.hours != null ? String(record.class.hours) : '—' },
        { text: statusLabel(record.status), badge: statusVariant[record.status] },
        { text: record.late_minutes != null ? String(record.late_minutes) : '—' },
        { text: record.remarks ?? '—' },
      ]),
      emptyText: t('admin.attendance.summaryEmptyMessage'),
      fileName: `attendance-${row.student.name.replace(/[\\/:*?"<>|\s]+/g, '-')}-${new Date().toISOString().slice(0, 10)}.png`,
    })
  } catch {
    detailError.value = t('admin.attendance.exportImageFailed')
  } finally {
    detailExporting.value = false
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
    // The watcher below reloads on this change.
    classId.value = classIdFromQuery
  } else {
    await loadSummary()
  }
})
</script>

<template>
  <div>
    <AttendanceTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.attendance.summaryTitle') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.attendance.summarySubtitle') }}</p>
      </div>
      <BaseButton variant="outline" :loading="exporting" :disabled="loading || !classId" @click="exportImage">
        {{ t('admin.attendance.exportImage') }}<template v-if="selectedRows.length"> ({{ selectedRows.length }})</template>
      </BaseButton>
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
        <SearchableSelect
          :model-value="studentId !== null ? String(studentId) : ''"
          :options="studentOptions"
          :disabled="!classId"
          :placeholder="t('admin.attendance.allStudents')"
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
    <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>

    <template v-if="classId">
      <DataTable
        :columns="columns"
        :rows="filteredRows"
        row-key="enrollment_id"
        selectable
        :selected="selectedIds"
        :loading="loading"
        @update:selected="selectedIds = $event as number[]"
        :empty-message="t('admin.attendance.summaryEmptyMessage')"
      >
        <template #cell-student="{ row }">
          <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(row)">
            {{ row.student.name }}
          </button>
          <!-- Which enrollment this row is — one student can be in several classes/courses. -->
          <p class="text-xs text-neutral-400">{{ enrollmentLabel(row) || '—' }}</p>
        </template>
        <template #cell-missed_hours="{ row }">{{ totalAbsenceHours(row) }}</template>
        <template #cell-make_up_hours="{ row }">{{ row.make_up_hours }}</template>
        <template #cell-remaining_hours="{ row }">
          <span
            class="inline-block rounded px-2 py-0.5 font-semibold"
            :class="remainingHours(row) > REMAINING_HOURS_ALERT ? 'bg-danger-600 text-white' : 'text-neutral-800'"
          >
            {{ remainingHours(row) }}
          </span>
        </template>
      </DataTable>
    </template>
    <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.pickClassPrompt') }}</p>

    <BaseModal
      v-model="detailOpen"
      :title="detailRow ? `${t('admin.attendance.historyTitle')} — ${detailRow.student.name}` : undefined"
      size="lg"
    >
      <BaseAlert v-if="detailError" variant="danger" class="mb-4">{{ detailError }}</BaseAlert>

      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="detailAbsentOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.attendance.detailAbsentOnly') }}
        </label>
        <BaseButton variant="outline" size="sm" :loading="detailExporting" :disabled="detailLoading" @click="exportDetailImage">
          {{ t('admin.attendance.exportImage') }}
        </BaseButton>
      </div>

      <div v-if="detailLoading" class="py-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}</div>

      <table v-else-if="visibleDetailRecords.length > 0" class="w-full text-left text-sm">
        <thead class="border-b border-neutral-200 text-neutral-500">
          <tr>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnDate') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnClass') }}</th>
            <th class="py-2 pr-3 text-right font-medium">{{ t('admin.attendance.columnHours') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnStatus') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('admin.attendance.columnLateMinutes') }}</th>
            <th class="py-2 font-medium">{{ t('admin.attendance.columnRemarks') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="record in visibleDetailRecords" :key="record.id">
            <td class="py-2 pr-3 text-neutral-700">{{ formatDate(record.date) }}</td>
            <td class="py-2 pr-3 text-neutral-700">{{ record.class?.name ?? '—' }}</td>
            <td class="py-2 pr-3 text-right tabular-nums text-neutral-700">{{ record.class?.hours ?? '—' }}</td>
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
