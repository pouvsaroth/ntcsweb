<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { makeUpClassCourseLabel, myMakeUpClassRequestsService, type MakeUpClassEnrollmentOption } from '@/services/makeUpClassRequests'
import { ApiRequestError } from '@/types/api'

/**
 * A student's own make-up class request (ស្នើសុំរៀនសង) — launched from the
 * student home screen's card (see Dashboard.vue). The Course picker lists
 * the student's active enrollments newest first and pre-selects the first
 * one as the current course; both dates default to today. Starts pending; a
 * school admin approves or rejects it from the eApprovals "Approvals" queue
 * (see admin/approvals/Approvals.vue).
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; submitted: [] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const enrollments = ref<MakeUpClassEnrollmentOption[]>([])
const enrollmentsLoading = ref(false)
const enrollmentsError = ref<string | null>(null)

const form = reactive({ enrollment_id: '', from_date: '', to_date: '', from_time: '', to_time: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

const courseOptions = computed(() =>
  enrollments.value.map((e) => ({ value: String(e.id), label: makeUpClassCourseLabel(e) })),
)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return

    form.enrollment_id = ''
    form.from_date = today()
    form.to_date = today()
    form.from_time = ''
    form.to_time = ''
    errors.value = {}
    generalError.value = null
    submitted.value = false

    enrollmentsLoading.value = true
    enrollmentsError.value = null
    try {
      enrollments.value = await myMakeUpClassRequestsService.enrollments()
      form.enrollment_id = enrollments.value[0] ? String(enrollments.value[0].id) : ''
    } catch (error) {
      enrollmentsError.value = error instanceof ApiRequestError ? error.message : t('makeUpClassRequest.coursesLoadFailed')
    } finally {
      enrollmentsLoading.value = false
    }
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await myMakeUpClassRequestsService.submit({
      enrollment_id: Number(form.enrollment_id),
      from_date: form.from_date,
      to_date: form.to_date,
      from_time: form.from_time,
      to_time: form.to_time,
    })
    submitted.value = true
    emit('submitted')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('makeUpClassRequest.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('makeUpClassRequest.title')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <BaseAlert v-if="submitted" variant="success">{{ t('makeUpClassRequest.submitSuccess') }}</BaseAlert>

    <template v-else>
      <div v-if="enrollmentsLoading" class="flex justify-center py-6"><BaseSpinner /></div>

      <form v-else class="space-y-4" @submit.prevent="submit">
        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
        <BaseAlert v-if="enrollmentsError" variant="danger">{{ enrollmentsError }}</BaseAlert>
        <BaseAlert v-else-if="enrollments.length === 0" variant="warning">{{ t('makeUpClassRequest.noActiveCourse') }}</BaseAlert>

        <BaseSelect
          v-model="form.enrollment_id"
          :options="courseOptions"
          required
          :label="t('makeUpClassRequest.course')"
          :placeholder="t('makeUpClassRequest.coursePlaceholder')"
          :error="errors.enrollment_id?.[0]"
        />

        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.from_date" type="date" required :label="t('makeUpClassRequest.fromDate')" :error="errors.from_date?.[0]" />
          <BaseInput v-model="form.to_date" type="date" required :label="t('makeUpClassRequest.toDate')" :error="errors.to_date?.[0]" />
          <BaseInput v-model="form.from_time" type="time" required :label="t('makeUpClassRequest.fromTime')" :error="errors.from_time?.[0]" />
          <BaseInput v-model="form.to_time" type="time" required :label="t('makeUpClassRequest.toTime')" :error="errors.to_time?.[0]" />
        </div>
      </form>
    </template>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" :disabled="enrollments.length === 0" @click="submit">{{ t('makeUpClassRequest.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
