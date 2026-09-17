<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ExamApplicationFormModal from '@/components/admin/ExamApplicationFormModal.vue'
import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import PrintExamApplicationModal from '@/components/admin/PrintExamApplicationModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { examApplicationStatuses, examApplicationsService, type ExamApplication, type ExamApplicationStatus } from '@/services/examApplications'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

const { t } = useI18n()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('exam-applications.create'))
const canUpdate = computed(() => auth.can('exam-applications.update'))
const canDelete = computed(() => auth.can('exam-applications.delete'))

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<ExamApplication>((query) =>
  examApplicationsService.list(query),
)

onMounted(() => void fetch())

const statusFilter = ref<ExamApplicationStatus | ''>('')
function onStatusFilterChange(value: string) {
  statusFilter.value = value as ExamApplicationStatus | ''
  setFilter('status', statusFilter.value || undefined)
}
const statusFilterOptions = computed(() => [
  { value: '', label: t('admin.exams.allStatuses') },
  ...examApplicationStatuses.map((status) => ({ value: status, label: t(`admin.exams.status${status.charAt(0).toUpperCase()}${status.slice(1)}`) })),
])

const statusVariant: Record<ExamApplicationStatus, 'success' | 'danger' | 'warning'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const columns = [
  { key: 'status', label: t('admin.exams.columnStatus') },
  { key: 'enrollment_code', label: t('admin.exams.columnEnrollmentCode') },
  { key: 'full_name', label: t('admin.exams.columnFullName') },
  { key: 'other_name', label: t('admin.exams.columnOtherName') },
  { key: 'sex', label: t('admin.exams.columnSex') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'exam_date', label: t('admin.exams.columnExamDate') },
  { key: 'time_exam', label: t('admin.exams.columnTimeExam') },
  { key: 'sold_at', label: t('admin.exams.columnBuyDate') },
  { key: 'received_at', label: t('admin.exams.columnReceiveDate') },
  { key: 'birth_date', label: t('admin.exams.columnBirthDate') },
  { key: 'address', label: t('admin.exams.columnAddress') },
  { key: 'room_number', label: t('admin.exams.columnRoomNumber') },
  { key: 'table_no', label: t('admin.exams.columnTableNumber') },
]

function statusLabel(status: ExamApplicationStatus): string {
  return t(`admin.exams.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)
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

async function runBulkAction(action: (ids: number[]) => Promise<unknown>) {
  if (selectedIds.value.length === 0) return
  acting.value = true
  actionError.value = null
  try {
    await action(selectedIds.value)
    selectedIds.value = []
    await fetch()
  } catch (err) {
    actionError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = false
  }
}

const receiveWord = () => runBulkAction((ids) => examApplicationsService.receive(ids))
const payBackExam = () => runBulkAction((ids) => examApplicationsService.payBack(ids))

async function deleteSelected() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('admin.exams.confirmDelete', { count: selectedIds.value.length }))) return

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

// --- Print modal ---------------------------------------------------------

const printModalOpen = ref(false)
/** Print needs exactly one selected row — the document it produces is inherently a single student's form. */
const printTarget = computed(() => (selectedIds.value.length === 1 ? items.value.find((a) => a.id === selectedIds.value[0]) ?? null : null))

function openPrint() {
  if (printTarget.value) printModalOpen.value = true
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
      <BaseButton v-if="canUpdate" variant="outline" :disabled="!printTarget || acting" @click="openPrint">
        {{ t('admin.exams.print') }}
      </BaseButton>
      <BaseButton v-if="canUpdate" variant="outline" :disabled="selectedIds.length === 0 || acting" @click="receiveWord">
        {{ t('admin.exams.receiveWord') }}
      </BaseButton>
      <BaseButton v-if="canUpdate" variant="outline" :disabled="selectedIds.length === 0 || acting" @click="payBackExam">
        {{ t('admin.exams.payBackExam') }}
      </BaseButton>
      <BaseButton variant="outline" @click="printList">{{ t('admin.exams.printList') }}</BaseButton>
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

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      selectable
      :selected="selectedIds"
      :empty-message="t('admin.exams.emptyMessage')"
      @update:selected="selectedIds = $event as number[]"
    >
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
      <template #cell-sex="{ row }">{{ (row as ExamApplication).student.gender ?? '—' }}</template>
      <template #cell-book="{ row }">{{ (row as ExamApplication).book?.title ?? (row as ExamApplication).enrollment.course_package?.name ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ fmtDate((row as ExamApplication).exam_date) }}</template>
      <template #cell-time_exam="{ row }">{{ timeRange(row as ExamApplication) }}</template>
      <template #cell-sold_at="{ row }">{{ fmtDate((row as ExamApplication).sold_at) }}</template>
      <template #cell-received_at="{ row }">{{ fmtDate((row as ExamApplication).received_at) }}</template>
      <template #cell-birth_date="{ row }">{{ fmtDate((row as ExamApplication).student.date_of_birth) }}</template>
      <template #cell-address="{ row }">{{ (row as ExamApplication).student.address ?? '—' }}</template>
      <template #cell-room_number="{ row }">{{ (row as ExamApplication).classroom?.name ?? '—' }}</template>
      <template #cell-table_no="{ row }">{{ (row as ExamApplication).table?.name ?? (row as ExamApplication).table_no ?? '—' }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ExamApplicationFormModal v-model="formModalOpen" :initial-enrollment-code="editingEnrollmentCode" @saved="fetch" />
    <PrintExamApplicationModal v-model="printModalOpen" :application="printTarget" @saved="fetch" />
  </div>
</template>
