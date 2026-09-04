<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import {
  enrollmentsService,
  enrollmentStatusesManageable,
  enrollmentStatusesRequiringReason,
  type Enrollment,
  type EnrollmentStatus,
} from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

function statusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusOptions = enrollmentStatusesManageable.map((status) => ({ value: status, label: t(`admin.enrollments.status${statusKey(status)}`) }))

const form = reactive({
  status: 'active' as EnrollmentStatus,
  reason: '',
  effective_date: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const reasonRequired = computed(() => enrollmentStatusesRequiringReason.includes(form.status))

watch(
  () => [props.modelValue, props.enrollment] as const,
  ([open, enrollment]) => {
    if (!open || !enrollment) return

    form.status = enrollment.status === 'dropped' ? 'active' : enrollment.status
    form.reason = enrollment.status_reason ?? ''
    form.effective_date = enrollment.status_effective_date ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (!props.enrollment) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await enrollmentsService.changeStatus(props.enrollment.id, {
      status: form.status,
      reason: reasonRequired.value ? form.reason : null,
      effective_date: reasonRequired.value ? form.effective_date : null,
    })
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.statusSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.enrollments.changeStatus')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="enrollment" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }} — {{ enrollment.course_package?.name ?? enrollment.book?.title ?? '—' }}
      </div>

      <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.enrollments.status')" :error="errors.status?.[0]" />

      <template v-if="reasonRequired">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ t('admin.enrollments.statusReason') }} <span class="text-danger-600">*</span>
          </label>
          <textarea
            v-model="form.reason"
            rows="3"
            required
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
        </div>

        <BaseInput
          v-model="form.effective_date"
          type="date"
          required
          :label="t('admin.enrollments.statusEffectiveDate')"
          :error="errors.effective_date?.[0]"
        />
      </template>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
