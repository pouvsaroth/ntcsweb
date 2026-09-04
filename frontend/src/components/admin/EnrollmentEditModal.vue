<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({
  enrolled_at: '',
  fee: 0,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.enrollment] as const,
  ([open, enrollment]) => {
    if (!open || !enrollment) return

    form.enrolled_at = enrollment.enrolled_at
    form.fee = enrollment.fee
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
    await enrollmentsService.update(props.enrollment.id, form)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('admin.enrollments.editTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form v-if="enrollment" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }} — {{ enrollment.book?.title ?? enrollment.course_package?.name ?? '—' }}
      </div>

      <BaseInput v-model="form.enrolled_at" type="date" required :label="t('admin.enrollments.enrolledAt')" :error="errors.enrolled_at?.[0]" />
      <BaseInput
        :model-value="String(form.fee)"
        type="number"
        required
        :label="t('admin.enrollments.fee')"
        :hint="t('admin.enrollments.feeHint')"
        :error="errors.fee?.[0]"
        @update:model-value="form.fee = Number($event) || 0"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
