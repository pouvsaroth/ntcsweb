<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { assetConditions, type AssetCondition } from '@/services/assets'

const props = defineProps<{
  modelValue: boolean
  currentCondition?: AssetCondition
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: { condition: AssetCondition; notes?: string }] }>()

const { t } = useI18n()

const condition = ref<AssetCondition>('GOOD')
const notes = ref('')

const conditionOptions = computed(() =>
  assetConditions.map((c) => ({ value: c, label: t(`admin.assets.condition${c.charAt(0)}${c.slice(1).toLowerCase()}`) })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    condition.value = props.currentCondition ?? 'GOOD'
    notes.value = ''
  },
)

function submit() {
  emit('confirm', { condition: condition.value, notes: notes.value || undefined })
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="sm" :title="t('admin.assets.changeConditionTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect v-model="condition" :options="conditionOptions" :label="t('admin.assets.condition')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assets.notes') }}</label>
        <textarea
          v-model="notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
