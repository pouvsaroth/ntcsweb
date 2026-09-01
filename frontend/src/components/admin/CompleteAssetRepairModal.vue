<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { assetConditions, type AssetCondition } from '@/services/assets'
import type { CompleteRepairInput } from '@/services/assetRepairs'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: CompleteRepairInput] }>()

const { t } = useI18n()

const accounts = ref<Account[]>([])
const expenseAccountOptions = computed(() => accounts.value.filter((a) => a.type === 'EXPENSE' && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const conditionOptions = computed(() => [
  { value: '', label: t('admin.assetRepairs.noConditionChange') },
  ...assetConditions.map((c) => ({ value: c, label: t(`admin.assets.condition${c.charAt(0)}${c.slice(1).toLowerCase()}`) })),
])

const form = reactive({
  expense_account_id: '',
  repair_description: '',
  condition_after_repair: '' as AssetCondition | '',
  diagnosis_cost: '0',
  parts_cost: '0',
  labor_cost: '0',
  transport_cost: '0',
  other_cost: '0',
})

const total = computed(() =>
  [form.diagnosis_cost, form.parts_cost, form.labor_cost, form.transport_cost, form.other_cost].reduce((sum, v) => sum + (Number(v) || 0), 0),
)

onMounted(async () => {
  accounts.value = await accountsService.listAll()
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.expense_account_id = ''
    form.repair_description = ''
    form.condition_after_repair = ''
    form.diagnosis_cost = '0'
    form.parts_cost = '0'
    form.labor_cost = '0'
    form.transport_cost = '0'
    form.other_cost = '0'
  },
)

function submit() {
  if (!form.expense_account_id) return
  emit('confirm', {
    expense_account_id: Number(form.expense_account_id),
    repair_description: form.repair_description,
    condition_after_repair: form.condition_after_repair || null,
    diagnosis_cost: form.diagnosis_cost,
    parts_cost: form.parts_cost,
    labor_cost: form.labor_cost,
    transport_cost: form.transport_cost,
    other_cost: form.other_cost,
  })
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="t('admin.assetRepairs.completeTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect
        v-model="form.expense_account_id"
        required
        :options="expenseAccountOptions"
        :placeholder="t('admin.assetRepairs.selectExpenseAccount')"
        :label="t('admin.assetRepairs.expenseAccount')"
        :hint="t('admin.assetRepairs.expenseAccountHint')"
      />

      <div class="grid grid-cols-3 gap-4">
        <BaseInput v-model="form.diagnosis_cost" type="number" :label="t('admin.assetRepairs.diagnosisCost')" />
        <BaseInput v-model="form.parts_cost" type="number" :label="t('admin.assetRepairs.partsCost')" />
        <BaseInput v-model="form.labor_cost" type="number" :label="t('admin.assetRepairs.laborCost')" />
      </div>
      <div class="grid grid-cols-3 gap-4">
        <BaseInput v-model="form.transport_cost" type="number" :label="t('admin.assetRepairs.transportCost')" />
        <BaseInput v-model="form.other_cost" type="number" :label="t('admin.assetRepairs.otherCost')" />
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetRepairs.totalCost') }}</label>
          <p class="mt-2 text-lg font-semibold text-neutral-900">${{ total.toFixed(2) }}</p>
        </div>
      </div>

      <BaseSelect v-model="form.condition_after_repair" :options="conditionOptions" :label="t('admin.assetRepairs.conditionAfterRepair')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetRepairs.repairDescription') }}</label>
        <textarea
          v-model="form.repair_description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!form.expense_account_id" @click="submit">{{ t('admin.assetRepairs.complete') }}</BaseButton>
    </template>
  </BaseModal>
</template>
