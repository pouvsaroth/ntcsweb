<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: { last_known_location?: string; description?: string }] }>()

const { t } = useI18n()

const lastKnownLocation = ref('')
const description = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    lastKnownLocation.value = ''
    description.value = ''
  },
)

function submit() {
  emit('confirm', { last_known_location: lastKnownLocation.value || undefined, description: description.value || undefined })
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="sm" :title="t('admin.assets.markLostTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="lastKnownLocation" :label="t('admin.assets.lastKnownLocation')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assets.description') }}</label>
        <textarea
          v-model="description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :loading="submitting" @click="submit">{{ t('admin.assets.markLost') }}</BaseButton>
    </template>
  </BaseModal>
</template>
