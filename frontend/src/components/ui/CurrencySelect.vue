<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseSelect from '@/components/ui/BaseSelect.vue'

/**
 * The USD / KHR picker for any amount. Every amount is saved with the
 * currency it is in, so it is never shown or added up as the wrong money.
 */
withDefaults(defineProps<{ modelValue: 'USD' | 'KHR'; label?: string; error?: string; disabled?: boolean }>(), {
  label: undefined,
  error: undefined,
  disabled: false,
})
const emit = defineEmits<{ 'update:modelValue': [value: 'USD' | 'KHR'] }>()

const { t } = useI18n()
const options = computed(() => [
  { value: 'USD', label: t('admin.coursePackages.currencyUsd') },
  { value: 'KHR', label: t('admin.coursePackages.currencyKhr') },
])
</script>

<template>
  <BaseSelect
    :model-value="modelValue"
    :options="options"
    required
    :label="label ?? t('admin.coursePackages.currency')"
    :error="error"
    :disabled="disabled"
    @update:model-value="emit('update:modelValue', $event === 'KHR' ? 'KHR' : 'USD')"
  />
</template>
