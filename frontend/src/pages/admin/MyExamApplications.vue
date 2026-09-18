<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  myExamApplicationsService,
  type ExamApplicationStatus,
  type ExamFee,
  type MyExamApplication,
  type MyExamApplicationEnrollment,
} from '@/services/myExamApplications'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * A student's own exam applications — submit one against an active
 * enrollment, then track it through pending/approved/rejected. See
 * MyExamApplicationController's docblock; approval/printing happens on the
 * admin side (ExamApplications.vue's approval queue).
 */
const { t } = useI18n()

const rows = ref<MyExamApplication[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const activeTab = ref<ExamApplicationStatus>('pending')

const tabs: { key: ExamApplicationStatus; labelKey: string }[] = [
  { key: 'pending', labelKey: 'admin.myExamApplications.tabPending' },
  { key: 'approved', labelKey: 'admin.myExamApplications.tabApproved' },
  { key: 'rejected', labelKey: 'admin.myExamApplications.tabRejected' },
]

const counts = computed(() => ({
  pending: rows.value.filter((r) => r.status === 'pending').length,
  approved: rows.value.filter((r) => r.status === 'approved').length,
  rejected: rows.value.filter((r) => r.status === 'rejected').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

// Same columns the admin's Examination tab shows (see Exams.vue) — just
// scoped to this student's own rows and read-only, no room/table/book
// reassignment or student-record editing.
const columns = [
  { key: 'date', label: t('admin.myExamApplications.columnDate') },
  { key: 'course', label: t('admin.myExamApplications.columnCourse') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'examDate', label: t('admin.myExamApplications.columnExamDate') },
  { key: 'timeExam', label: t('admin.exams.columnTimeExam') },
  { key: 'room', label: t('admin.exams.columnRoomNumber') },
  { key: 'table', label: t('admin.myExamApplications.columnTable') },
  { key: 'buyDate', label: t('admin.exams.columnBuyDate') },
  { key: 'receiveDate', label: t('admin.exams.columnReceiveDate') },
  { key: 'status', label: t('admin.myExamApplications.columnStatus') },
]

function timeRange(row: MyExamApplication): string {
  const timeIn = row.exam_time?.slice(0, 5)
  const timeOut = row.exam_time_out?.slice(0, 5)
  if (!timeIn && !timeOut) return '—'
  return `${timeIn ?? '—'} - ${timeOut ?? '—'}`
}

const statusVariant: Record<ExamApplicationStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const result = await myExamApplicationsService.list({ page: 1, per_page: 100, filter: {} })
    rows.value = result.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myExamApplications.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- New application ---

const createOpen = ref(false)
const createForm = reactive({ enrollment_id: '', exam_date: '', exam_time: '', table_no: '', has_paid: false })
const enrollments = ref<MyExamApplicationEnrollment[]>([])
const enrollmentsLoading = ref(false)
const enrollmentsError = ref<string | null>(null)
const fee = ref<ExamFee | null>(null)
const feeLoading = ref(false)
const createErrors = ref<Record<string, string[]>>({})
const createGeneralError = ref<string | null>(null)
const creating = ref(false)

const enrollmentOptions = computed(() =>
  enrollments.value.map((enrollment) => ({
    value: String(enrollment.id),
    label: [enrollment.course_package?.name, enrollment.school_class?.name].filter(Boolean).join(' — ') || `#${enrollment.id}`,
  })),
)

async function openCreate() {
  createForm.enrollment_id = ''
  createForm.exam_date = ''
  createForm.exam_time = ''
  createForm.table_no = ''
  createForm.has_paid = false
  createErrors.value = {}
  createGeneralError.value = null
  createOpen.value = true

  if (enrollments.value.length === 0 && !enrollmentsLoading.value) {
    enrollmentsLoading.value = true
    enrollmentsError.value = null
    try {
      enrollments.value = await myExamApplicationsService.enrollments()
    } catch (e) {
      enrollmentsError.value = e instanceof ApiRequestError ? e.message : t('admin.myExamApplications.enrollmentsLoadFailed')
    } finally {
      enrollmentsLoading.value = false
    }
  }

  if (fee.value === null && !feeLoading.value) {
    feeLoading.value = true
    try {
      fee.value = await myExamApplicationsService.fee()
    } catch {
      // Shown as "—" below; submission still works, the backend is the source of truth on the fee.
    } finally {
      feeLoading.value = false
    }
  }
}

async function submitCreate() {
  creating.value = true
  createErrors.value = {}
  createGeneralError.value = null

  try {
    await myExamApplicationsService.submit({
      enrollment_id: Number(createForm.enrollment_id),
      exam_date: createForm.exam_date,
      exam_time: createForm.exam_time,
      table_no: createForm.table_no,
      has_paid: createForm.has_paid,
    })
    createOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      createErrors.value = e.errors
    } else {
      createGeneralError.value = e instanceof ApiRequestError ? e.message : t('admin.myExamApplications.submitFailed')
    }
  } finally {
    creating.value = false
  }
}

const detail = ref<MyExamApplication | null>(null)

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myExamApplications.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myExamApplications.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.myExamApplications.newButton') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 flex gap-1 border-b border-neutral-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="activeTab = tab.key"
      >
        {{ t(tab.labelKey) }} ({{ counts[tab.key] }})
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState
      v-else-if="visibleRows.length === 0"
      :title="t('admin.myExamApplications.emptyTitle')"
      :message="t('admin.myExamApplications.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="visibleRows" row-key="id">
      <template #cell-date="{ row }">{{ formatDate(row.created_at) }}</template>
      <template #cell-course="{ row }">
        <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row">
          {{ [row.enrollment.course_package?.name, row.enrollment.school_class?.name].filter(Boolean).join(' — ') || '—' }}
        </button>
      </template>
      <template #cell-book="{ row }">{{ row.book?.title ?? row.enrollment.course_package?.name ?? '—' }}</template>
      <template #cell-examDate="{ row }">{{ formatDate(row.exam_date) }}</template>
      <template #cell-timeExam="{ row }">{{ timeRange(row) }}</template>
      <template #cell-room="{ row }">{{ row.classroom?.name ?? '—' }}</template>
      <template #cell-table="{ row }">{{ row.table?.name ?? row.table_no ?? '—' }}</template>
      <template #cell-buyDate="{ row }">{{ row.sold_at ? formatDate(row.sold_at) : '—' }}</template>
      <template #cell-receiveDate="{ row }">{{ row.received_at ? formatDate(row.received_at) : '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.myExamApplications.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}</BaseBadge>
      </template>
    </DataTable>

    <!-- New application -->
    <BaseModal v-model="createOpen" :title="t('admin.myExamApplications.createTitle')">
      <form class="space-y-4" @submit.prevent="submitCreate">
        <BaseAlert v-if="createGeneralError" variant="danger">{{ createGeneralError }}</BaseAlert>
        <BaseAlert v-if="enrollmentsError" variant="danger">{{ enrollmentsError }}</BaseAlert>

        <BaseSelect
          v-model="createForm.enrollment_id"
          :label="t('admin.myExamApplications.enrollmentLabel')"
          :placeholder="t('admin.myExamApplications.selectEnrollment')"
          :options="enrollmentOptions"
          :disabled="enrollmentsLoading"
          required
          :error="createErrors.enrollment_id?.[0]"
        />

        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="createForm.exam_date" type="date" required :label="t('admin.myExamApplications.examDateLabel')" :error="createErrors.exam_date?.[0]" />
          <BaseInput v-model="createForm.exam_time" type="time" required :label="t('admin.myExamApplications.examTimeLabel')" :error="createErrors.exam_time?.[0]" />
        </div>

        <BaseInput v-model="createForm.table_no" required :label="t('admin.myExamApplications.tableLabel')" :error="createErrors.table_no?.[0]" />

        <div class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700">
          {{ t('admin.myExamApplications.feeLabel') }}:
          <span class="font-medium text-neutral-900">
            {{ feeLoading ? '…' : fee?.amount != null ? `${fee.amount} ${fee.currency ?? ''}` : t('admin.myExamApplications.feeNotSet') }}
          </span>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="createForm.has_paid" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.myExamApplications.hasPaidLabel') }}
        </label>
        <p v-if="createErrors.has_paid?.[0]" class="text-sm text-danger-600">{{ createErrors.has_paid[0] }}</p>
      </form>

      <template #footer>
        <BaseButton variant="outline" @click="createOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="creating" @click="submitCreate">{{ t('admin.myExamApplications.submit') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Detail -->
    <BaseModal :model-value="detail !== null" :title="t('admin.myExamApplications.detailTitle')" @update:model-value="detail = null">
      <template v-if="detail">
        <!-- The same information the admin's Examination tab shows for this
             row (see ExamApplicationFormModal.vue) — read-only here, since a
             student can't reassign their own room/table/book or edit their
             official student record. -->
        <dl class="grid grid-cols-1 gap-y-2 text-sm sm:grid-cols-2 sm:gap-x-4">
          <div><dt class="text-neutral-500">{{ t('admin.myExamApplications.columnCourse') }}</dt><dd class="font-medium text-neutral-900">{{ [detail.enrollment.course_package?.name, detail.enrollment.school_class?.name].filter(Boolean).join(' — ') || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnBook') }}</dt><dd class="font-medium text-neutral-900">{{ detail.book?.title ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.myExamApplications.columnExamDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.exam_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnTimeExam') }}</dt><dd class="font-medium text-neutral-900">{{ timeRange(detail) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnRoomNumber') }}</dt><dd class="font-medium text-neutral-900">{{ detail.classroom?.name ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.myExamApplications.columnTable') }}</dt><dd class="font-medium text-neutral-900">{{ detail.table?.name ?? detail.table_no ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnBuyDate') }}</dt><dd class="font-medium text-neutral-900">{{ detail.sold_at ? formatDate(detail.sold_at) : '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnReceiveDate') }}</dt><dd class="font-medium text-neutral-900">{{ detail.received_at ? formatDate(detail.received_at) : '—' }}</dd></div>
          <div v-if="detail.student?.date_of_birth"><dt class="text-neutral-500">{{ t('admin.exams.columnBirthDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.student.date_of_birth) }}</dd></div>
          <div v-if="detail.student?.address"><dt class="text-neutral-500">{{ t('admin.exams.columnAddress') }}</dt><dd class="font-medium text-neutral-900">{{ detail.student.address }}</dd></div>
          <div v-if="detail.remark"><dt class="text-neutral-500">{{ t('admin.exams.remark') }}</dt><dd class="font-medium text-neutral-900">{{ detail.remark }}</dd></div>
          <div v-if="detail.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.decision_reason }}</dd></div>
        </dl>
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
