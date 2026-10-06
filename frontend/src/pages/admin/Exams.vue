<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowReactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import ExamApplicationFormModal from '@/components/admin/ExamApplicationFormModal.vue'
import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { examApplicationStatuses, examApplicationsService, type ExamApplication, type ExamApplicationStatus } from '@/services/examApplications'
import { useAdminUiStore } from '@/stores/adminUi'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'
import { exportTableAsImage, type TableImageTone } from '@/utils/tableImage'

const { t } = useI18n()
const adminUi = useAdminUiStore()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('exam-applications.create'))
const canUpdate = computed(() => auth.can('exam-applications.update'))
const canDelete = computed(() => auth.can('exam-applications.delete'))

/** The dropdown's default (see statusFilter below) — draft/pending applications plus approved-but-not-yet-scored ones, i.e. everything this tab can still act on. Mutually exclusive with the `status` filter, so it's kept out of `filters` entirely and passed as its own param instead (see examApplicationsService.list()). */
const AWAITING_ACTION = 'awaiting_action'

const statusFilter = ref<ExamApplicationStatus | '' | typeof AWAITING_ACTION>(AWAITING_ACTION)

const perPageOptions = [10, 25, 50, 100]

const { items, meta, loading, error, perPage, sort, setPage, setFilter, fetch } = usePaginatedResource<ExamApplication>((query) =>
  examApplicationsService.list(query, { awaitingAction: statusFilter.value === AWAITING_ACTION }),
)

// Earliest exam date first, on the phone cards and the desktop table alike;
// applications with no exam date yet come last.
const EXAM_DATE_SORT = 'exam_date'

// On a phone the list shows as cards (below the `sm` breakpoint, same as
// Students.vue).
const phoneQuery = window.matchMedia('(max-width: 639px)')
const isPhone = ref(phoneQuery.matches)

function onScreenChange(event: MediaQueryListEvent) {
  isPhone.value = event.matches
}

onMounted(() => {
  phoneQuery.addEventListener('change', onScreenChange)
  sort.value = EXAM_DATE_SORT
  void fetch()
})

onBeforeUnmount(() => phoneQuery.removeEventListener('change', onScreenChange))

function toggleSelected(id: number) {
  selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter((selected) => selected !== id) : [...selectedIds.value, id]
}

function onStatusFilterChange(value: string) {
  statusFilter.value = value as ExamApplicationStatus | '' | typeof AWAITING_ACTION
  setFilter('status', statusFilter.value === AWAITING_ACTION ? undefined : statusFilter.value || undefined)
}
/** "not_exam" -> "NotExam" — a plain first-letter capitalize (as every other status here only ever needed) breaks on the underscore. */
function statusKeySuffix(status: string): string {
  return status.replace(/(^|_)([a-z])/g, (_match, _sep, letter: string) => letter.toUpperCase())
}

const statusFilterOptions = computed(() => [
  { value: AWAITING_ACTION, label: t('admin.exams.awaitingAction') },
  { value: '', label: t('admin.exams.allStatuses') },
  ...examApplicationStatuses.map((status) => ({ value: status, label: t(`admin.exams.status${statusKeySuffix(status)}`) })),
])

const statusVariant: Record<ExamApplicationStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'primary'> = {
  draft: 'neutral',
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  not_exam: 'neutral',
  make_up: 'primary',
}

const columns = [
  { key: 'actions', label: t('admin.exams.columnActions') },
  { key: 'status', label: t('admin.exams.columnStatus') },
  { key: 'enrollment_code', label: t('admin.exams.columnEnrollmentCode') },
  { key: 'full_name', label: t('admin.exams.columnFullName') },
  { key: 'other_name', label: t('admin.exams.columnOtherName') },
  { key: 'sex', label: t('admin.exams.columnSex') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'exam_date', label: t('admin.exams.columnExamDate') },
  { key: 'time_exam', label: t('admin.exams.columnTimeExam') },
  { key: 'birth_date', label: t('admin.exams.columnBirthDate') },
  { key: 'address', label: t('admin.exams.columnAddress') },
  { key: 'room_number', label: t('admin.exams.columnRoomNumber') },
  { key: 'table_no', label: t('admin.exams.columnTableNumber') },
]

function statusLabel(status: ExamApplicationStatus): string {
  return t(`admin.exams.status${statusKeySuffix(status)}`)
}

function fmtDate(value: string | null): string {
  return formatDate(value)
}

function timeRange(a: ExamApplication): string {
  const timeIn = a.exam_time?.slice(0, 5)
  const timeOut = a.exam_time_out?.slice(0, 5)
  if (!timeIn && !timeOut) return '—'
  return `${timeIn ?? '—'} - ${timeOut ?? '—'}`
}

// --- Selection + toolbar actions --------------------------------------

const selectedIds = ref<number[]>([])
const actionError = ref<string | null>(null)
const acting = ref(false)

// --- Export as image ------------------------------------------------------
//
// Same convention as Attendance Summary: ticked rows export alone; with
// nothing ticked, every row on screen does. Ticks survive paging, so every
// row seen so far is remembered by id — otherwise a row ticked on page 1
// would silently drop out of the export once you moved to page 2.

// shallowReactive so a reload (e.g. after Approve) refreshes what a ticked
// row exports, not just what the table shows.
const seenRows = shallowReactive(new Map<number, ExamApplication>())
watch(items, (rows) => rows.forEach((row) => seenRows.set(row.id, row)), { immediate: true })

const selectedRows = computed(() =>
  selectedIds.value.map((id) => seenRows.get(id)).filter((row): row is ExamApplication => row !== undefined),
)

const exporting = ref(false)

/** BaseBadge has a 'primary' tone the image drawer doesn't — Make-up shows as amber there instead. */
function imageTone(status: ExamApplicationStatus): TableImageTone {
  const variant = statusVariant[status]
  return variant === 'primary' ? 'warning' : variant
}

async function exportImage() {
  exporting.value = true
  actionError.value = null
  try {
    const rows = selectedRows.value.length ? selectedRows.value : items.value
    await exportTableAsImage({
      title: t('admin.exams.title'),
      subtitle: `${t('admin.exams.exportedOn')} ${formatDate(new Date())}`,
      columns: [
        { label: t('admin.exams.columnStatus'), width: 110 },
        { label: t('admin.exams.columnEnrollmentCode'), width: 140 },
        { label: t('admin.exams.columnFullName'), width: 200, maxWidth: 320 },
        { label: t('admin.exams.columnSex'), width: 60 },
        { label: t('admin.exams.columnBook'), width: 160, maxWidth: 280 },
        { label: t('admin.exams.columnExamDate'), width: 120 },
        { label: t('admin.exams.columnTimeExam'), width: 130 },
      ],
      rows: rows.map((row) => [
        { text: statusLabel(row.status), badge: imageTone(row.status) },
        { text: row.enrollment_code ?? '—' },
        { text: row.student.name, bold: true },
        { text: genderLabel(row.student.gender) },
        { text: row.book?.title ?? row.enrollment.course_package?.name ?? '—' },
        { text: fmtDate(row.exam_date) },
        { text: timeRange(row) },
      ]),
      emptyText: t('admin.exams.emptyMessage'),
      fileName: `exams-${new Date().toISOString().slice(0, 10)}.png`,
    })
  } catch {
    actionError.value = t('admin.exams.exportImageFailed')
  } finally {
    exporting.value = false
  }
}

/**
 * "Not Exam" — a student was sent to exam (still draft) but doesn't want
 * to sit it. Only offered on a draft row (see ExamApplicationService::
 * markNotExam()); a row that's already pending/approved/rejected is a real
 * application at that point, handled by Approve/Reject instead.
 */
async function markNotExam(application: ExamApplication) {
  if (!(await confirmDialog.confirm({ message: t('admin.exams.confirmNotExam'), danger: true }))) return

  acting.value = true
  actionError.value = null
  try {
    await examApplicationsService.markNotExam(application.id)
    await fetch()
  } catch (err) {
    actionError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = false
  }
}

/**
 * A direct status flip to Approved, from any status — unlike the Approval
 * queue's own Approve action (ExamApplicationService::approve()), this
 * doesn't require the row to already be pending first, doesn't touch
 * decided_by/decided_at, and never touches book/room/table/date (the
 * update request only ever sends the one field it validated — see
 * UpdateExamApplicationRequest's `status` rule). A convenience shortcut for
 * a school that doesn't need the review step, not a replacement for it.
 */
async function approveAuto(application: ExamApplication) {
  if (!(await confirmDialog.confirm({ message: t('admin.exams.confirmApproveAuto') }))) return

  acting.value = true
  actionError.value = null
  try {
    await examApplicationsService.update(application.id, { status: 'approved' })
    await fetch()
  } catch (err) {
    actionError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = false
  }
}

async function deleteSelected() {
  if (selectedIds.value.length === 0) return
  if (!(await confirmDialog.confirm({ message: t('admin.exams.confirmDelete', { count: selectedIds.value.length }), danger: true }))) return

  acting.value = true
  actionError.value = null
  try {
    for (const id of selectedIds.value) {
      await examApplicationsService.remove(id)
    }
    selectedIds.value = []
    await fetch()
  } catch (err) {
    actionError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = false
  }
}

function printList() {
  window.print()
}

// --- Application Form modal --------------------------------------------

const formModalOpen = ref(false)
const editingEnrollmentCode = ref<string | null>(null)

function openApplicationForm(enrollmentCode: string | null = null) {
  editingEnrollmentCode.value = enrollmentCode
  formModalOpen.value = true
}
</script>

<template>
  <div>
    <ExaminationTabs />

    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.exams.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.exams.subtitle') }}</p>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseButton v-if="canCreate" @click="openApplicationForm()">{{ t('admin.exams.applicationForm') }}</BaseButton>
      <BaseButton variant="outline" @click="printList">{{ t('admin.exams.printList') }}</BaseButton>
      <BaseButton variant="outline" :loading="exporting" :disabled="loading" @click="exportImage">
        {{ t('admin.exams.exportImage') }}<template v-if="selectedRows.length"> ({{ selectedRows.length }})</template>
      </BaseButton>
      <BaseButton v-if="canDelete" variant="danger" :disabled="selectedIds.length === 0 || acting" @click="deleteSelected">
        {{ t('common.remove') }}
      </BaseButton>

      <BaseSelect
        class="ml-auto w-48"
        :model-value="statusFilter"
        :options="statusFilterOptions"
        @update:model-value="onStatusFilterChange"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <!-- Cards on a phone — the table's 13 columns have no room there. Same
         order as the table (see EXAM_DATE_SORT). -->
    <div v-if="isPhone" class="pb-28">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.exams.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div
          v-for="row in items"
          :key="row.id"
          class="rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card]"
          :class="selectedIds.includes(row.id) ? 'border-primary-300 ring-1 ring-primary-200' : 'border-neutral-200'"
        >
          <div class="flex items-start gap-3">
            <input
              type="checkbox"
              class="mt-1 h-4 w-4 shrink-0 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="selectedIds.includes(row.id)"
              :aria-label="row.student.name"
              @change="toggleSelected(row.id)"
            />
            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-neutral-800">{{ row.student.name }}</p>
                  <button type="button" class="text-xs font-medium text-primary-700 hover:underline" @click="openApplicationForm(row.enrollment_code)">
                    {{ row.enrollment_code ?? '—' }}
                  </button>
                </div>
                <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ statusLabel(row.status) }}</BaseBadge>
              </div>

              <p class="mt-2 text-sm font-medium text-neutral-800">
                {{ fmtDate(row.exam_date) }} <span class="font-normal text-neutral-500">· {{ timeRange(row) }}</span>
              </p>
              <p class="truncate text-xs text-neutral-500">{{ row.book?.title ?? row.enrollment.course_package?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">
                {{ t('admin.exams.columnRoomNumber') }}: {{ row.classroom?.name ?? '—' }} · {{ t('admin.exams.columnTableNumber') }}:
                {{ row.table?.name ?? row.table_no ?? '—' }}
              </p>

              <div v-if="canUpdate" class="mt-2 flex justify-end gap-1">
                <EditIconButton :title="t('admin.exams.update')" @click="openApplicationForm(row.enrollment_code)" />
                <ActionIconButton v-if="row.status === 'draft'" :title="t('admin.exams.approveAuto')" @click="approveAuto(row)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </ActionIconButton>
                <ActionIconButton
                  v-if="row.status === 'draft' || row.status === 'make_up'"
                  variant="danger"
                  :title="row.status === 'draft' ? t('admin.exams.notExam') : t('admin.exams.giveUpExam')"
                  @click="markNotExam(row)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </ActionIconButton>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable
      v-else
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      selectable
      :selected="selectedIds"
      :empty-message="t('admin.exams.emptyMessage')"
      @update:selected="selectedIds = $event as number[]"
    >
      <template #cell-actions="{ row }">
        <div class="flex gap-1">
          <EditIconButton v-if="canUpdate" :title="t('admin.exams.update')" @click="openApplicationForm((row as ExamApplication).enrollment_code)" />
          <ActionIconButton
            v-if="canUpdate && (row as ExamApplication).status === 'draft'"
            :title="t('admin.exams.approveAuto')"
            @click="approveAuto(row as ExamApplication)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </ActionIconButton>
          <ActionIconButton
            v-if="canUpdate && (row as ExamApplication).status === 'draft'"
            variant="danger"
            :title="t('admin.exams.notExam')"
            @click="markNotExam(row as ExamApplication)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </ActionIconButton>
          <ActionIconButton
            v-if="canUpdate && (row as ExamApplication).status === 'make_up'"
            variant="danger"
            :title="t('admin.exams.giveUpExam')"
            @click="markNotExam(row as ExamApplication)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </ActionIconButton>
        </div>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[(row as ExamApplication).status]">{{ statusLabel((row as ExamApplication).status) }}</BaseBadge>
      </template>
      <template #cell-enrollment_code="{ row }">
        <button type="button" class="font-medium text-primary-700 hover:underline" @click="openApplicationForm((row as ExamApplication).enrollment_code)">
          {{ (row as ExamApplication).enrollment_code ?? '—' }}
        </button>
      </template>
      <template #cell-full_name="{ row }">{{ (row as ExamApplication).student.name }}</template>
      <template #cell-other_name="{ row }">{{ (row as ExamApplication).student.english_name ?? '—' }}</template>
      <template #cell-sex="{ row }">{{ genderLabel((row as ExamApplication).student.gender) }}</template>
      <template #cell-book="{ row }">{{ (row as ExamApplication).book?.title ?? (row as ExamApplication).enrollment.course_package?.name ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ fmtDate((row as ExamApplication).exam_date) }}</template>
      <template #cell-time_exam="{ row }">{{ timeRange(row as ExamApplication) }}</template>
      <template #cell-birth_date="{ row }">{{ fmtDate((row as ExamApplication).student.date_of_birth) }}</template>
      <template #cell-address="{ row }">{{ (row as ExamApplication).student.address ?? '—' }}</template>
      <template #cell-room_number="{ row }">{{ (row as ExamApplication).classroom?.name ?? '—' }}</template>
      <template #cell-table_no="{ row }">{{ (row as ExamApplication).table?.name ?? (row as ExamApplication).table_no ?? '—' }}</template>
    </DataTable>

    <!-- Same per-page selector + pager bar as Students.vue — see its
         comment for why the whole bar is `fixed`, not just the pager. -->
    <div
      v-if="meta"
      class="fixed inset-x-0 bottom-0 z-10 mt-4 flex flex-col items-center gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:flex-row sm:justify-between sm:px-6"
      :class="adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64'"
    >
      <label class="flex items-center gap-2 text-sm text-neutral-500">
        {{ t('admin.exams.perPage') }}
        <select
          v-model.number="perPage"
          class="rounded-lg border border-neutral-300 py-1.5 pl-2 pr-7 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        >
          <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
        </select>
      </label>

      <BasePagination :meta="meta" @update:page="setPage" />
    </div>

    <ExamApplicationFormModal v-model="formModalOpen" :initial-enrollment-code="editingEnrollmentCode" @saved="fetch" />
  </div>
</template>
