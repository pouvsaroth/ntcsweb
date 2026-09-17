<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { examApplicationsService } from '@/services/examApplications'
import { ApiRequestError } from '@/types/api'

/**
 * Bulk "Send to Exam" from a class roster (see ClassStudents.vue): the
 * teacher picks one exam date/time/remark that applies to every checked
 * student, then this fires one POST /exam-applications per enrollment (no
 * bulk-create endpoint exists) — same book/room/table-less shape a student's
 * own self-submission starts with; those get filled in later from the
 * Application Form.
 */
const props = defineProps<{ modelValue: boolean; enrollmentIds: number[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [count: number] }>()

const { t } = useI18n()

const form = reactive({
  exam_date: '',
  exam_time: '',
  exam_time_out: '',
  remark: '',
})

const submitting = ref(false)
const generalError = ref<string | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.exam_date = ''
    form.exam_time = ''
    form.exam_time_out = ''
    form.remark = ''
    generalError.value = null
  },
)

async function submit() {
  if (props.enrollmentIds.length === 0) return

  submitting.value = true
  generalError.value = null

  try {
    for (const enrollmentId of props.enrollmentIds) {
      await examApplicationsService.create(enrollmentId, {
        status: 'pending',
        exam_date: form.exam_date || null,
        exam_time: form.exam_time || null,
        exam_time_out: form.exam_time_out || null,
        remark: form.remark || null,
      })
    }
    emit('saved', props.enrollmentIds.length)
    emit('update:modelValue', false)
  } catch (error) {
    generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.sendToExamFailed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.classStudents.sendToExam')" @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.classStudents.sendToExamCount', { count: enrollmentIds.length }) }}</p>

    <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

    <form class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2" @submit.prevent="submit">
      <BaseInput v-model="form.exam_date" type="date" :label="t('admin.exams.examDate')" />
      <BaseInput v-model="form.remark" :label="t('admin.exams.remark')" />
      <BaseInput v-model="form.exam_time" type="time" :label="t('admin.exams.timeIn')" />
      <BaseInput v-model="form.exam_time_out" type="time" :label="t('admin.exams.timeOut')" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('admin.exams.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
