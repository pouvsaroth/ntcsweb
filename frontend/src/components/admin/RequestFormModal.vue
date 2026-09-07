<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { myApprovalRequestsService } from '@/services/approvalRequests'
import type { FormTemplate } from '@/services/formTemplates'
import { ApiRequestError } from '@/types/api'

/**
 * Submits a request against a chosen FormTemplate from the eApprovals Forms
 * catalog — see Forms.vue. Every template shares this same generic
 * subject+details shape (see ApprovalRequest's own docblock for why).
 */
const props = defineProps<{
  modelValue: boolean
  template: FormTemplate | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; submitted: [] }>()

const { t } = useI18n()

const form = reactive({ subject: '', details: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

watch(
  () => [props.modelValue, props.template] as const,
  ([open]) => {
    if (!open) return

    form.subject = props.template?.name ?? ''
    form.details = ''
    errors.value = {}
    generalError.value = null
    submitted.value = false
  },
  { immediate: true },
)

async function submit() {
  if (!props.template) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await myApprovalRequestsService.submit({
      form_template_id: props.template.id,
      subject: form.subject,
      details: form.details || null,
    })
    submitted.value = true
    emit('submitted')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.forms.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="template ? `${template.code} — ${template.name}` : ''"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <BaseAlert v-if="submitted" variant="success">{{ t('admin.forms.submitSuccess') }}</BaseAlert>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.subject" required :label="t('admin.forms.subject')" :error="errors.subject?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.forms.details') }}</label>
        <textarea
          v-model="form.details"
          rows="4"
          :placeholder="t('admin.forms.detailsPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.details?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.details[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" @click="submit">{{ t('admin.forms.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
