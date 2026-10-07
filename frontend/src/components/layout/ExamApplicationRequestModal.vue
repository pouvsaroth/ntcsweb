<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddressSelects from '@/components/admin/AddressSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import WebcamCaptureModal from '@/components/ui/WebcamCaptureModal.vue'
import {
  myExamApplicationsService,
  type ExamFee,
  type MyExamApplication,
  type MyExamApplicationEnrollment,
  type MyExamApplicationLookup,
} from '@/services/myExamApplications'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; submitted: [] }>()

const { t } = useI18n()

function timeRange(row: Pick<MyExamApplication, 'exam_time' | 'exam_time_out'>): string {
  const timeIn = row.exam_time?.slice(0, 5)
  const timeOut = row.exam_time_out?.slice(0, 5)
  if (!timeIn && !timeOut) return '—'
  return `${timeIn ?? '—'} - ${timeOut ?? '—'}`
}

/**
 * A student's own exam application form — launched from My Request's Exam
 * Application button and the My Exam Application page's New button. Two steps, same as the admin's Application Form "Show Data" flow: pick a
 * course, then review/complete the information it loads. Unlike the admin
 * form, the student only ever edits their own personal info — exam-day
 * logistics (book/room/table/date) are display-only, whatever a teacher/
 * admin has already set (or blank, if nobody has yet — see
 * ExamApplicationService::applyOnline() on the backend).
 */
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

/** KHR shows as a rounded, thousands-separated whole number (e.g. "15,000 ៛", never "15000.00") — see formatMoney's own docblock. */
const feeDisplay = computed(() => {
  if (fee.value?.amount == null) return null
  return formatMoney(Number(fee.value.amount), (fee.value.currency as 'USD' | 'KHR' | null) ?? 'USD')
})
const createErrors = ref<Record<string, string[]>>({})
const createGeneralError = ref<string | null>(null)
const creating = ref(false)

const lookup = ref<MyExamApplicationLookup | null>(null)
const lookupLoading = ref(false)
const lookupError = ref<string | null>(null)

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const uploadInput = ref<HTMLInputElement | null>(null)
const webcamModalOpen = ref(false)

const enrollmentOptions = computed(() =>
  enrollments.value.map((enrollment) => ({
    value: String(enrollment.id),
    label: [enrollment.course_package?.name, enrollment.school_class?.name].filter(Boolean).join(' — ') || `#${enrollment.id}`,
  })),
)

async function reset() {
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

  setPhotoFile(file)
  input.value = ''
}

function setPhotoFile(file: File) {
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
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
    emit('update:modelValue', false)
    emit('submitted')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      createErrors.value = e.errors

      // Every field error above renders inline next to its own input — but
      // a rule like "the school hasn't configured an exam fee yet" lands on
      // a key (`exam_fee_amount`) this form has no input for, and would
      // otherwise fail completely silently: the request rejected, nothing
      // visibly wrong. Anything on an unrendered key surfaces here instead.
      const renderedFields = new Set([
        'enrollment_id', 'first_name', 'last_name', 'english_name', 'gender',
        'date_of_birth', 'phone', 'village_code', 'photo', 'has_paid',
      ])
      const unmapped = Object.entries(e.errors)
        .filter(([field]) => !renderedFields.has(field))
        .flatMap(([, messages]) => messages)
      createGeneralError.value = unmapped.length > 0 ? unmapped.join(' ') : null
    } else {
      createGeneralError.value = e instanceof ApiRequestError ? e.message : t('admin.myExamApplications.submitFailed')
    }
  } finally {
    creating.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void reset()
  },
  { immediate: true },
)
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.myExamApplications.createTitle')" size="lg" @update:model-value="emit('update:modelValue', $event)">
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
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 7.5L12 3m0 0L7.5 7.5M12 3v13.5" />
                </svg>
                {{ t('common.uploadPhoto') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
                @click="webcamModalOpen = true"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.25 2.25 0 018.92 4.5h6.16c.891 0 1.71.484 2.083 1.267l.415.865a1.5 1.5 0 001.348.868h.334c1.036 0 1.875.84 1.875 1.875v9.375A2.25 2.25 0 0118.875 21H5.625a2.25 2.25 0 01-2.25-2.25V9.375c0-1.036.84-1.875 1.875-1.875h.334a1.5 1.5 0 001.35-.868l.893-.457z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                </svg>
                {{ t('common.takePhoto') }}
              </button>
            </div>
            <input ref="uploadInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="onPhotoChange" />
            <WebcamCaptureModal v-model="webcamModalOpen" @captured="setPhotoFile" />
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
            {{ feeLoading ? '…' : (feeDisplay ?? t('admin.myExamApplications.feeNotSet')) }}
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
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :disabled="!lookup" :loading="creating" @click="submitCreate">{{ t('admin.myExamApplications.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
