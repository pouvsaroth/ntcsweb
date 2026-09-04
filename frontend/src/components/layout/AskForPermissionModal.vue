<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { myLeaveRequestsService } from '@/services/leaveRequests'
import { ApiRequestError } from '@/types/api'

/**
 * A student's self-submitted leave/permission request — launched from
 * PublicUserMenu's "Ask for Permission" entry. Starts pending; an admin
 * approves or rejects it from the "Leave Requests" page under Settings (see
 * LeaveRequests.vue), and approving syncs matching class days into the
 * student's attendance as Excused (LeaveRequestService::approve()).
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const form = reactive({ from_date: '', to_date: '', from_time: '', to_time: '', reason: '' })
const attachments = ref<File[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return

    form.from_date = ''
    form.to_date = ''
    form.from_time = ''
    form.to_time = ''
    form.reason = ''
    attachments.value = []
    errors.value = {}
    generalError.value = null
    submitted.value = false
  },
)

function onFilesChange(event: Event) {
  const files = (event.target as HTMLInputElement).files
  if (!files) return

  attachments.value = [...attachments.value, ...Array.from(files)]
  ;(event.target as HTMLInputElement).value = ''
}

function removeFile(index: number) {
  attachments.value = attachments.value.filter((_, i) => i !== index)
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await myLeaveRequestsService.submit({
      from_date: form.from_date,
      to_date: form.to_date,
      from_time: form.from_time || null,
      to_time: form.to_time || null,
      reason: form.reason,
      attachments: attachments.value,
    })
    submitted.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('leaveRequest.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('leaveRequest.title')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <BaseAlert v-if="submitted" variant="success">{{ t('leaveRequest.submitSuccess') }}</BaseAlert>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.from_date" type="date" required :label="t('leaveRequest.fromDate')" :error="errors.from_date?.[0]" />
        <BaseInput v-model="form.to_date" type="date" required :label="t('leaveRequest.toDate')" :error="errors.to_date?.[0]" />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.from_time" type="time" :label="t('leaveRequest.fromTime')" :error="errors.from_time?.[0]" />
        <BaseInput v-model="form.to_time" type="time" :label="t('leaveRequest.toTime')" :error="errors.to_time?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('leaveRequest.reason') }} <span class="text-danger-600">*</span>
        </label>
        <textarea
          v-model="form.reason"
          rows="3"
          required
          :placeholder="t('leaveRequest.reasonPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('leaveRequest.attachments') }}</label>
        <input
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp,application/pdf"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onFilesChange"
        />
        <p class="mt-1.5 text-xs text-neutral-500">{{ t('leaveRequest.attachmentsHint') }}</p>
        <ul v-if="attachments.length" class="mt-2 space-y-1">
          <li
            v-for="(file, index) in attachments"
            :key="`${file.name}-${index}`"
            class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-1.5 text-sm text-neutral-700"
          >
            <span class="truncate">{{ file.name }}</span>
            <button type="button" class="ml-2 shrink-0 text-neutral-400 hover:text-danger-600" @click="removeFile(index)">
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>
        <p v-if="errors['attachments.0']?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors['attachments.0'][0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" @click="submit">{{ t('leaveRequest.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
