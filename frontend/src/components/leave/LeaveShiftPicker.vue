<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import type { LeaveShiftOption } from '@/services/leaveRequests'

/**
 * Leave by shift: tick the shifts the staff member will be away from (they
 * can work several). Their own shifts are listed first and marked.
 */
const props = defineProps<{ modelValue: number[]; shifts: LeaveShiftOption[]; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [number[]] }>()

const { t } = useI18n()

const options = computed(() =>
  props.shifts.map((s) => ({
    value: String(s.id),
    label: `${s.name} ${s.start_time}–${s.end_time}${s.mine ? ` · ${t('leaveRequest.myShift')}` : ''}`,
  })),
)
const selected = computed({
  get: () => props.modelValue.map(String),
  set: (ids: string[]) => emit('update:modelValue', ids.map(Number)),
})
</script>

<template>
  <BaseMultiSelect
    v-model="selected"
    :options="options"
    :label="t('leaveRequest.shifts')"
    :placeholder="t('leaveRequest.shiftsPlaceholder')"
    :hint="shifts.length ? t('leaveRequest.shiftsHint') : t('leaveRequest.noShifts')"
    :error="error"
  />
</template>
