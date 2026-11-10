<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { savingsService, type SavingsAccount, type SavingsEntry } from '@/services/savings'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/** Loan > Savings: record a deposit into, or a withdrawal from, one savings account (with a remark). */
const props = defineProps<{ modelValue: boolean; account: SavingsAccount | null; type: 'deposit' | 'withdrawal' }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [entry: SavingsEntry] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const form = reactive({ amount: '', transacted_on: '', cash_account_id: '', remark: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const isWithdrawal = computed(() => props.type === 'withdrawal')
const money = (value: number) => formatMoney(value, props.account?.currency ?? 'USD')
const title = computed(() => {
  const name = props.account?.customer?.name ?? '—'
  return isWithdrawal.value ? t('admin.savings.withdrawFrom', { name }) : t('admin.savings.depositTo', { name })
})
const after = computed(() => (props.account ? props.account.balance + (isWithdrawal.value ? -1 : 1) * Number(form.amount || 0) : 0))

watch(
  () => [props.modelValue, props.account] as const,
  async ([open]) => {
    if (!open) return
    Object.assign(form, { amount: '', transacted_on: today(), cash_account_id: form.cash_account_id, remark: '' })
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

function withdrawAll() {
  if (props.account) form.amount = String(props.account.balance)
}

async function save() {
  if (!props.account) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const entry = await savingsService.record(props.account.id, {
      type: props.type,
      amount: Number(form.amount || 0),
      transacted_on: form.transacted_on,
      cash_account_id: Number(form.cash_account_id),
      remark: form.remark.trim() || null,
    })
    emit('saved', entry)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.savings.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="account" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError || errors.status" variant="danger">{{ saveError || errors.status?.[0] }}</BaseAlert>
      <p class="text-sm text-neutral-600">
        {{ account.account_no }} · {{ t('admin.savings.balance') }}: <span class="font-semibold text-neutral-900">{{ money(account.balance) }}</span>
      </p>
      <div v-if="isWithdrawal && account.balance > 0">
        <button type="button" class="rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100" @click="withdrawAll">
          {{ t('admin.savings.withdrawAll') }}
        </button>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.amount" type="number" required :label="t('admin.savings.amount')" :error="errors.amount?.[0]" />
        <BaseInput v-model="form.transacted_on" type="date" required :label="t('admin.savings.date')" :error="errors.transacted_on?.[0]" />
      </div>
      <SearchableSelect
        v-model="form.cash_account_id"
        required
        :options="accountOptions"
        :label="isWithdrawal ? t('admin.savings.paidFrom') : t('admin.savings.receivedInto')"
        :placeholder="t('admin.savings.selectAccount')"
        :error="errors.cash_account_id?.[0]"
      />
      <BaseInput v-model="form.remark" :label="t('admin.savings.remark')" :error="errors.remark?.[0]" />
      <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        {{ t('admin.savings.balanceAfter') }}:
        <span class="font-semibold" :class="after < 0 ? 'text-danger-600' : 'text-neutral-900'">{{ money(after) }}</span>
      </p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="Number(form.amount || 0) <= 0 || !form.cash_account_id || after < 0" @click="save">
        {{ isWithdrawal ? t('admin.savings.withdraw') : t('admin.savings.deposit') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
