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
import type { InterestPeriod, InterestType, ScheduleRow, ScheduleTotals } from '@/services/borrowings'
import { customerLoansService, type CustomerLoan, type CustomerLoanCurrency, type CustomerLoanInput } from '@/services/customerLoans'
import { loanCustomersService, type LoanCustomer } from '@/services/loanCustomers'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Owe: lend money to a customer, or edit a loan. The monthly plan is
 * worked out by the server from the terms and shown while typing. Once a
 * payment is recorded only the reference and purpose can change.
 */
const props = defineProps<{ modelValue: boolean; loan?: CustomerLoan | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [loan: CustomerLoan] }>()

const { t } = useI18n()
const auth = useAuthStore()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Same day next month — when the first payment usually falls. */
function monthAfter(date: string): string {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number]
  const last = new Date(y, m + 1, 0).getDate()
  return `${m === 12 ? y + 1 : y}-${String((m % 12) + 1).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`
}

const customers = ref<LoanCustomer[]>([])
const cashAccounts = ref<Account[]>([])
const customerOptions = computed(() => customers.value.map((c) => ({ value: String(c.id), label: `${c.name}${c.code ? ` (${c.code})` : ''}${c.phone ? ` · ${c.phone}` : ''}` })))
const cashOptions = computed(() => cashAccounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const interestOptions = computed(() => (['none', 'flat', 'reducing', 'interest_only'] as const).map((v) => ({ value: v, label: t(`admin.borrowings.interestTypes.${v}`) })))
const periodOptions = computed(() => (['month', 'year'] as const).map((v) => ({ value: v, label: t(`admin.borrowings.perPeriod.${v}`) })))

const form = reactive({
  loan_customer_id: '',
  reference: '',
  amount: '',
  currency: 'USD' as CustomerLoanCurrency,
  lent_on: '',
  cash_account_id: '',
  interest_type: 'none' as InterestType,
  interest_rate: '',
  interest_period: 'month' as InterestPeriod,
  term_months: '',
  first_payment_on: '',
  purpose: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const customerModalOpen = ref(false)

const interestHint = computed(() => t(`admin.borrowings.interestHints.${form.interest_type}`))
const locked = computed(() => (props.loan?.paid_principal ?? 0) > 0 || (props.loan?.paid_interest ?? 0) > 0)
const money = (value: number) => formatMoney(value, form.currency)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    const loan = props.loan
    form.loan_customer_id = loan?.customer ? String(loan.customer.id) : ''
    form.reference = loan?.reference ?? ''
    form.amount = loan ? String(Number(loan.amount)) : ''
    form.currency = loan?.currency ?? ((auth.tenantDefaultCurrency as CustomerLoanCurrency | null) ?? 'USD')
    form.lent_on = loan?.lent_on ?? today()
    form.cash_account_id = loan?.cash_account ? String(loan.cash_account.id) : ''
    form.interest_type = loan?.interest_type ?? 'none'
    form.interest_rate = loan && loan.interest_type !== 'none' ? String(Number(loan.interest_rate)) : ''
    form.interest_period = loan?.interest_period ?? 'month'
    form.term_months = loan?.term_months ? String(loan.term_months) : ''
    form.first_payment_on = loan?.first_payment_on ?? ''
    form.purpose = loan?.purpose ?? ''
    errors.value = {}
    saveError.value = null
    ;[customers.value, cashAccounts.value] = await Promise.all([
      loanCustomersService.listAll().catch(() => []),
      cashAccounts.value.length
        ? Promise.resolve(cashAccounts.value)
        : accountsService
            .listAll()
            .then((all) => all.filter((a) => a.is_bank_or_cash && a.is_active))
            .catch(() => []),
    ])
    // An inactive customer of an old loan still shows by name.
    if (loan?.customer && !customers.value.some((c) => c.id === loan.customer!.id)) {
      customers.value = [...customers.value, { ...loan.customer, gender: null, id_card_no: null, date_of_birth: null, address: null, notes: null, is_active: false, created_at: null }]
    }
    if (!loan && cashAccounts.value.length === 1) form.cash_account_id = String(cashAccounts.value[0]!.id)
  },
  { immediate: true },
)

function onCustomerAdded(customer: LoanCustomer) {
  customers.value = [...customers.value, customer].sort((a, b) => a.name.localeCompare(b.name))
  form.loan_customer_id = String(customer.id)
}

function input(): CustomerLoanInput {
  return {
    loan_customer_id: form.loan_customer_id ? Number(form.loan_customer_id) : null,
    reference: form.reference.trim() || null,
    amount: Number(form.amount || 0),
    currency: form.currency,
    lent_on: form.lent_on,
    cash_account_id: form.cash_account_id ? Number(form.cash_account_id) : null,
    interest_type: form.interest_type,
    interest_rate: form.interest_type === 'none' ? 0 : Number(form.interest_rate || 0),
    interest_period: form.interest_period,
    // No months: a flexible loan (any date, any amount, interest only).
    term_months: Number(form.term_months) > 0 ? Number(form.term_months) : null,
    first_payment_on: Number(form.term_months) > 0 ? form.first_payment_on || null : null,
    purpose: form.purpose.trim() || null,
  }
}

// Live plan while typing (debounced).
const preview = ref<{ schedule: ScheduleRow[]; totals: ScheduleTotals } | null>(null)
let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [props.modelValue, form.amount, form.currency, form.interest_type, form.interest_rate, form.interest_period, form.term_months, form.first_payment_on, form.lent_on],
  () => {
    clearTimeout(previewTimer)
    const i = input()
    const months = i.term_months ?? 0
    // Without a first payment date the plan starts a month after lending (as on the server).
    const first = i.first_payment_on || (form.lent_on ? monthAfter(form.lent_on) : '')
    const ready = props.modelValue && i.amount > 0 && months > 0 && first && (i.interest_type === 'none' || i.interest_rate > 0)
    if (!ready) {
      preview.value = null
      return
    }
    previewTimer = setTimeout(async () => {
      preview.value = await customerLoansService
        .preview({ amount: i.amount, currency: i.currency, interest_type: i.interest_type, interest_rate: i.interest_rate, interest_period: i.interest_period, term_months: months, first_payment_on: first })
        .catch(() => null)
    }, 300)
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const data = input()
    const payload: Partial<CustomerLoanInput> = locked.value ? { reference: data.reference, purpose: data.purpose } : data
    const saved = props.loan ? await customerLoansService.update(props.loan.id, payload) : await customerLoansService.create(data)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.customerLoans.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="loan ? t('admin.customerLoans.edit') : t('admin.customerLoans.add')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError || errors.status" variant="danger">{{ saveError || errors.status?.[0] }}</BaseAlert>
      <BaseAlert v-if="locked" variant="info">{{ t('admin.borrowings.lockedHint') }}</BaseAlert>

      <fieldset :disabled="locked" class="space-y-4 disabled:opacity-60">
        <div>
          <SearchableSelect
            v-model="form.loan_customer_id"
            required
            :options="customerOptions"
            :label="t('admin.customerLoans.customer')"
            :placeholder="t('admin.customerLoans.selectCustomer')"
            :error="errors.loan_customer_id?.[0]"
          />
          <button v-if="!locked && auth.can('loan-customers.create')" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="customerModalOpen = true">
            {{ t('admin.savings.newCustomer') }}
          </button>
        </div>

        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)]">
          <BaseInput v-model="form.amount" type="number" required :label="t('admin.customerLoans.amount')" :error="errors.amount?.[0]" />
          <BaseSelect :model-value="form.currency" :options="currencyOptions" :label="t('admin.savings.currency')" @update:model-value="form.currency = $event as CustomerLoanCurrency" />
          <BaseInput v-model="form.lent_on" type="date" required :label="t('admin.customerLoans.lentOn')" :error="errors.lent_on?.[0]" />
        </div>

        <SearchableSelect
          v-model="form.cash_account_id"
          :options="cashOptions"
          :label="t('admin.customerLoans.paidFrom')"
          :placeholder="t('admin.savings.selectAccount')"
          :hint="t('admin.customerLoans.paidFromHint')"
          :error="errors.cash_account_id?.[0]"
        />

        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem_10rem]">
          <BaseSelect :model-value="form.interest_type" :options="interestOptions" :label="t('admin.borrowings.interest')" :hint="interestHint" @update:model-value="form.interest_type = $event as InterestType" />
          <template v-if="form.interest_type !== 'none'">
            <BaseInput v-model="form.interest_rate" type="number" required :label="t('admin.borrowings.rate')" :error="errors.interest_rate?.[0]" />
            <BaseSelect :model-value="form.interest_period" :options="periodOptions" :label="t('admin.borrowings.ratePer')" @update:model-value="form.interest_period = $event as InterestPeriod" />
          </template>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.term_months" type="number" :label="t('admin.borrowings.termMonths')" :hint="t('admin.borrowings.termMonthsHint')" :error="errors.term_months?.[0]" />
          <BaseInput
            v-model="form.first_payment_on"
            type="date"
            :disabled="!(Number(form.term_months) > 0)"
            :label="t('admin.borrowings.firstPayment')"
            :hint="t('admin.customerLoans.firstPaymentHint')"
            :error="errors.first_payment_on?.[0]"
          />
        </div>
      </fieldset>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.reference" :label="t('admin.borrowings.reference')" :error="errors.reference?.[0]" />
        <BaseInput v-model="form.purpose" :label="t('admin.borrowings.purpose')" :error="errors.purpose?.[0]" />
      </div>

      <div v-if="!locked && !(Number(form.term_months) > 0)" class="rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-900">{{ t('admin.borrowings.flexibleHint') }}</div>
      <div v-else-if="preview && !locked" class="rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-900">
        {{
          t('admin.borrowings.previewLine', {
            monthly: money(preview.schedule[0]?.total ?? 0),
            months: preview.schedule.length,
            interest: money(preview.totals.interest),
            total: money(preview.totals.total),
            last: formatDate(preview.schedule[preview.schedule.length - 1]?.due_on),
          })
        }}
      </div>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.loan_customer_id" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
    <LoanCustomerFormModal v-model="customerModalOpen" @saved="onCustomerAdded" />
  </BaseModal>
</template>
