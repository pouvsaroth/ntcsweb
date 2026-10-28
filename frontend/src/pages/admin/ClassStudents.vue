<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AssignStudentsModal from '@/components/admin/AssignStudentsModal.vue'
import ChangeTableModal from '@/components/admin/ChangeTableModal.vue'
import ClassNotesModal from '@/components/admin/ClassNotesModal.vue'
import EnrollmentStatusModal from '@/components/admin/EnrollmentStatusModal.vue'
import EnrollmentTransferModal from '@/components/admin/EnrollmentTransferModal.vue'
import ResponsibleStaffModal from '@/components/admin/ResponsibleStaffModal.vue'
import SendToExamModal from '@/components/admin/SendToExamModal.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { classesService, type SchoolClass } from '@/services/classes'
import { enrollmentsService, type Enrollment, type EnrollmentStatus } from '@/services/enrollments'
import { type LookupOption, lookupsService } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// A role built from Classes + Attendance alone (see EnrollmentPolicy's own
// docblock) can reach this page and its roster, but never granted
// enrollments.transfer/.change-status — hide the row actions those would
// otherwise 403 on, rather than let a teacher discover that on submit.
// enrollments.change-table is deliberately separate from transfer: a
// Teacher can be granted just the seat-only action without also getting
// the ability to move a student to a different class or course.
const canTransfer = computed(() => auth.can('enrollments.transfer'))
const canChangeStatus = computed(() => auth.can('enrollments.change-status'))
const canChangeTable = computed(() => auth.can('enrollments.change-table'))
const canSendToExam = computed(() => auth.can('exam-applications.create'))
const canNote = computed(() => auth.can('enrollments.note'))
const canResponsibleStaff = computed(() => auth.can('enrollments.responsible-staff'))
// "Change class and table" already covers a seat change, so the seat-only
// icon shows just for users who can change tables but not classes.
const showChangeTable = computed(() => canChangeTable.value && !canTransfer.value)
const hasRowActions = computed(() => canTransfer.value || canChangeStatus.value || canChangeTable.value)

/**
 * Reached two ways: the single-class route `/admin/classes/:id/students`
 * (route param), or the "View" button on Classes.vue after checking several
 * classes, which lands on `/admin/classes/students?class_ids=1,2,3` (query
 * string, no `:id`). Everything below branches on `classIds.length` rather
 * than on which route matched — one class via either path renders identically.
 */
const classIds = computed<number[]>(() => {
  if (route.params.id) return [Number(route.params.id)]

  const raw = route.query.class_ids
  const csv = typeof raw === 'string' ? raw : ''
  return csv
    .split(',')
    .map((s) => Number(s.trim()))
    .filter((n) => Number.isInteger(n) && n > 0)
})

const isSingleClass = computed(() => classIds.value.length === 1)

/** Only fetched (and only shown) in single-class mode — see isSingleClass. */
const schoolClass = ref<SchoolClass | null>(null)

// Class assignment with no course package (Grade 1–12), single class only.
const canAssign = computed(() => auth.can('enrollments.create'))
const assignOpen = ref(false)
const assignNotice = ref<string | null>(null)
function onAssigned(message: string) {
  assignNotice.value = message
  void load()
}
const roster = ref<Enrollment[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

/** Mirrors Enrollments.vue's own filter — see its comment for why codes/labels come from ENROLLMENT_STATUS base data. Defaults to `active` to match this roster's previous behavior (it only ever showed active enrollments before this filter existed). */
const statusLookup = ref<LookupOption[]>([])
const selectedStatus = ref<EnrollmentStatus | ''>('active')

const statusFilterOptions = computed(() => [
  { value: '', label: t('admin.classStudents.filterAllStatuses') },
  ...statusLookup.value.map((option) => ({ value: option.code, label: option.name })),
])

/** The Class column only earns its place once rows can come from more than one class — see isSingleClass. */
const columns = computed(() => [
  { key: 'index', label: '#' },
  { key: 'table', label: t('admin.classStudents.columnTable'), sortable: true },
  { key: 'student', label: t('admin.classStudents.columnStudent'), sortable: true },
  { key: 'gender', label: t('admin.classStudents.columnGender') },
  ...(isSingleClass.value ? [] : [{ key: 'class', label: t('admin.classStudents.columnClass') }]),
  { key: 'book', label: t('admin.classStudents.columnBook'), sortable: true },
  { key: 'responsible_staff', label: t('admin.classStudents.columnResponsibleStaff') },
  { key: 'note', label: t('admin.classStudents.columnNote') },
  ...(hasRowActions.value
    ? [{ key: 'actions', label: t('admin.classStudents.columnActions'), align: 'text-right' }]
    : []),
])

/**
 * The whole roster is fetched in one shot (it's one class, never paginated),
 * so unlike every other admin list here sorting is done client-side rather
 * than via a server `sort` param. Defaults to Table so a teacher opening the
 * roster to seat students sees them grouped by table right away.
 */
const tableSort = ref<string | undefined>('table')

const sortValueGetters: Record<string, (enrollment: Enrollment) => string> = {
  table: (enrollment) => enrollment.table?.name ?? '',
  student: (enrollment) => enrollment.student.full_name,
  book: (enrollment) => enrollment.course_package?.name ?? '',
}

// Responsible Staff filter: '' = everyone, 'none' = no responsible staff, else a staff id.
// Built from the students loaded, so it only lists staff of this class.
const responsibleStaffFilter = ref('')
const responsibleStaffFilterOptions = computed(() => {
  const staff = new Map<number, string>()
  for (const row of roster.value) {
    if (row.responsible_staff) staff.set(row.responsible_staff.staff_id, row.responsible_staff.name)
  }
  return [
    { value: '', label: t('admin.classStudents.filterAllResponsibleStaff') },
    { value: 'none', label: t('admin.classStudents.filterNoResponsibleStaff') },
    ...[...staff].sort((a, b) => a[1].localeCompare(b[1])).map(([id, name]) => ({ value: String(id), label: name })),
  ]
})
const filteredRoster = computed(() => {
  const f = responsibleStaffFilter.value
  if (f === '') return roster.value
  if (f === 'none') return roster.value.filter((row) => !row.responsible_staff)
  return roster.value.filter((row) => String(row.responsible_staff?.staff_id ?? '') === f)
})
function onResponsibleStaffFilterChange(value: string) {
  responsibleStaffFilter.value = value
  selectedIds.value = []
}

const sortedRoster = computed(() => {
  if (tableSort.value === undefined) return filteredRoster.value

  const descending = tableSort.value.startsWith('-')
  const getValue = sortValueGetters[tableSort.value.replace(/^-/, '')]
  if (!getValue) return filteredRoster.value

  return [...filteredRoster.value].sort((a, b) => {
    const result = getValue(a).localeCompare(getValue(b))
    return descending ? -result : result
  })
})

function toggleTableSort(column: string) {
  tableSort.value = tableSort.value === column ? `-${column}` : column
}

const classModalOpen = ref(false)
const statusModalOpen = ref(false)
const tableModalOpen = ref(false)
const activeEnrollment = ref<Enrollment | null>(null)

function openChangeClass(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  classModalOpen.value = true
}

function openChangeStatus(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  statusModalOpen.value = true
}

function openChangeTable(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  tableModalOpen.value = true
}

/** "1:00 PM - 2:00 PM" for a single weekly slot; joins multiple as a comma list — same shape as Classes.vue's own summary. */
const scheduleSummary = computed(() => {
  if (!schoolClass.value || schoolClass.value.schedules.length === 0) return '—'
  return schoolClass.value.schedules.map((s) => `${s.start_time} - ${s.end_time}`).join(', ')
})

/** No direct Academic Year link on a class today — the year it actually ran in is simply the year it started. */
const academicYear = computed(() => {
  if (!schoolClass.value?.start_date) return '—'
  return new Date(schoolClass.value.start_date).getFullYear()
})

async function loadRoster() {
  if (classIds.value.length === 0) {
    roster.value = []
    return
  }

  // The backend's `filter[class_id]` already accepts a comma-separated list
  // (ApiQuery::applyFilters -> whereIn) — one class or several is the same call.
  const filter: Record<string, string> = { class_id: classIds.value.join(',') }
  if (selectedStatus.value) filter.status = selectedStatus.value

  const enrollments = await enrollmentsService.listAll(filter)
  roster.value = [...enrollments].sort((a, b) => a.student.full_name.localeCompare(b.student.full_name))
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    if (isSingleClass.value) {
      const [classResult] = await Promise.all([classesService.get(classIds.value[0]), loadRoster()])
      schoolClass.value = classResult
    } else {
      schoolClass.value = null
      await loadRoster()
    }
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.loadFailed')
  } finally {
    loading.value = false
  }
}

const attendanceLink = computed(() =>
  isSingleClass.value ? `/admin/attendance?class_id=${classIds.value[0]}` : `/admin/classes/attendance?class_ids=${classIds.value.join(',')}`,
)

async function onStatusFilterChange(value: string) {
  selectedStatus.value = value as EnrollmentStatus | ''
  selectedIds.value = []

  try {
    await loadRoster()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.loadFailed')
  }
}

// --- Send to Exam --------------------------------------------------------
// The teacher checks students, sets one shared exam date/time/remark in
// SendToExamModal, and that fires one pending Exam Application per checked
// enrollment (no bulk-create endpoint exists on the backend).

const selectedIds = ref<number[]>([])
const sendToExamModalOpen = ref(false)
const sendToExamMessage = ref<string | null>(null)

function openSendToExam() {
  if (selectedIds.value.length === 0) return
  sendToExamMessage.value = null
  sendToExamModalOpen.value = true
}

function onSentToExam(count: number) {
  sendToExamMessage.value = t('admin.classStudents.sendToExamSuccess', { count })
  selectedIds.value = []
}

// --- Note ----------------------------------------------------------------
// Every student on the roster with their note, edited together (like Add score).

const notesModalOpen = ref(false)
const notesMessage = ref<string | null>(null)

// The checked students (in screen order), or everyone shown when none is checked.
const noteTargets = computed(() =>
  selectedIds.value.length > 0 ? sortedRoster.value.filter((row) => selectedIds.value.includes(row.id)) : sortedRoster.value,
)

function openNotes() {
  notesMessage.value = null
  notesModalOpen.value = true
}

async function onNotesSaved(count: number) {
  notesMessage.value = t('admin.classStudents.notesSaved', { count })
  try {
    await loadRoster()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.loadFailed')
  }
}

// --- Responsible Staff ---------------------------------------------------
// One staff member for all the checked students, from a date.

const responsibleStaffOpen = ref(false)
const responsibleStaffMessage = ref<string | null>(null)

function openResponsibleStaff() {
  if (selectedIds.value.length === 0) return
  responsibleStaffMessage.value = null
  responsibleStaffOpen.value = true
}

async function onResponsibleStaffSaved(count: number) {
  try {
    await loadRoster()
    responsibleStaffMessage.value = t('admin.classStudents.responsibleStaffSaved', { count })
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.loadFailed')
  }
}

// --- Export Excel --------------------------------------------------------
// Only the checked students, numbered in the order the roster shows them.

const exporting = ref(false)
const exportError = ref<string | null>(null)

async function exportExcel() {
  const ids = sortedRoster.value.filter((row) => selectedIds.value.includes(row.id)).map((row) => row.id)
  if (ids.length === 0) return

  exporting.value = true
  exportError.value = null
  try {
    const name = (schoolClass.value?.name ?? 'class-students').replace(/[\\/:*?"<>|]+/g, '-').trim()
    await enrollmentsService.exportExcel(
      ids,
      {
        no: t('admin.classStudents.columnNo'),
        table: t('admin.classStudents.columnTable'),
        name: t('admin.classStudents.columnFullName'),
        gender: t('admin.classStudents.columnGender'),
        book: t('admin.classStudents.columnBook'),
        responsible_staff: t('admin.classStudents.columnResponsibleStaff'),
        note: t('admin.classStudents.columnNote'),
        male: t('common.genderMale'),
        female: t('common.genderFemale'),
        other: t('common.genderOther'),
      },
      schoolClass.value?.name ?? null,
      `${name || 'class-students'}.xlsx`,
    )
  } catch (error) {
    exportError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.exportFailed')
  } finally {
    exporting.value = false
  }
}

const allShownSelected = computed(() => sortedRoster.value.length > 0 && sortedRoster.value.every((row) => selectedIds.value.includes(row.id)))
function toggleAllShown() {
  selectedIds.value = allShownSelected.value ? [] : sortedRoster.value.map((row) => row.id)
}

function toggleSelected(id: number) {
  selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter((x) => x !== id) : [...selectedIds.value, id]
}

onMounted(() => {
  void load()
  void lookupsService.values('ENROLLMENT_STATUS', locale.value).then((result) => (statusLookup.value = result))
})
</script>

<template>
  <div>
    <div class="mb-2">
      <BaseButton variant="outline" size="sm" to="/admin/classes">{{ t('admin.classStudents.backToClasses') }}</BaseButton>
    </div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.classStudents.title') }}</h1>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <p v-else-if="classIds.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.classStudents.noClassesSelected') }}
    </p>

    <template v-else>
      <!-- Class/Teacher/Academic Year/Time only make sense for exactly one
           class — with several selected, each row's own Class column (see
           columns above) carries that instead. -->
      <div v-if="schoolClass" class="mb-6 grid gap-x-8 gap-y-2 rounded-[--radius-card] border border-neutral-200 bg-white p-4 sm:grid-cols-2">
        <div class="flex justify-between border-b border-neutral-100 pb-2 sm:border-b-0 sm:pb-0">
          <span class="text-sm text-neutral-500">{{ t('admin.classStudents.class') }}</span>
          <span class="text-sm font-medium text-neutral-800">{{ schoolClass.name }}</span>
        </div>
        <div class="flex justify-between border-b border-neutral-100 pb-2 sm:border-b-0 sm:pb-0">
          <span class="text-sm text-neutral-500">{{ t('admin.classStudents.academicYear') }}</span>
          <span class="text-sm font-medium text-neutral-800">{{ academicYear }}</span>
        </div>
        <div class="flex justify-between border-b border-neutral-100 pb-2 sm:border-b-0 sm:pb-0">
          <span class="text-sm text-neutral-500">{{ t('admin.classStudents.teacher') }}</span>
          <span class="text-sm font-medium text-neutral-800">{{ schoolClass.teachers.map((t) => t.name).join(', ') || '—' }}</span>
        </div>
        <div class="flex justify-between border-b border-neutral-100 pb-2 sm:border-b-0 sm:pb-0">
          <span class="text-sm text-neutral-500">{{ t('admin.classStudents.time') }}</span>
          <span class="text-sm font-medium text-neutral-800">{{ scheduleSummary }}</span>
        </div>
        <div v-if="schoolClass.assistant_teachers.length > 0" class="flex justify-between">
          <span class="text-sm text-neutral-500">{{ t('admin.classStudents.assistantTeacher') }}</span>
          <span class="text-sm font-medium text-neutral-800">{{ schoolClass.assistant_teachers.map((t) => t.name).join(', ') }}</span>
        </div>
      </div>
      <p v-else class="mb-4 text-sm text-neutral-500">{{ t('admin.classStudents.multiSubtitle', { count: classIds.length }) }}</p>

      <BaseAlert v-if="assignNotice" variant="success" class="mb-4">{{ assignNotice }}</BaseAlert>
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <BaseButton v-if="canAssign && schoolClass" @click="assignOpen = true">{{ t('studentRecord.assign.button') }}</BaseButton>
          <BaseButton :to="attendanceLink" variant="outline">{{ t('admin.classStudents.attendance') }}</BaseButton>
          <BaseButton v-if="canSendToExam" variant="outline" :disabled="selectedIds.length === 0" @click="openSendToExam">
            {{ t('admin.classStudents.sendToExam') }}
          </BaseButton>
          <BaseButton v-if="canNote" variant="outline" :disabled="sortedRoster.length === 0" :title="t('admin.classStudents.noteHint')" @click="openNotes">
            {{ t('admin.classStudents.note') }}<template v-if="selectedIds.length > 0"> ({{ selectedIds.length }})</template>
          </BaseButton>
          <BaseButton v-if="canResponsibleStaff" variant="outline" :disabled="selectedIds.length === 0" @click="openResponsibleStaff">
            {{ t('admin.classStudents.responsibleStaff') }}<template v-if="selectedIds.length > 0"> ({{ selectedIds.length }})</template>
          </BaseButton>
          <BaseButton variant="outline" :disabled="selectedIds.length === 0" :loading="exporting" :title="t('admin.classStudents.exportExcelHint')" @click="exportExcel">
            {{ t('admin.classStudents.exportExcel') }}<template v-if="selectedIds.length > 0"> ({{ selectedIds.length }})</template>
          </BaseButton>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
          <BaseSelect
            :model-value="responsibleStaffFilter"
            :options="responsibleStaffFilterOptions"
            class="w-full sm:w-56"
            :aria-label="t('admin.classStudents.columnResponsibleStaff')"
            @update:model-value="onResponsibleStaffFilterChange"
          />
          <BaseSelect
            :model-value="selectedStatus"
            :options="statusFilterOptions"
            class="w-full sm:w-48"
            @update:model-value="onStatusFilterChange"
          />
        </div>
      </div>

      <BaseAlert v-if="sendToExamMessage" variant="success" class="mb-4">{{ sendToExamMessage }}</BaseAlert>
      <BaseAlert v-if="notesMessage" variant="success" class="mb-4">{{ notesMessage }}</BaseAlert>
      <BaseAlert v-if="responsibleStaffMessage" variant="success" class="mb-4">{{ responsibleStaffMessage }}</BaseAlert>
      <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>

      <!-- Phone: one card per student. -->
      <div class="sm:hidden">
        <p v-if="sortedRoster.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('admin.classStudents.emptyMessage') }}
        </p>
        <div v-else class="space-y-2">
          <label class="flex items-center gap-2 rounded-lg border border-neutral-200 bg-white px-2.5 py-2 text-sm font-medium text-neutral-800 shadow-sm">
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="allShownSelected"
              :indeterminate="selectedIds.length > 0 && !allShownSelected"
              @change="toggleAllShown"
            />
            {{ t('common.selectAll') }}
            <span v-if="selectedIds.length > 0" class="ml-auto text-xs font-normal text-neutral-500">{{ t('common.selectedCount', { count: selectedIds.length }) }}</span>
          </label>
          <div v-for="(row, index) in sortedRoster" :key="row.id" class="rounded-lg border border-neutral-200 bg-white px-2.5 py-2 shadow-sm">
            <div class="flex items-center gap-2">
              <input
                type="checkbox"
                class="shrink-0 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :checked="selectedIds.includes(row.id)"
                :aria-label="row.student.full_name"
                @change="toggleSelected(row.id)"
              />
              <p class="min-w-0 flex-1 truncate text-sm font-semibold text-neutral-800">
                <span class="font-normal text-neutral-400">{{ index + 1 }}.</span> {{ row.student.full_name }}
              </p>
              <div v-if="hasRowActions" class="-my-1 flex shrink-0 gap-0.5">
                <ActionIconButton v-if="canTransfer" :title="t('admin.enrollments.changeClass')" @click="openChangeClass(row)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                  </svg>
                </ActionIconButton>
                <ActionIconButton v-if="canChangeStatus" :title="t('admin.enrollments.changeStatus')" @click="openChangeStatus(row)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
                    />
                  </svg>
                </ActionIconButton>
                <ActionIconButton v-if="showChangeTable" :title="t('admin.enrollments.changeTable')" @click="openChangeTable(row)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"
                    />
                  </svg>
                </ActionIconButton>
              </div>
            </div>
            <p class="mt-0.5 truncate pl-6 text-xs text-neutral-500">
              <span class="font-medium text-neutral-700">{{ row.table?.name ?? '—' }}</span>
              · {{ genderLabel(row.student.gender) }}
              <template v-if="row.course_package"> · {{ row.course_package.name }}</template>
              <template v-if="!isSingleClass"> · {{ row.class?.name ?? '—' }}</template>
            </p>
            <p v-if="row.responsible_staff" class="mt-1 flex items-center gap-1 pl-6 text-xs text-neutral-700" :title="t('admin.classStudents.columnResponsibleStaff')">
              <svg class="h-3.5 w-3.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.1a7.5 7.5 0 0115 0A17.9 17.9 0 0112 21.75c-2.68 0-5.22-.59-7.5-1.65z" />
              </svg>
              <span class="sr-only">{{ t('admin.classStudents.columnResponsibleStaff') }}:</span>
              <span class="truncate">{{ row.responsible_staff.name }}</span>
            </p>
            <p v-if="row.note" class="mt-1 flex items-start gap-1 pl-6 text-xs text-neutral-700" :title="t('admin.classStudents.columnNote')">
              <svg class="mt-px h-3.5 w-3.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.86 4.49l1.69-1.69a1.88 1.88 0 112.65 2.65L10.58 16.07a4.5 4.5 0 01-1.9 1.13L6 18l.8-2.68a4.5 4.5 0 011.13-1.9l8.93-8.93zM19.5 14v5.25A2.25 2.25 0 0117.25 21.5H4.75A2.25 2.25 0 012.5 19.25V6.75A2.25 2.25 0 014.75 4.5H10" />
              </svg>
              <span class="sr-only">{{ t('admin.classStudents.columnNote') }}:</span>
              <span class="line-clamp-2 whitespace-pre-line">{{ row.note }}</span>
            </p>
          </div>
        </div>
      </div>

      <DataTable
        class="hidden sm:block"
        :columns="columns"
        :rows="sortedRoster"
        row-key="id"
        :sort="tableSort"
        selectable
        :selected="selectedIds"
        :empty-message="t('admin.classStudents.emptyMessage')"
        @sort="toggleTableSort"
        @update:selected="selectedIds = $event as number[]"
      >
        <template #cell-index="{ row }">{{ sortedRoster.indexOf(row) + 1 }}</template>
        <template #cell-table="{ row }">{{ row.table?.name ?? '—' }}</template>
        <template #cell-student="{ row }">{{ row.student.full_name }}</template>
        <template #cell-gender="{ row }">{{ genderLabel(row.student.gender) }}</template>
        <template #cell-class="{ row }">{{ row.class?.name ?? '—' }}</template>
        <template #cell-book="{ row }">{{ row.course_package?.name ?? '—' }}</template>
        <template #cell-responsible_staff="{ row }">
          <span
            v-if="row.responsible_staff"
            class="text-neutral-700"
            :title="`${formatDate(row.responsible_staff.from_date)} → ${row.responsible_staff.to_date ? formatDate(row.responsible_staff.to_date) : '…'}`"
          >
            {{ row.responsible_staff.name }}
          </span>
          <span v-else class="text-neutral-400">—</span>
        </template>
        <template #cell-note="{ row }">
          <span class="line-clamp-2 max-w-xs whitespace-pre-line text-neutral-700" :title="row.note ?? ''">{{ row.note || '—' }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <ActionIconButton v-if="canTransfer" :title="t('admin.enrollments.changeClass')" @click="openChangeClass(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
              </svg>
            </ActionIconButton>
            <ActionIconButton v-if="canChangeStatus" :title="t('admin.enrollments.changeStatus')" @click="openChangeStatus(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
                />
              </svg>
            </ActionIconButton>
            <ActionIconButton v-if="showChangeTable" :title="t('admin.enrollments.changeTable')" @click="openChangeTable(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"
                />
              </svg>
            </ActionIconButton>
          </div>
        </template>
      </DataTable>
    </template>

    <ResponsibleStaffModal v-model="responsibleStaffOpen" :enrollment-ids="selectedIds" @saved="onResponsibleStaffSaved" />
    <EnrollmentTransferModal v-model="classModalOpen" :enrollment="activeEnrollment" @saved="load" />
    <EnrollmentStatusModal v-model="statusModalOpen" :enrollment="activeEnrollment" @saved="load" />
    <ChangeTableModal v-model="tableModalOpen" :enrollment="activeEnrollment" @saved="load" />
    <SendToExamModal v-model="sendToExamModalOpen" :enrollment-ids="selectedIds" @saved="onSentToExam" />
    <ClassNotesModal v-model="notesModalOpen" :enrollments="noteTargets" @saved="onNotesSaved" />
    <AssignStudentsModal v-if="schoolClass" v-model="assignOpen" :class-id="schoolClass.id" :class-name="schoolClass.name" @saved="onAssigned" />
  </div>
</template>
