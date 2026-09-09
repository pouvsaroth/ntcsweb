<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import { staffService, type Staff, type StaffStatus } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  staff: Staff | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({
  status: 'active' as StaffStatus,
  reason: '',
  requested_date: '',
  effective_date: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.staff] as const,
  ([open, staff]) => {
    if (!open || !staff) return

    form.status = staff.status
    // Blank on every open — a new status change is a fresh reason, not an
    // edit of the last one (the full history is on its own page).
    form.reason = ''
    form.requested_date = ''
    form.effective_date = ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (!props.staff) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await staffService.changeStatus(props.staff.id, {
      status: form.status,
      reason: form.reason,
      requested_date: form.requested_date,
      effective_date: form.effective_date,
    })
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.staff.statusSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.staff.changeStatus')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="staff" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ staff.full_name }} — {{ staff.employee_code }}
      </div>

      <LookupSelect
        v-model="form.status"
        category="STAFF_STATUS"
        :label="t('admin.staff.status')"
        :error="errors.status?.[0]"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.staff.statusReason') }} <span class="text-danger-600">*</span>
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
        v-model="form.requested_date"
        type="date"
        required
        :label="t('admin.staff.statusRequestedDate')"
        :hint="t('admin.staff.statusRequestedDateHint')"
        :error="errors.requested_date?.[0]"
      />

      <BaseInput
        v-model="form.effective_date"
        type="date"
        required
        :label="t('admin.staff.statusEffectiveDate')"
        :hint="t('admin.staff.statusEffectiveDateHint')"
        :error="errors.effective_date?.[0]"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
