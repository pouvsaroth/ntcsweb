<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { trainingRecordsService, type TrainingParticipant } from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Issue a training certificate to one person, or edit one: number (made up
 * when left blank), issue and expiry dates, and an optional scan. It is also
 * kept under Teachers > Qualification as a certificate.
 */
const props = defineProps<{ modelValue: boolean; participant: TrainingParticipant | null; trainingTitle?: string }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [TrainingParticipant] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({ certificate_no: '', issued_on: '', expires_on: '' })
const file = ref<File | null>(null)
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    file.value = null
    const c = props.participant?.certificate
    const end = props.participant?.training?.end_date
    Object.assign(form, {
      certificate_no: c?.no ?? '',
      issued_on: c?.issued_on ?? (end && end < todayIso() ? end : todayIso()),
      expires_on: c?.expires_on ?? '',
    })
  },
)

function onFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function save() {
  if (!props.participant) return
  saving.value = true
  errors.value = {}
  error.value = null
  try {
    const saved = await trainingRecordsService.issueCertificate(props.participant.id, {
      certificate_no: form.certificate_no.trim() || null,
      issued_on: form.issued_on,
      expires_on: form.expires_on || null,
      file: file.value,
    })
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="participant?.certificate ? t('proDev.certificates.editTitle') : t('proDev.certificates.issueTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="training-certificate-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div v-if="participant" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm">
        <p class="font-medium text-neutral-900">{{ participant.staff?.name }}</p>
        <p class="text-neutral-600">
          {{ participant.training?.title ?? trainingTitle }}
          <template v-if="participant.training"> · {{ formatDate(participant.training.start_date) }}</template>
        </p>
      </div>

      <BaseInput
        v-model="form.certificate_no"
        :label="t('proDev.certificates.number')"
        :placeholder="t('proDev.certificates.numberPlaceholder')"
        :hint="t('proDev.certificates.numberHint')"
        :error="errors.certificate_no?.[0]"
      />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.issued_on" type="date" required :label="t('proDev.certificates.issuedOn')" :error="errors.issued_on?.[0]" />
        <BaseInput v-model="form.expires_on" type="date" :label="t('proDev.certificates.expiresOn')" :error="errors.expires_on?.[0]" />
      </div>

      <div>
        <label for="training-certificate-file" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.certificates.file') }}</label>
        <input
          id="training-certificate-file"
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,.webp"
          class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-md file:border-0 file:bg-neutral-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-neutral-200"
          @change="onFile"
        />
        <p class="mt-1.5 text-xs text-neutral-500">
          {{ participant?.certificate?.file_name ? t('proDev.certificates.fileReplace', { name: participant.certificate.file_name }) : t('proDev.certificates.fileHint') }}
        </p>
        <p v-if="errors.file?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.file[0] }}</p>
      </div>
      <p class="text-xs text-neutral-500">{{ t('proDev.certificates.qualificationNote') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="training-certificate-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
