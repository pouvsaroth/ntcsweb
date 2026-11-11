<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderPicker from '@/components/admin/FundProviderPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { fundsService, type Fund, type FundEntry, type FundEntryType } from '@/services/funds'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/** Fund > Funds: record money into, or out of, one fund (from / paid to, remark). */
const props = defineProps<{ modelValue: boolean; fund: Fund | null; type: FundEntryType }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [entry: FundEntry] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const form = reactive({ amount: '', transacted_on: '', cash_account_id: '', party: '', fund_provider_id: '', remark: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const isOut = computed(() => props.type === 'out')
const money = (value: number) => formatMoney(value, props.fund?.currency ?? 'USD')
const title = computed(() => (isOut.value ? t('admin.funds.moneyOutOf', { name: props.fund?.name ?? '—' }) : t('admin.funds.moneyInto', { name: props.fund?.name ?? '—' })))
const after = computed(() => (props.fund ? props.fund.balance + (isOut.value ? -1 : 1) * Number(form.amount || 0) : 0))

watch(
  () => [props.modelValue, props.fund] as const,
  async ([open]) => {
    if (!open) return
    Object.assign(form, { amount: '', transacted_on: today(), cash_account_id: form.cash_account_id, party: '', fund_provider_id: '', remark: '' })
    errors.value = {}
    saveError.value = null
    if (accounts.value.length === 0) {
      accounts.value = await accountsService
        .listAll()
        .then((all) => all.filter((a) => a.is_bank_or_cash && a.is_active))
        .catch(() => [])
      if (!form.cash_account_id && accounts.value.length === 1) form.cash_account_id = String(accounts.value[0]!.id)
    }
  },
  { immediate: true },
)

async function save() {
  if (!props.fund) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const entry = await fundsService.record(props.fund.id, {
      type: props.type,
      amount: Number(form.amount || 0),
      transacted_on: form.transacted_on,
      cash_account_id: Number(form.cash_account_id),
      // Money in: the provider picked; money out: who it was paid to.
      party: isOut.value ? form.party.trim() || null : null,
      fund_provider_id: !isOut.value && form.fund_provider_id ? Number(form.fund_provider_id) : null,
      remark: form.remark.trim() || null,
    })
    emit('saved', entry)
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
  <BaseModal :model-value="modelValue" :title="title" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="fund" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError || errors.status" variant="danger">{{ saveError || errors.status?.[0] }}</BaseAlert>
      <p class="text-sm text-neutral-600">
        {{ fund.fund_no }} · {{ t('admin.funds.balance') }}: <span class="font-semibold text-neutral-900">{{ money(fund.balance) }}</span>
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.amount" type="number" required :label="t('admin.funds.amount')" :error="errors.amount?.[0]" />
        <BaseInput v-model="form.transacted_on" type="date" required :label="t('admin.funds.date')" :error="errors.transacted_on?.[0]" />
      </div>
      <SearchableSelect
        v-model="form.cash_account_id"
        required
        :options="accountOptions"
        :label="isOut ? t('admin.funds.paidFrom') : t('admin.funds.receivedInto')"
        :placeholder="t('admin.funds.selectAccount')"
        :error="errors.cash_account_id?.[0]"
      />
      <BaseInput v-if="isOut" v-model="form.party" :label="t('admin.funds.paidTo')" :error="errors.party?.[0]" />
      <FundProviderPicker v-else v-model="form.fund_provider_id" :error="errors.fund_provider_id?.[0]" />
      <BaseInput v-model="form.remark" :label="t('admin.funds.remark')" :error="errors.remark?.[0]" />
      <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        {{ t('admin.funds.balanceAfter') }}:
        <span class="font-semibold" :class="after < 0 ? 'text-danger-600' : 'text-neutral-900'">{{ money(after) }}</span>
      </p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="Number(form.amount || 0) <= 0 || !form.cash_account_id || after < 0" @click="save">
        {{ isOut ? t('admin.funds.moneyOut') : t('admin.funds.moneyIn') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
