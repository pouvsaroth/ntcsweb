<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myResignationRequestsService, type MyStaffProfile } from '@/services/resignationRequests'
import { ApiRequestError } from '@/types/api'

/**
 * A staff member's own resignation request — launched from the admin
 * panel's Staff/HRM nav group ("Form", see adminNav.ts) and from
 * MyRequests.vue's own "Resignation" button. First name/last name/gender/
 * position are auto-filled read-only from the signed-in staff's own record
 * (see MyResignationRequestController::profile()) rather than retyped;
 * only the resignation date and reason are actually entered here. Starts
 * pending; a school admin approves or rejects it from the eApprovals
 * "Approvals" queue (see admin/approvals/Approvals.vue).
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const profile = ref<MyStaffProfile | null>(null)
const profileLoading = ref(false)
const profileError = ref<string | null>(null)

const form = reactive({ resignation_date: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return

    form.resignation_date = ''
    form.reason = ''
    errors.value = {}
    generalError.value = null
    submitted.value = false

    profileLoading.value = true
    profileError.value = null
    try {
      profile.value = await myResignationRequestsService.profile()
    } catch (error) {
      profileError.value = error instanceof ApiRequestError ? error.message : t('resignationRequest.profileLoadFailed')
    } finally {
      profileLoading.value = false
    }
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await myResignationRequestsService.submit({
      resignation_date: form.resignation_date,
      reason: form.reason,
    })
    submitted.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('resignationRequest.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('resignationRequest.title')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <BaseAlert v-if="submitted" variant="success">{{ t('resignationRequest.submitSuccess') }}</BaseAlert>

    <template v-else>
      <div v-if="profileLoading" class="flex justify-center py-6"><BaseSpinner /></div>

      <form v-else class="space-y-4" @submit.prevent="submit">
        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
        <BaseAlert v-if="profileError" variant="danger">{{ profileError }}</BaseAlert>

        <dl v-if="profile" class="grid grid-cols-2 gap-3 rounded-lg bg-neutral-50 p-3 text-sm">
          <div><dt class="text-neutral-500">{{ t('resignationRequest.firstName') }}</dt><dd class="font-medium text-neutral-900">{{ profile.first_name || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.lastName') }}</dt><dd class="font-medium text-neutral-900">{{ profile.last_name || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.gender') }}</dt><dd class="font-medium text-neutral-900">{{ profile.gender || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.position') }}</dt><dd class="font-medium text-neutral-900">{{ profile.position || '—' }}</dd></div>
        </dl>

        <BaseInput
          v-model="form.resignation_date"
          type="date"
          required
          :label="t('resignationRequest.resignationDate')"
          :error="errors.resignation_date?.[0]"
        />

        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ t('resignationRequest.reason') }} <span class="text-danger-600">*</span>
          </label>
          <textarea
            v-model="form.reason"
            rows="3"
            required
            :placeholder="t('resignationRequest.reasonPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
        </div>
      </form>
    </template>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" @click="submit">{{ t('resignationRequest.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
