<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { examApplicationsService } from '@/services/examApplications'
import { ApiRequestError } from '@/types/api'

/** Examination → Certificate's "Received Photo": the date (today by default) and a remark for every selected student. */
const props = defineProps<{ modelValue: boolean; ids: number[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const receivedDate = ref(today())
const remark = ref('')
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    receivedDate.value = today()
    remark.value = ''
    errors.value = {}
    generalError.value = null
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    await examApplicationsService.markPhotoReceived(props.ids, receivedDate.value, remark.value.trim() || null)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
      if (error.errors.ids) generalError.value = error.errors.ids[0] ?? null
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.examCertificate.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.examCertificate.modalTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <p class="text-sm text-neutral-600">{{ t('admin.examCertificate.modalSelected', ids.length) }}</p>

      <BaseInput
        v-model="receivedDate"
        type="date"
        required
        :label="t('admin.examCertificate.receivedDate')"
        :error="errors.received_date?.[0]"
      />

      <div>
        <label for="photo-received-remark" class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.examCertificate.remark') }}</label>
        <textarea
          id="photo-received-remark"
          v-model="remark"
          rows="3"
          maxlength="500"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.remark?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.remark[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!receivedDate || ids.length === 0" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
