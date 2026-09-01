<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { repairShopsService, type RepairShop } from '@/services/repairShops'
import type { ScheduleMaintenanceInput } from '@/services/assetMaintenance'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: ScheduleMaintenanceInput] }>()

const { t } = useI18n()

const shops = ref<RepairShop[]>([])
const shopOptions = computed(() => [{ value: '', label: t('admin.assetMaintenance.noRepairShop') }, ...shops.value.map((s) => ({ value: String(s.id), label: s.name }))])

const form = reactive({
  maintenance_type: '',
  scheduled_date: '',
  description: '',
  repair_shop_id: '',
  recurrence_interval_months: '',
  notes: '',
})

onMounted(async () => {
  shops.value = await repairShopsService.listAll()
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.maintenance_type = ''
    form.scheduled_date = ''
    form.description = ''
    form.repair_shop_id = ''
    form.recurrence_interval_months = ''
    form.notes = ''
  },
)

function submit() {
  if (!form.maintenance_type.trim() || !form.scheduled_date) return
  emit('confirm', {
    maintenance_type: form.maintenance_type.trim(),
    scheduled_date: form.scheduled_date,
    description: form.description,
    repair_shop_id: form.repair_shop_id ? Number(form.repair_shop_id) : null,
    recurrence_interval_months: form.recurrence_interval_months ? Number(form.recurrence_interval_months) : null,
    notes: form.notes,
  })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assetMaintenance.scheduleTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="form.maintenance_type" required :label="t('admin.assetMaintenance.maintenanceType')" :hint="t('admin.assetMaintenance.maintenanceTypeHint')" />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.scheduled_date" type="date" required :label="t('admin.assetMaintenance.scheduledDate')" />
        <BaseInput v-model="form.recurrence_interval_months" type="number" :label="t('admin.assetMaintenance.recurrenceMonths')" :hint="t('admin.assetMaintenance.recurrenceMonthsHint')" />
      </div>
      <BaseSelect v-model="form.repair_shop_id" :options="shopOptions" :label="t('admin.assetMaintenance.repairShop')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetMaintenance.description') }}</label>
        <textarea
          v-model="form.description"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!form.maintenance_type.trim() || !form.scheduled_date" @click="submit">
        {{ t('admin.assetMaintenance.schedule') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
