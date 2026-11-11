<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({
  enrolled_at: '',
  start_study_on: '',
})

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Only while Not started / Studying does the status follow the date. */
const startStudyHint = computed(() => {
  if (!props.enrollment || !['not_started', 'active'].includes(props.enrollment.status) || !form.start_study_on) return undefined
  return form.start_study_on > todayIso()
    ? t('admin.enrollments.startStudyLater', { date: formatDate(form.start_study_on) })
    : t('admin.enrollments.startStudyNow', { date: formatDate(form.start_study_on) })
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.enrollment] as const,
  ([open, enrollment]) => {
    if (!open || !enrollment) return

    form.enrolled_at = enrollment.enrolled_at
    form.start_study_on = enrollment.start_study_on ?? enrollment.enrolled_at
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
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }} — {{ enrollment.course_package?.name ?? '—' }}
      </div>

      <BaseInput v-model="form.enrolled_at" type="date" required :label="t('admin.enrollments.enrolledAt')" :error="errors.enrolled_at?.[0]" />
      <BaseInput
        v-model="form.start_study_on"
        type="date"
        required
        :label="t('admin.enrollments.startStudyOn')"
        :hint="startStudyHint"
        :error="errors.start_study_on?.[0]"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
