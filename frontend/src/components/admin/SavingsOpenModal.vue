<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import LoanCustomerFormModal from '@/components/admin/LoanCustomerFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { loanCustomersService, type LoanCustomer } from '@/services/loanCustomers'
import { savingsService, type SavingsAccount, type SavingsCurrency } from '@/services/savings'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/** Loan > Savings: open a savings account for a customer, with an optional first deposit. */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [account: SavingsAccount] }>()

const { t } = useI18n()
const auth = useAuthStore()
const canAddCustomer = computed(() => auth.can('loan-customers.create'))

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const customers = ref<LoanCustomer[]>([])
const customerOptions = computed(() => customers.value.map((c) => ({ value: String(c.id), label: `${c.name}${c.code ? ` (${c.code})` : ''}${c.phone ? ` · ${c.phone}` : ''}` })))
const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

const form = reactive({ loan_customer_id: '', currency: 'USD' as SavingsCurrency, opened_on: '', first_deposit: '', cash_account_id: '', remark: '', notes: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const customerModalOpen = ref(false)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    Object.assign(form, { loan_customer_id: '', currency: 'USD', opened_on: today(), first_deposit: '', cash_account_id: '', remark: '', notes: '' })
    errors.value = {}
    saveError.value = null
    const [list, all] = await Promise.all([
      loanCustomersService.listAll().catch(() => []),
      accounts.value.length ? Promise.resolve(accounts.value) : accountsService.listAll().then((rows) => rows.filter((a) => a.is_bank_or_cash && a.is_active)).catch(() => []),
    ])
    customers.value = list
    accounts.value = all
    if (accounts.value.length === 1) form.cash_account_id = String(accounts.value[0]!.id)
  },
  { immediate: true },
)

function onCustomerAdded(customer: LoanCustomer) {
  customers.value = [...customers.value, customer].sort((a, b) => a.name.localeCompare(b.name))
  form.loan_customer_id = String(customer.id)
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const hasDeposit = Number(form.first_deposit || 0) > 0
    const account = await savingsService.open({
      loan_customer_id: Number(form.loan_customer_id),
      currency: form.currency,
      opened_on: form.opened_on,
      notes: form.notes.trim() || null,
      first_deposit: hasDeposit ? Number(form.first_deposit) : null,
      cash_account_id: hasDeposit && form.cash_account_id ? Number(form.cash_account_id) : null,
      remark: hasDeposit ? form.remark.trim() || null : null,
    })
    emit('saved', account)
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
  <BaseModal :model-value="modelValue" :title="t('admin.savings.open')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <div>
        <SearchableSelect
          v-model="form.loan_customer_id"
          required
          :options="customerOptions"
          :label="t('admin.savings.customer')"
          :placeholder="t('admin.savings.selectCustomer')"
          :error="errors.loan_customer_id?.[0]"
        />
        <button v-if="canAddCustomer" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="customerModalOpen = true">
          {{ t('admin.savings.newCustomer') }}
        </button>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect
          :model-value="form.currency"
          :options="currencyOptions"
          :label="t('admin.savings.currency')"
          :error="errors.currency?.[0]"
          @update:model-value="form.currency = $event as SavingsCurrency"
        />
        <BaseInput v-model="form.opened_on" type="date" required :label="t('admin.savings.openedOn')" :error="errors.opened_on?.[0]" />
      </div>
      <div class="rounded-lg border border-neutral-200 p-3">
        <p class="mb-3 text-xs text-neutral-500">{{ t('admin.savings.firstDepositHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.first_deposit" type="number" :label="t('admin.savings.firstDeposit')" :error="errors.first_deposit?.[0]" />
          <SearchableSelect
            v-model="form.cash_account_id"
            :options="accountOptions"
            :label="t('admin.savings.cashAccount')"
            :placeholder="t('admin.savings.selectAccount')"
            :error="errors.cash_account_id?.[0]"
          />
        </div>
        <BaseInput v-model="form.remark" class="mt-4" :label="t('admin.savings.remark')" :error="errors.remark?.[0]" />
      </div>
      <BaseInput v-model="form.notes" :label="t('admin.savings.notes')" :error="errors.notes?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.loan_customer_id" @click="save">{{ t('admin.savings.open') }}</BaseButton>
    </template>
    <LoanCustomerFormModal v-model="customerModalOpen" @saved="onCustomerAdded" />
  </BaseModal>
</template>
