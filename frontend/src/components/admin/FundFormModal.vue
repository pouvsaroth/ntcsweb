<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderPicker from '@/components/admin/FundProviderPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { fundsService, type Fund, type FundCurrency } from '@/services/funds'
import { ApiRequestError } from '@/types/api'

/**
 * Fund > Funds: add a fund (with optional money already in it) or edit one.
 * The type comes from Base Data (FUND_TYPE). Currency is fixed once added.
 */
const props = defineProps<{ modelValue: boolean; fund: Fund | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [fund: Fund] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const editing = computed(() => props.fund !== null)
const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

const form = reactive({
  name: '',
  fund_type: '',
  currency: 'USD' as FundCurrency,
  opened_on: '',
  target_amount: '',
  description: '',
  opening_amount: '',
  cash_account_id: '',
  party: '',
  fund_provider_id: '',
  remark: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

watch(
  () => [props.modelValue, props.fund] as const,
  async ([open]) => {
    if (!open) return
    const f = props.fund
    Object.assign(form, {
      name: f?.name ?? '',
      fund_type: f?.fund_type ?? '',
      currency: f?.currency ?? 'USD',
      opened_on: f?.opened_on ?? today(),
      target_amount: f?.target_amount != null ? String(f.target_amount) : '',
      description: f?.description ?? '',
      opening_amount: '',
      cash_account_id: '',
      party: '',
      fund_provider_id: '',
      remark: '',
    })
    errors.value = {}
    saveError.value = null
    if (!f && accounts.value.length === 0) {
      accounts.value = await accountsService
        .listAll()
        .then((rows) => rows.filter((a) => a.is_bank_or_cash && a.is_active))
        .catch(() => [])
    }
    if (!f && accounts.value.length === 1) form.cash_account_id = String(accounts.value[0]!.id)
  },
  { immediate: true },
)

const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const base = {
    name: form.name.trim(),
    fund_type: form.fund_type,
    opened_on: form.opened_on,
    target_amount: numberOrNull(form.target_amount),
    description: form.description.trim() || null,
  }
  try {
    let fund: Fund
    if (props.fund) {
      fund = await fundsService.update(props.fund.id, base)
    } else {
      const opening = numberOrNull(form.opening_amount)
      const hasOpening = opening !== null && opening > 0
      fund = await fundsService.create({
        ...base,
        currency: form.currency,
        opening_amount: hasOpening ? opening : null,
        cash_account_id: hasOpening && form.cash_account_id ? Number(form.cash_account_id) : null,
        party: null,
        fund_provider_id: hasOpening && form.fund_provider_id ? Number(form.fund_provider_id) : null,
        remark: hasOpening ? form.remark.trim() || null : null,
      })
    }
    emit('saved', fund)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.funds.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="editing ? t('admin.funds.edit') : t('admin.funds.add')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="form.name" required :label="t('admin.funds.name')" :placeholder="t('admin.funds.namePlaceholder')" :error="errors.name?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <LookupSelect v-model="form.fund_type" category="FUND_TYPE" required :label="t('admin.funds.type')" :error="errors.fund_type?.[0]" />
        <BaseSelect
          :model-value="form.currency"
          :options="currencyOptions"
          :label="t('admin.funds.currency')"
          :disabled="editing"
          :error="errors.currency?.[0]"
          @update:model-value="form.currency = $event as FundCurrency"
        />
        <BaseInput v-model="form.opened_on" type="date" required :label="t('admin.funds.openedOn')" :error="errors.opened_on?.[0]" />
        <BaseInput v-model="form.target_amount" type="number" :label="t('admin.funds.target')" :hint="t('admin.funds.targetHint')" :error="errors.target_amount?.[0]" />
      </div>
      <p class="-mt-2 text-xs text-neutral-500">{{ t('admin.funds.typeHint') }}</p>

      <div v-if="!editing" class="rounded-lg border border-neutral-200 p-3">
        <p class="mb-3 text-xs text-neutral-500">{{ t('admin.funds.openingHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.opening_amount" type="number" :label="t('admin.funds.openingAmount')" :error="errors.opening_amount?.[0]" />
          <SearchableSelect
            v-model="form.cash_account_id"
            :options="accountOptions"
            :label="t('admin.funds.receivedInto')"
            :placeholder="t('admin.funds.selectAccount')"
            :error="errors.cash_account_id?.[0]"
          />
          <FundProviderPicker v-model="form.fund_provider_id" :error="errors.fund_provider_id?.[0]" />
          <BaseInput v-model="form.remark" :label="t('admin.funds.remark')" :error="errors.remark?.[0]" />
        </div>
      </div>

      <BaseInput v-model="form.description" :label="t('admin.funds.description')" :error="errors.description?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.name.trim() || !form.fund_type" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
