<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseSelect from '@/components/ui/BaseSelect.vue'
import type { PayPeriod } from '@/services/payroll'

/** A pay period: a month, or one half of it for a 15-day payroll. */
const month = defineModel<string>('month', { required: true })
const period = defineModel<PayPeriod>('period', { required: true })

const { t } = useI18n()

const periodOptions = computed(() => [
  { value: 'month', label: t('admin.payroll.period.month') },
  { value: 'first_half', label: t('admin.payroll.period.firstHalf') },
  { value: 'second_half', label: t('admin.payroll.period.secondHalf') },
])
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <input
      v-model="month"
      type="month"
      :aria-label="t('admin.payroll.period.label')"
      class="block rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
    />
    <BaseSelect class="w-40" :model-value="period" :options="periodOptions" @update:model-value="period = $event as PayPeriod" />
  </div>
</template>
