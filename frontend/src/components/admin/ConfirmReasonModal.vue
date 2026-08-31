<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

/**
 * A small reusable "type a reason, then confirm" dialog — shared by every
 * billing action that requires one (invoice cancel/void, payment
 * cancel/refund all take the same `{ reason: string }` shape on the
 * backend). The caller owns the actual API call and error handling; this
 * component only collects the reason and reports validation errors back.
 */
const props = defineProps<{
  modelValue: boolean
  title: string
  label: string
  confirmLabel: string
  danger?: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [reason: string] }>()

const { t } = useI18n()

const reason = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open) reason.value = ''
  },
)

function submit() {
  if (!reason.value.trim()) return
  emit('confirm', reason.value.trim())
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ label }} <span class="text-danger-600">*</span>
        </label>
        <textarea
          v-model="reason"
          rows="3"
          required
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :variant="danger ? 'danger' : 'primary'" :loading="submitting" :disabled="!reason.trim()" @click="submit">
        {{ confirmLabel }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
