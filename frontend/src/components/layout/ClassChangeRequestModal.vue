<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myClassChangeRequestsService, type ClassChangeOptions } from '@/services/classChangeRequests'
import { ApiRequestError } from '@/types/api'

/**
 * A student's request to change class (eApprovals > My Request): the class
 * they are in now (their active enrollments; preselected when there is one),
 * the start date (ថ្ងៃចាប់ផ្តើមរៀន) and the class they want, from every open
 * class. Submitting (ដាក់ស្នើ) sends it to Approvals; once approved the
 * student is moved and the history saved — the enrollment itself stays.
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; submitted: [] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const options = ref<ClassChangeOptions>({ enrollments: [], classes: [] })
const loading = ref(false)
const loadError = ref<string | null>(null)
const form = reactive({ enrollment_id: '', start_date: '', to_class_id: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

const current = computed(() => options.value.enrollments.find((e) => String(e.id) === form.enrollment_id) ?? null)
const enrollmentOptions = computed(() =>
  options.value.enrollments.map((e) => ({ value: String(e.id), label: [e.class?.name, e.course].filter(Boolean).join(' — ') || e.code || `#${e.id}` })),
)
const classOptions = computed(() =>
  options.value.classes
    .filter((c) => c.id !== current.value?.class?.id)
    .map((c) => ({ value: String(c.id), label: [c.name, c.grade_level, c.academic_year].filter(Boolean).join(' · ') })),
)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    Object.assign(form, { enrollment_id: '', start_date: today(), to_class_id: '', reason: '' })
    errors.value = {}
    generalError.value = null
    submitted.value = false
    loading.value = true
    loadError.value = null
    try {
      options.value = await myClassChangeRequestsService.options()
      const first = options.value.enrollments.find((e) => !e.has_pending_request) ?? options.value.enrollments[0]
      form.enrollment_id = first ? String(first.id) : ''
    } catch (e) {
      loadError.value = e instanceof ApiRequestError ? e.message : t('classChangeRequest.loadFailed')
    } finally {
      loading.value = false
    }
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    await myClassChangeRequestsService.submit({
      enrollment_id: Number(form.enrollment_id),
      to_class_id: Number(form.to_class_id),
      start_date: form.start_date,
      reason: form.reason.trim(),
    })
    submitted.value = true
    emit('submitted')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else generalError.value = e instanceof ApiRequestError ? e.message : t('classChangeRequest.submitFailed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('classChangeRequest.title')" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="submitted" variant="success">{{ t('classChangeRequest.submitSuccess') }}</BaseAlert>

    <template v-else>
      <div v-if="loading" class="flex justify-center py-6"><BaseSpinner /></div>

      <form v-else class="space-y-4" @submit.prevent="submit">
        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
        <BaseAlert v-if="loadError" variant="danger">{{ loadError }}</BaseAlert>
        <BaseAlert v-else-if="options.enrollments.length === 0" variant="warning">{{ t('classChangeRequest.noActiveClass') }}</BaseAlert>

        <BaseSelect
          v-if="options.enrollments.length > 1"
          v-model="form.enrollment_id"
          :options="enrollmentOptions"
          required
          :label="t('classChangeRequest.currentClass')"
          :error="errors.enrollment_id?.[0]"
        />
        <div v-else-if="current" class="text-sm">
          <p class="text-neutral-500">{{ t('classChangeRequest.currentClass') }}</p>
          <p class="font-medium text-neutral-900">{{ enrollmentOptions[0]?.label }}</p>
          <p v-if="errors.enrollment_id?.[0]" class="mt-1 text-xs text-red-600">{{ errors.enrollment_id[0] }}</p>
        </div>
        <BaseAlert v-if="current?.has_pending_request" variant="warning">{{ t('classChangeRequest.alreadyWaiting') }}</BaseAlert>

        <BaseInput v-model="form.start_date" type="date" required :label="t('classChangeRequest.startDate')" :error="errors.start_date?.[0]" />
        <BaseSelect
          v-model="form.to_class_id"
          :options="classOptions"
          required
          :label="t('classChangeRequest.newClass')"
          :placeholder="t('classChangeRequest.newClassPlaceholder')"
          :error="errors.to_class_id?.[0]"
        />
        <BaseInput v-model="form.reason" required :label="t('classChangeRequest.reason')" :error="errors.reason?.[0]" />
        <p class="text-xs text-neutral-500">{{ t('classChangeRequest.hint') }}</p>
      </form>
    </template>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" :disabled="!form.enrollment_id || !form.to_class_id || !form.start_date || !form.reason.trim()" @click="submit">
        {{ t('classChangeRequest.submit') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
