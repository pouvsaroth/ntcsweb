<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AttendanceTabs from '@/components/admin/AttendanceTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { attendanceService, type AttendanceSheet, type AttendanceSheetQuery, type AttendanceSheetTotals } from '@/services/attendance'
import { classesService, type SchoolClass } from '@/services/classes'
import { enrollmentStatusesManageable, type EnrollmentStatus } from '@/services/enrollments'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { exportTableAsImage, type TableImageCell, type TableImageTone } from '@/utils/tableImage'

/**
 * Student Attendance > Attendance Sheet: one class over a date range, one
 * row per student and one column per day attendance was taken. Marks:
 * ✓ present, A absent, P permission, the late minutes when late — then
 * totals. Exports to Excel / PDF (built on the server) and image (drawn here).
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const canExport = computed(() => auth.can('attendance.view'))

// --- Filters -------------------------------------------------------------

const classes = ref<SchoolClass[]>([])
const loadingClasses = ref(true)
const classId = ref<number | null>(null)

/** Local yyyy-mm-dd (not UTC, which can be a day off in Cambodia). */
function isoDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
const today = new Date()
const dateFrom = ref(isoDate(new Date(today.getFullYear(), today.getMonth(), 1)))
const dateTo = ref(isoDate(today))

/** 'active' (Studying) by default, like the Summary tab. */
const statusFilter = ref<EnrollmentStatus | 'all'>('active')

function enrollmentStatusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const classOptions = computed(() => classes.value.map((c) => ({ value: String(c.id), label: c.name })))
const statusOptions = computed(() => [
  { value: 'all', label: t('admin.attendance.allStudentStatuses') },
  ...enrollmentStatusesManageable.map((status) => ({ value: status, label: t(`admin.enrollments.status${enrollmentStatusKey(status)}`) })),
])

const rangeTooLong = computed(() => {
  if (!dateFrom.value || !dateTo.value) return false
  return (new Date(dateTo.value).getTime() - new Date(dateFrom.value).getTime()) / 86_400_000 > 366
})
const rangeBackwards = computed(() => Boolean(dateFrom.value && dateTo.value && dateTo.value < dateFrom.value))
const ready = computed(() => classId.value !== null && Boolean(dateFrom.value && dateTo.value) && !rangeTooLong.value && !rangeBackwards.value)

const query = computed<AttendanceSheetQuery>(() => ({
  date_from: dateFrom.value,
  date_to: dateTo.value,
  ...(statusFilter.value === 'active' ? {} : { status: statusFilter.value }),
}))

// --- Sheet ---------------------------------------------------------------

const sheet = ref<AttendanceSheet | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

let requestId = 0
async function load(): Promise<void> {
  if (!ready.value || classId.value === null) {
    sheet.value = null
    return
  }
  const id = ++requestId
  loading.value = true
  error.value = null
  try {
    const result = await attendanceService.sheet(classId.value, query.value)
    if (id === requestId) sheet.value = result
  } catch (e) {
    if (id === requestId) error.value = e instanceof ApiRequestError ? e.message : t('admin.attendance.loadFailed')
  } finally {
    if (id === requestId) loading.value = false
  }
}

watch([classId, dateFrom, dateTo, statusFilter], load)

/** dd-mm for the day column headers (the year is in the period above). */
function dayLabel(date: string): string {
  const [, month, day] = date.split('-')
  return `${day}-${month}`
}

type MarkKind = 'present' | 'absent' | 'permission' | 'late' | 'none'
function markKind(mark: string | undefined): MarkKind {
  if (!mark) return 'none'
  if (mark === '✓') return 'present'
  if (mark === 'A') return 'absent'
  if (mark === 'P') return 'permission'
  return 'late'
}
const markClass: Record<MarkKind, string> = {
  present: 'text-green-700',
  absent: 'bg-red-50 font-semibold text-red-700',
  permission: 'bg-blue-50 font-semibold text-blue-700',
  late: 'bg-amber-50 font-semibold text-amber-800',
  none: 'text-neutral-300',
}
const markTone: Record<Exclude<MarkKind, 'none'>, TableImageTone> = {
  present: 'success',
  absent: 'danger',
  permission: 'info',
  late: 'warning',
}

const totalColumns = computed<{ key: keyof AttendanceSheetTotals; label: string }[]>(() => [
  { key: 'present', label: t('admin.attendance.sheetTotalPresent') },
  { key: 'late', label: t('admin.attendance.sheetTotalLate') },
  { key: 'absent', label: t('admin.attendance.sheetTotalAbsent') },
  { key: 'permission', label: t('admin.attendance.sheetTotalPermission') },
  { key: 'late_minutes', label: t('admin.attendance.sheetTotalLateMinutes') },
])

const period = computed(() => (sheet.value ? `${formatDate(sheet.value.date_from)} – ${formatDate(sheet.value.date_to)}` : ''))

// --- Export --------------------------------------------------------------

const exporting = ref<'excel' | 'pdf' | 'image' | null>(null)
const exportError = ref<string | null>(null)

function fileName(extension: string): string {
  const slug = (sheet.value?.class.name ?? 'class').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'class'
  return `attendance-${slug}-${dateFrom.value}-to-${dateTo.value}.${extension}`
}

async function download(format: 'excel' | 'pdf'): Promise<void> {
  if (classId.value === null) return
  exporting.value = format
  exportError.value = null
  try {
    await attendanceService.downloadSheet(classId.value, format, query.value, fileName(format === 'excel' ? 'xlsx' : 'pdf'))
  } catch (e) {
    exportError.value = e instanceof ApiRequestError ? e.message : t('admin.attendance.exportFailed')
  } finally {
    exporting.value = null
  }
}

async function exportImage(): Promise<void> {
  const data = sheet.value
  if (!data) return
  exporting.value = 'image'
  exportError.value = null
  try {
    await exportTableAsImage({
      title: `${t('admin.attendance.sheetTab')} — ${data.class.name}`,
      subtitle: [period.value, t('admin.attendance.sheetLegend'), `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`].join('  ·  '),
      columns: [
        { label: '#', width: 36, align: 'center' },
        { label: t('admin.attendance.columnStudent'), width: 160, maxWidth: 240 },
        ...data.dates.map((date) => ({ label: dayLabel(date), width: 48, align: 'center' as const })),
        ...totalColumns.value.map((column) => ({ label: column.label, width: 60, align: 'center' as const })),
      ],
      rows: data.students.map((row, index) => [
        { text: String(index + 1) },
        { text: row.student.name, subtext: row.student.student_code ?? undefined },
        ...data.dates.map((date): TableImageCell => {
          const mark = row.marks[date]
          const kind = markKind(mark)
          return kind === 'none' ? { text: '' } : { text: mark ?? '', badge: markTone[kind] }
        }),
        ...totalColumns.value.map((column) => ({ text: String(row.totals[column.key]), bold: true })),
      ]),
      emptyText: t('admin.attendance.sheetNoDays'),
      fileName: fileName('png'),
    })
  } catch {
    exportError.value = t('admin.attendance.exportImageFailed')
  } finally {
    exporting.value = null
  }
}

const canDownload = computed(() => ready.value && !loading.value && sheet.value !== null && sheet.value.students.length > 0)

onMounted(async () => {
  try {
    classes.value = await classesService.listAll()
  } finally {
    loadingClasses.value = false
  }
  const fromQuery = Number(route.query.class_id)
  if (Number.isInteger(fromQuery) && classes.value.some((c) => c.id === fromQuery)) classId.value = fromQuery
})
</script>

<template>
  <div>
    <AttendanceTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.attendance.sheetTab') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.attendance.sheetSubtitle') }}</p>
      </div>
      <div v-if="canExport" class="flex flex-wrap gap-2">
        <BaseButton variant="outline" :loading="exporting === 'excel'" :disabled="!canDownload || exporting !== null" @click="download('excel')">
          {{ t('admin.attendance.exportExcel') }}
        </BaseButton>
        <BaseButton variant="outline" :loading="exporting === 'pdf'" :disabled="!canDownload || exporting !== null" @click="download('pdf')">
          {{ t('admin.attendance.exportPdf') }}
        </BaseButton>
        <BaseButton variant="outline" :loading="exporting === 'image'" :disabled="!canDownload || exporting !== null" @click="exportImage">
          {{ t('admin.attendance.exportImage') }}
        </BaseButton>
      </div>
    </div>

    <div class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-5">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SearchableSelect
          :model-value="classId !== null ? String(classId) : ''"
          :options="classOptions"
          :disabled="loadingClasses"
          :placeholder="t('admin.attendance.selectClass')"
          :label="t('admin.attendance.class')"
          @update:model-value="classId = $event ? Number($event) : null"
        />
        <BaseInput
          v-model="dateFrom"
          type="date"
          :label="t('admin.attendance.dateFrom')"
        />
        <BaseInput
          v-model="dateTo"
          type="date"
          :label="t('admin.attendance.dateTo')"
          :error="rangeTooLong ? t('admin.attendance.sheetRangeTooLong') : rangeBackwards ? t('admin.attendance.sheetDateOrder') : undefined"
        />
        <BaseSelect
          :model-value="statusFilter"
          :options="statusOptions"
          :label="t('admin.attendance.filterStudentStatus')"
          @update:model-value="statusFilter = ($event || 'active') as EnrollmentStatus | 'all'"
        />
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>

    <p v-if="!ready && !rangeTooLong && !rangeBackwards" class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.sheetPickPrompt') }}</p>
    <div v-else-if="loading && !sheet" class="py-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}</div>

    <template v-else-if="sheet && ready">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
        <p class="text-neutral-600">
          <span class="font-medium text-neutral-900">{{ sheet.class.name }}</span> · {{ period }} ·
          {{ t('admin.attendance.sheetClassDays', { count: sheet.dates.length }) }} ·
          {{ t('admin.attendance.sheetStudents', { count: sheet.students.length }) }}
        </p>
        <p class="text-neutral-500">{{ t('admin.attendance.sheetLegend') }}</p>
      </div>

      <p v-if="sheet.students.length === 0 || sheet.dates.length === 0" class="py-8 text-center text-sm text-neutral-400">
        {{ sheet.students.length === 0 ? t('admin.attendance.noStudents') : t('admin.attendance.sheetNoDays') }}
      </p>

      <template v-else>
        <!-- Phone: one card per student — totals, then each day's mark. -->
        <div class="space-y-3 sm:hidden" :class="{ 'opacity-60': loading }">
          <div
            v-for="(row, index) in sheet.students"
            :key="row.enrollment_id"
            class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]"
          >
            <p class="text-sm font-semibold text-neutral-900">{{ index + 1 }}. {{ row.student.name }}</p>
            <p v-if="row.student.student_code" class="text-xs text-neutral-400">{{ row.student.student_code }}</p>
            <dl class="mt-3 grid grid-cols-5 gap-1 border-t border-neutral-100 pt-3 text-center">
              <div v-for="column in totalColumns" :key="column.key">
                <dt class="text-[11px] leading-tight text-neutral-500">{{ column.label }}</dt>
                <dd class="text-sm font-semibold tabular-nums text-neutral-800">{{ row.totals[column.key] }}</dd>
              </div>
            </dl>
            <div class="mt-3 flex flex-wrap gap-1.5 border-t border-neutral-100 pt-3">
              <div
                v-for="date in sheet.dates"
                :key="date"
                class="w-12 rounded border border-neutral-200 text-center"
              >
                <div class="border-b border-neutral-100 text-[10px] text-neutral-500">{{ dayLabel(date) }}</div>
                <div class="py-0.5 text-sm" :class="markClass[markKind(row.marks[date])]">{{ row.marks[date] ?? '–' }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Wider screens: the sheet itself, scrolling sideways with No. and Student kept in view. -->
        <div
          class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block"
          :class="{ 'opacity-60': loading }"
        >
          <table class="min-w-full border-collapse text-sm">
            <thead>
              <tr class="bg-neutral-50 text-xs font-semibold text-neutral-600">
                <th class="sticky left-0 z-10 w-10 border-b border-r border-neutral-200 bg-neutral-50 px-2 py-2 text-center">#</th>
                <th class="sticky left-10 z-10 min-w-44 border-b border-r border-neutral-200 bg-neutral-50 px-3 py-2 text-left">
                  {{ t('admin.attendance.columnStudent') }}
                </th>
                <th v-for="date in sheet.dates" :key="date" class="min-w-12 whitespace-nowrap border-b border-r border-neutral-200 px-1.5 py-2 text-center tabular-nums">
                  {{ dayLabel(date) }}
                </th>
                <th
                  v-for="column in totalColumns"
                  :key="column.key"
                  class="border-b border-r border-neutral-200 bg-neutral-100 px-2 py-2 text-center whitespace-nowrap last:border-r-0"
                >
                  {{ column.label }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in sheet.students" :key="row.enrollment_id" class="even:bg-neutral-50/60">
                <td class="sticky left-0 z-10 border-b border-r border-neutral-200 bg-white px-2 py-1.5 text-center text-neutral-500 tabular-nums">
                  {{ index + 1 }}
                </td>
                <td class="sticky left-10 z-10 border-b border-r border-neutral-200 bg-white px-3 py-1.5">
                  <p class="font-medium text-neutral-900">{{ row.student.name }}</p>
                  <p v-if="row.student.student_code" class="text-xs text-neutral-400">{{ row.student.student_code }}</p>
                </td>
                <td
                  v-for="date in sheet.dates"
                  :key="date"
                  class="border-b border-r border-neutral-200 px-1 py-1.5 text-center tabular-nums"
                  :class="markClass[markKind(row.marks[date])]"
                >
                  {{ row.marks[date] ?? '' }}
                </td>
                <td
                  v-for="column in totalColumns"
                  :key="column.key"
                  class="border-b border-r border-neutral-200 bg-neutral-50 px-2 py-1.5 text-center font-semibold tabular-nums text-neutral-800 last:border-r-0"
                >
                  {{ row.totals[column.key] }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
