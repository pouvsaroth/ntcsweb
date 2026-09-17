<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ExamApplicationFormModal from '@/components/admin/ExamApplicationFormModal.vue'
import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

const { t } = useI18n()
const auth = useAuthStore()

const canApprove = computed(() => auth.can('exam-applications.approve'))
const canReject = computed(() => auth.can('exam-applications.reject'))
// Every row on this tab is already status=pending (see the query below), so
// this is really "can edit a pending application" rather than a per-row check.
const canEdit = computed(() => auth.can('exam-applications.update'))

/**
 * Every pending application, regardless of who created it — an
 * admin-entered one still needs a decision same as a student's own
 * submission; the "Submitted" column just shows which is which.
 */
const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<ExamApplication>((query) =>
  examApplicationsService.list({ ...query, filter: { ...query.filter, status: 'pending' } }),
)

onMounted(() => void fetch())

const columns = [
  { key: 'student_code', label: t('admin.exams.columnStudentCode') },
  { key: 'full_name', label: t('admin.exams.columnFullName') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'exam_date', label: t('admin.exams.columnExamDate') },
  { key: 'time_exam', label: t('admin.exams.columnTimeExam') },
  { key: 'table_no', label: t('admin.exams.columnTableNumber') },
  { key: 'submitted_at', label: t('admin.exams.columnSubmittedAt') },
  { key: 'actions', label: t('admin.exams.columnActions') },
]

function fmtDate(value: string | null): string {
  return formatDate(value)
}

function timeRange(a: ExamApplication): string {
  const timeIn = a.exam_time?.slice(0, 5)
  const timeOut = a.exam_time_out?.slice(0, 5)
  if (!timeIn && !timeOut) return '—'
  return `${timeIn ?? '—'} - ${timeOut ?? '—'}`
}

const acting = ref<number | null>(null)
const actionError = ref<string | null>(null)

async function approve(application: ExamApplication) {
  acting.value = application.id
  actionError.value = null
  try {
    await examApplicationsService.approve(application.id)
    await fetch()
  } catch (err) {
    actionError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = null
  }
}

const rejectModalOpen = ref(false)
const rejectTarget = ref<ExamApplication | null>(null)
const rejectReason = ref('')
const rejectError = ref<string | null>(null)

function openReject(application: ExamApplication) {
  rejectTarget.value = application
  rejectReason.value = ''
  rejectError.value = null
  rejectModalOpen.value = true
}

async function submitReject() {
  if (!rejectTarget.value || !rejectReason.value.trim()) return
  acting.value = rejectTarget.value.id
  rejectError.value = null
  try {
    await examApplicationsService.reject(rejectTarget.value.id, rejectReason.value.trim())
    rejectModalOpen.value = false
    await fetch()
  } catch (err) {
    rejectError.value = err instanceof ApiRequestError ? err.message : t('admin.exams.actionFailed')
  } finally {
    acting.value = null
  }
}

const formModalOpen = ref(false)
const editingEnrollmentCode = ref<string | null>(null)

function openEdit(application: ExamApplication) {
  editingEnrollmentCode.value = application.enrollment_code
  formModalOpen.value = true
}
</script>

<template>
  <div>
    <ExaminationTabs />

    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.exams.approvalsTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.exams.approvalsSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.exams.approvalsEmptyMessage')">
      <template #cell-student_code="{ row }">{{ (row as ExamApplication).student.student_code }}</template>
      <template #cell-full_name="{ row }">{{ (row as ExamApplication).student.name }}</template>
      <template #cell-book="{ row }">{{ (row as ExamApplication).book?.title ?? (row as ExamApplication).enrollment.course_package?.name ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ fmtDate((row as ExamApplication).exam_date) }}</template>
      <template #cell-time_exam="{ row }">{{ timeRange(row as ExamApplication) }}</template>
      <template #cell-table_no="{ row }">{{ (row as ExamApplication).table?.name ?? (row as ExamApplication).table_no ?? '—' }}</template>
      <template #cell-submitted_at="{ row }">{{ (row as ExamApplication).student_marked_paid_at ? fmtDate((row as ExamApplication).student_marked_paid_at) : '—' }}</template>
      <template #cell-actions="{ row }">
        <div class="flex gap-2">
          <BaseButton
            v-if="canEdit"
            size="sm"
            variant="outline"
            :disabled="acting === (row as ExamApplication).id"
            @click="openEdit(row as ExamApplication)"
          >
            {{ t('common.edit') }}
          </BaseButton>
          <BaseButton
            v-if="canApprove"
            size="sm"
            :loading="acting === (row as ExamApplication).id"
            @click="approve(row as ExamApplication)"
          >
            {{ t('admin.exams.approve') }}
          </BaseButton>
          <BaseButton
            v-if="canReject"
            size="sm"
            variant="outline"
            :disabled="acting === (row as ExamApplication).id"
            @click="openReject(row as ExamApplication)"
          >
            {{ t('admin.exams.reject') }}
          </BaseButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="rejectModalOpen" :title="t('admin.exams.reject')">
      <BaseAlert v-if="rejectError" variant="danger" class="mb-4">{{ rejectError }}</BaseAlert>
      <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.exams.rejectReason') }}</label>
      <textarea
        v-model="rejectReason"
        rows="3"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
      ></textarea>

      <template #footer>
        <BaseButton variant="outline" @click="rejectModalOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :disabled="!rejectReason.trim()" :loading="acting === rejectTarget?.id" @click="submitReject">
          {{ t('admin.exams.reject') }}
        </BaseButton>
      </template>
    </BaseModal>

    <ExamApplicationFormModal v-model="formModalOpen" :initial-enrollment-code="editingEnrollmentCode" @saved="fetch" />
  </div>
</template>
