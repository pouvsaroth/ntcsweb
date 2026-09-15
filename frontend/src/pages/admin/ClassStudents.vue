<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EnrollmentStatusModal from '@/components/admin/EnrollmentStatusModal.vue'
import EnrollmentTransferModal from '@/components/admin/EnrollmentTransferModal.vue'
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

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// A role built from Classes + Attendance alone (see EnrollmentPolicy's own
// docblock) can reach this page and its roster, but never granted
// enrollments.transfer/.change-status — hide the row actions those would
// otherwise 403 on, rather than let a teacher discover that on submit.
const canTransfer = computed(() => auth.can('enrollments.transfer'))
const canChangeStatus = computed(() => auth.can('enrollments.change-status'))

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
  { key: 'student', label: t('admin.classStudents.columnStudent'), sortable: true },
  { key: 'gender', label: t('admin.classStudents.columnGender') },
  ...(isSingleClass.value ? [] : [{ key: 'class', label: t('admin.classStudents.columnClass') }]),
  { key: 'table', label: t('admin.classStudents.columnTable'), sortable: true },
  { key: 'book', label: t('admin.classStudents.columnBook'), sortable: true },
  ...(canTransfer.value || canChangeStatus.value ? [{ key: 'actions', label: t('admin.classStudents.columnActions'), align: 'text-right' }] : []),
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

const sortedRoster = computed(() => {
  if (tableSort.value === undefined) return roster.value

  const descending = tableSort.value.startsWith('-')
  const getValue = sortValueGetters[tableSort.value.replace(/^-/, '')]
  if (!getValue) return roster.value

  return [...roster.value].sort((a, b) => {
    const result = getValue(a).localeCompare(getValue(b))
    return descending ? -result : result
  })
})

function toggleTableSort(column: string) {
  tableSort.value = tableSort.value === column ? `-${column}` : column
}

const classModalOpen = ref(false)
const statusModalOpen = ref(false)
const activeEnrollment = ref<Enrollment | null>(null)

function openChangeClass(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  classModalOpen.value = true
}

function openChangeStatus(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  statusModalOpen.value = true
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

  try {
    await loadRoster()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.loadFailed')
  }
}

onMounted(() => {
  void load()
  void lookupsService.values('ENROLLMENT_STATUS', locale.value).then((result) => (statusLookup.value = result))
})
</script>

<template>
  <div>
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

      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <BaseButton :to="attendanceLink" variant="outline">{{ t('admin.classStudents.attendance') }}</BaseButton>
        <BaseSelect
          :model-value="selectedStatus"
          :options="statusFilterOptions"
          class="w-48"
          @update:model-value="onStatusFilterChange"
        />
      </div>

      <DataTable
        :columns="columns"
        :rows="sortedRoster"
        row-key="id"
        :sort="tableSort"
        :empty-message="t('admin.classStudents.emptyMessage')"
        @sort="toggleTableSort"
      >
        <template #cell-index="{ row }">{{ sortedRoster.indexOf(row) + 1 }}</template>
        <template #cell-student="{ row }">{{ row.student.full_name }}</template>
        <template #cell-gender="{ row }">{{ row.student.gender ?? '—' }}</template>
        <template #cell-class="{ row }">{{ row.class?.name ?? '—' }}</template>
        <template #cell-table="{ row }">{{ row.table?.name ?? '—' }}</template>
        <template #cell-book="{ row }">{{ row.course_package?.name ?? '—' }}</template>
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
          </div>
        </template>
      </DataTable>
    </template>

    <EnrollmentTransferModal v-model="classModalOpen" :enrollment="activeEnrollment" @saved="load" />
    <EnrollmentStatusModal v-model="statusModalOpen" :enrollment="activeEnrollment" @saved="load" />
  </div>
</template>
