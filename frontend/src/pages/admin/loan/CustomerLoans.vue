<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import CustomerLoanFormModal from '@/components/admin/CustomerLoanFormModal.vue'
import CustomerLoanPaymentModal from '@/components/admin/CustomerLoanPaymentModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { customerLoansService, type CustomerLoan, type CustomerLoanStatus, type CustomerLoanSummary } from '@/services/customerLoans'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Owe: money lent to customers. Cards for what is still owed, late
 * and due soon (per currency); the list with the next payment; Record
 * payment right from the row. A loan opens its statement page.
 */
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const canCreate = computed(() => auth.can('customer-loans.create'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<CustomerLoan>((query) => customerLoansService.list(query))

const summary = ref<CustomerLoanSummary | null>(null)
async function loadSummary() {
  summary.value = await customerLoansService.summary().catch(() => null)
}

const statusFilter = ref<CustomerLoanStatus | ''>('active')
const statusOptions = computed(() => [
  { value: '', label: t('admin.borrowings.allStatuses') },
  { value: 'active', label: t('admin.borrowings.statuses.active') },
  { value: 'paid_off', label: t('admin.borrowings.statuses.paid_off') },
  { value: 'cancelled', label: t('admin.borrowings.statuses.cancelled') },
])
function onStatus(value: string) {
  statusFilter.value = value as CustomerLoanStatus | ''
  setFilter('status', value || undefined)
}
const currencyFilter = ref('')
const currencyOptions = computed(() => [
  { value: '', label: t('admin.savings.allCurrencies') },
  { value: 'USD', label: 'USD' },
  { value: 'KHR', label: 'KHR' },
])
function onCurrency(value: string) {
  currencyFilter.value = value
  setFilter('currency', value || undefined)
}

const statusVariant: Record<CustomerLoanStatus, 'primary' | 'success' | 'neutral'> = { active: 'primary', paid_off: 'success', cancelled: 'neutral' }
const money = (value: number, currency: CustomerLoan['currency']) => formatMoney(Number(value), currency)

function interestLabel(loan: CustomerLoan): string {
  if (loan.interest_type === 'none') return t('admin.borrowings.interestTypes.none')
  return t('admin.borrowings.interestShort', {
    rate: Number(loan.interest_rate),
    period: t(`admin.borrowings.periods.${loan.interest_period}`),
    type: t(`admin.borrowings.interestTypes.${loan.interest_type}`),
  })
}

const formOpen = ref(false)
const payOpen = ref(false)
const paying = ref<CustomerLoan | null>(null)

function open(loan: CustomerLoan) {
  router.push(`/admin/customer-loans/${loan.id}`)
}
function pay(loan: CustomerLoan) {
  paying.value = loan
  payOpen.value = true
}
function refresh() {
  fetch()
  loadSummary()
}

onMounted(() => {
  setFilter('status', 'active')
  loadSummary()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.customerLoans.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.customerLoans.subtitle') }}</p>
    </div>

    <div v-if="summary" class="mb-6 grid gap-3 sm:grid-cols-2">
      <div v-for="row in summary.currencies" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.customerLoans.owedIn', { currency: row.currency }) }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ money(row.owed, row.currency) }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.customerLoans.loansCount', { n: row.loans }) }}</p>
        <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-neutral-100 pt-2 text-xs">
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.late') }}</dt><dd class="font-semibold tabular-nums" :class="row.late > 0 ? 'text-danger-600' : 'text-neutral-700'">{{ money(row.late, row.currency) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.customerLoans.dueSoon') }}</dt><dd class="font-semibold tabular-nums text-neutral-700">{{ money(row.due_soon, row.currency) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.customerLoans.interestThisYear') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ money(row.interest_this_year, row.currency) }}</dd></div>
        </dl>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('admin.customerLoans.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-full sm:w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseSelect class="w-full sm:w-40" :model-value="currencyFilter" :options="currencyOptions" @update:model-value="onCurrency" />
      <BaseButton v-if="canCreate" class="w-full sm:ml-auto sm:w-auto" @click="formOpen = true">{{ t('admin.customerLoans.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.customerLoans.empty') }}</p>
    <template v-else>
      <!-- Cards on small screens (below sm) instead of the table. -->
      <div class="space-y-2 sm:hidden">
        <div v-for="loan in items" :key="loan.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button type="button" class="block w-full text-left" @click="open(loan)">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-800">{{ loan.customer?.name ?? '—' }}</p>
                <p class="text-xs text-neutral-500">{{ loan.loan_no }} · {{ formatDate(loan.lent_on) }}</p>
              </div>
              <BaseBadge v-if="loan.late_count" variant="danger">{{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</BaseBadge>
              <BaseBadge v-else :variant="statusVariant[loan.status]">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
            </div>
            <p class="mt-1 text-sm font-semibold text-neutral-900">
              {{ money(loan.amount, loan.currency) }}
              <span v-if="loan.status === 'active'" class="font-normal text-neutral-500">· {{ t('admin.customerLoans.owed') }} {{ money(loan.outstanding, loan.currency) }}</span>
            </p>
            <p class="text-xs text-neutral-500">
              <template v-if="loan.flexible">{{ t('admin.borrowings.flexible') }}</template>
              <template v-else>{{ t('admin.borrowings.monthlyOf', { amount: money(loan.monthly_payment, loan.currency), months: loan.term_months }) }}</template>
              <template v-if="loan.next_payment"> · {{ t('admin.borrowings.nextDue', { date: formatDate(loan.next_payment.due_on) }) }}</template>
            </p>
          </button>
          <div v-if="canCreate && loan.status === 'active'" class="mt-2 flex justify-end">
            <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="pay(loan)">{{ t('admin.customerLoans.recordPayment') }}</button>
          </div>
        </div>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.customerLoans.customer') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.customerLoans.amount') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.customerLoans.owed') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.interest') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.monthly') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.nextPayment') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.status') }}</th>
              <th v-if="canCreate" class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="loan in items" :key="loan.id">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="open(loan)">{{ loan.customer?.name ?? '—' }}</button>
                <p class="text-xs text-neutral-500">{{ loan.loan_no }} · {{ formatDate(loan.lent_on) }}<template v-if="loan.reference"> · {{ loan.reference }}</template></p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(loan.amount, loan.currency) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(loan.outstanding, loan.currency) }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ interestLabel(loan) }}</td>
              <td v-if="loan.flexible" class="px-4 py-3 text-right text-neutral-500">{{ t('admin.borrowings.flexible') }}</td>
              <td v-else class="px-4 py-3 text-right tabular-nums">
                {{ money(loan.monthly_payment, loan.currency) }}
                <p class="text-xs text-neutral-500">× {{ loan.term_months }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">
                <template v-if="loan.next_payment">
                  <span :class="loan.next_payment.late ? 'font-medium text-danger-700' : ''">{{ formatDate(loan.next_payment.due_on) }}</span>
                  <p class="text-xs text-neutral-500">{{ money((loan.next_payment.due_principal ?? loan.next_payment.principal) + loan.next_payment.interest, loan.currency) }}</p>
                </template>
                <template v-else>—</template>
              </td>
              <td class="px-4 py-3">
                <BaseBadge v-if="loan.late_count" variant="danger">{{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</BaseBadge>
                <BaseBadge v-else :variant="statusVariant[loan.status]">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
              </td>
              <td v-if="canCreate" class="whitespace-nowrap px-4 py-3 text-right">
                <button v-if="loan.status === 'active'" type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="pay(loan)">{{ t('admin.customerLoans.recordPayment') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CustomerLoanFormModal v-model="formOpen" @saved="(loan) => router.push(`/admin/customer-loans/${loan.id}`)" />
    <CustomerLoanPaymentModal v-model="payOpen" :loan="paying" @saved="refresh" />
  </div>
</template>
