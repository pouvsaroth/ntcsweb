<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { disposalMethods, type DisposalMethod } from '@/services/assets'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: { method: DisposalMethod; reason: string; value?: number }] }>()

const { t } = useI18n()

const method = ref<DisposalMethod>('RECYCLED')
const reason = ref('')
const value = ref('')

const methodOptions = computed(() =>
  disposalMethods.map((m) => ({ value: m, label: t(`admin.assets.disposalMethod${m.charAt(0)}${m.slice(1).toLowerCase().replace(/_(.)/g, (_, c) => c.toUpperCase())}`) })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    method.value = 'RECYCLED'
    reason.value = ''
    value.value = ''
  },
)

function submit() {
  if (!reason.value.trim()) return
  emit('confirm', { method: method.value, reason: reason.value.trim(), value: value.value ? Number(value.value) : undefined })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assets.disposeTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect v-model="method" :options="methodOptions" :label="t('admin.assets.disposalMethod')" />
      <BaseInput v-model="value" type="number" :label="t('admin.assets.disposalValue')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.assets.reason') }} <span class="text-danger-600">*</span>
        </label>
        <textarea
          v-model="reason"
          rows="3"
          required
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :loading="submitting" :disabled="!reason.trim()" @click="submit">{{ t('admin.assets.dispose') }}</BaseButton>
    </template>
  </BaseModal>
</template>
