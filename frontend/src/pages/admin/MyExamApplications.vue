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
import AddressSelects from '@/components/admin/AddressSelects.vue'
import {
  myExamApplicationsService,
  type ExamApplicationStatus,
  type ExamFee,
  type MyExamApplication,
  type MyExamApplicationEnrollment,
  type MyExamApplicationLookup,
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

// not_exam has no tab of its own — a row with that status simply never
// matches `activeTab` and stays invisible, same as any other status this
// page doesn't have a tab for. Still typed here for correctness.
const statusVariant: Record<ExamApplicationStatus, 'warning' | 'success' | 'danger' | 'neutral'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  not_exam: 'neutral',
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

/**
 * Two steps, same as the admin's Application Form "Show Data" flow: pick a
 * course, then review/complete the information it loads. Unlike the admin
 * form, the student only ever edits their own personal info — exam-day
 * logistics (book/room/table/date) are display-only, whatever a teacher/
 * admin has already set (or blank, if nobody has yet — see
 * ExamApplicationService::applyOnline() on the backend).
 */
const createOpen = ref(false)
const createForm = reactive({
  enrollment_id: '',
  first_name: '',
  last_name: '',
  english_name: '',
  gender: '',
  date_of_birth: '',
  phone: '',
  village_code: '',
  has_paid: false,
})
const enrollments = ref<MyExamApplicationEnrollment[]>([])
const enrollmentsLoading = ref(false)
const enrollmentsError = ref<string | null>(null)
const fee = ref<ExamFee | null>(null)
const feeLoading = ref(false)
const createErrors = ref<Record<string, string[]>>({})
const createGeneralError = ref<string | null>(null)
const creating = ref(false)

const lookup = ref<MyExamApplicationLookup | null>(null)
const lookupLoading = ref(false)
const lookupError = ref<string | null>(null)

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const uploadInput = ref<HTMLInputElement | null>(null)
const backCameraInput = ref<HTMLInputElement | null>(null)
const frontCameraInput = ref<HTMLInputElement | null>(null)

const enrollmentOptions = computed(() =>
  enrollments.value.map((enrollment) => ({
    value: String(enrollment.id),
    label: [enrollment.course_package?.name, enrollment.school_class?.name].filter(Boolean).join(' — ') || `#${enrollment.id}`,
  })),
)

async function openCreate() {
  createForm.enrollment_id = ''
  createForm.first_name = ''
  createForm.last_name = ''
  createForm.english_name = ''
  createForm.gender = ''
  createForm.date_of_birth = ''
  createForm.phone = ''
  createForm.village_code = ''
  createForm.has_paid = false
  createErrors.value = {}
  createGeneralError.value = null
  lookup.value = null
  lookupError.value = null
  photoFile.value = null
  photoPreview.value = null
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

async function pickEnrollment(value: string) {
  createForm.enrollment_id = value
  lookup.value = null
  lookupError.value = null
  photoFile.value = null
  photoPreview.value = null

  if (!value) return

  lookupLoading.value = true
  try {
    lookup.value = await myExamApplicationsService.lookup(Number(value))
    const student = lookup.value.student
    createForm.first_name = student.first_name
    createForm.last_name = student.last_name
    createForm.english_name = student.english_name ?? ''
    createForm.gender = student.gender ?? ''
    createForm.date_of_birth = student.date_of_birth ?? ''
    createForm.phone = student.phone ?? ''
    createForm.village_code = student.village_code ?? ''
    photoPreview.value = student.photo_url
  } catch (e) {
    lookupError.value = e instanceof ApiRequestError ? e.message : t('admin.myExamApplications.lookupFailed')
  } finally {
    lookupLoading.value = false
  }
}

function onPhotoChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
  input.value = ''
}

async function submitCreate() {
  if (!lookup.value) return

  creating.value = true
  createErrors.value = {}
  createGeneralError.value = null

  try {
    await myExamApplicationsService.submit({
      enrollment_id: Number(createForm.enrollment_id),
      first_name: createForm.first_name,
      last_name: createForm.last_name,
      english_name: createForm.english_name,
      gender: createForm.gender,
      date_of_birth: createForm.date_of_birth,
      phone: createForm.phone,
      village_code: createForm.village_code,
      photo: photoFile.value,
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
    <BaseModal v-model="createOpen" :title="t('admin.myExamApplications.createTitle')" size="lg">
      <form class="space-y-4" @submit.prevent="submitCreate">
        <BaseAlert v-if="createGeneralError" variant="danger">{{ createGeneralError }}</BaseAlert>
        <BaseAlert v-if="enrollmentsError" variant="danger">{{ enrollmentsError }}</BaseAlert>
        <BaseAlert v-if="lookupError" variant="danger">{{ lookupError }}</BaseAlert>

        <BaseSelect
          :model-value="createForm.enrollment_id"
          :label="t('admin.myExamApplications.enrollmentLabel')"
          :placeholder="t('admin.myExamApplications.selectEnrollment')"
          :options="enrollmentOptions"
          :disabled="enrollmentsLoading"
          required
          :error="createErrors.enrollment_id?.[0]"
          @update:model-value="pickEnrollment"
        />

        <div v-if="lookupLoading" class="flex justify-center py-8"><BaseSpinner /></div>

        <p v-else-if="!lookup" class="py-8 text-center text-sm text-neutral-400">{{ t('admin.myExamApplications.selectCoursePrompt') }}</p>

        <template v-else>
          <!-- Student Information — the same fields the admin's Application
               Form edits, just self-service: this always updates the
               student's own real record, no permission needed. -->
          <section>
            <h3 class="mb-3 border-b border-neutral-200 pb-2 text-sm font-semibold text-primary-800">{{ t('admin.exams.studentInformation') }}</h3>

            <div class="mb-4 flex items-center gap-4">
              <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-neutral-100">
                <img v-if="photoPreview" :src="photoPreview" alt="" class="h-full w-full object-cover" />
                <svg v-else class="h-10 w-10 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0ZM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
                  @click="uploadInput?.click()"
                >
                  {{ t('common.uploadPhoto') }}
                </button>
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
                  @click="backCameraInput?.click()"
                >
                  {{ t('common.takePhotoBack') }}
                </button>
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
                  @click="frontCameraInput?.click()"
                >
                  {{ t('common.takePhotoFront') }}
                </button>
              </div>
              <input ref="uploadInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="onPhotoChange" />
              <input ref="backCameraInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" capture="environment" class="hidden" @change="onPhotoChange" />
              <input ref="frontCameraInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" capture="user" class="hidden" @change="onPhotoChange" />
            </div>

            <div class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
              <BaseInput v-model="createForm.first_name" required :label="t('admin.exams.firstName')" :error="createErrors.first_name?.[0]" />
              <BaseInput v-model="createForm.last_name" required :label="t('admin.exams.lastName')" :error="createErrors.last_name?.[0]" />
              <BaseInput v-model="createForm.english_name" :label="t('admin.exams.otherName')" :error="createErrors.english_name?.[0]" />

              <div>
                <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.exams.sex') }}</label>
                <div class="flex items-center gap-4 pt-2">
                  <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                    <input v-model="createForm.gender" type="radio" value="male" />
                    {{ t('admin.exams.genderMale') }}
                  </label>
                  <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                    <input v-model="createForm.gender" type="radio" value="female" />
                    {{ t('admin.exams.genderFemale') }}
                  </label>
                </div>
              </div>

              <BaseInput v-model="createForm.date_of_birth" type="date" :label="t('admin.exams.birthDate')" :error="createErrors.date_of_birth?.[0]" />
              <BaseInput v-model="createForm.phone" :label="t('admin.exams.phone')" :error="createErrors.phone?.[0]" />

              <AddressSelects v-model="createForm.village_code" />
            </div>
          </section>

          <!-- Examination Information — display-only. Only a teacher/admin
               ever sets these, via the Examination tab. -->
          <section>
            <h3 class="mb-3 border-b border-neutral-200 pb-2 text-sm font-semibold text-primary-800">{{ t('admin.exams.examinationInformation') }}</h3>

            <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
              <div><dt class="text-neutral-500">{{ t('admin.exams.course') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.course_package?.name ?? '—' }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.exams.book') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application?.book?.title ?? t('admin.myExamApplications.notScheduledYet') }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.exams.examDate') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application?.exam_date ? formatDate(lookup.exam_application.exam_date) : t('admin.myExamApplications.notScheduledYet') }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.exams.columnTimeExam') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application ? timeRange(lookup.exam_application) : t('admin.myExamApplications.notScheduledYet') }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.exams.roomNumber') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application?.classroom?.name ?? t('admin.myExamApplications.notScheduledYet') }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.exams.tableNumber') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application?.table?.name ?? lookup.exam_application?.table_no ?? t('admin.myExamApplications.notScheduledYet') }}</dd></div>
              <div v-if="lookup.exam_application?.remark"><dt class="text-neutral-500">{{ t('admin.exams.remark') }}</dt><dd class="font-medium text-neutral-900">{{ lookup.exam_application.remark }}</dd></div>
            </dl>
          </section>

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
        </template>
      </form>

      <template #footer>
        <BaseButton variant="outline" @click="createOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!lookup" :loading="creating" @click="submitCreate">{{ t('admin.myExamApplications.submit') }}</BaseButton>
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
