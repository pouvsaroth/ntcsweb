<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import CustomerLoanFormModal from '@/components/admin/CustomerLoanFormModal.vue'
import CustomerLoanPaymentModal from '@/components/admin/CustomerLoanPaymentModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { customerLoansService, type CustomerLoan, type CustomerLoanPayment } from '@/services/customerLoans'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Owe: one loan to a customer — terms, the monthly plan (paid /
 * late), every payment (principal + interest) and what is still owed.
 * Print prints only the statement (.print-area).
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('customer-loans.create'))
const canUpdate = computed(() => auth.can('customer-loans.update'))
const canDelete = computed(() => auth.can('customer-loans.delete'))

const loan = ref<CustomerLoan | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)

const money = (value: number | string) => formatMoney(Number(value), loan.value?.currency ?? 'USD')
const todayText = formatDate(new Date())
const printPage = () => window.print()
const isActive = computed(() => loan.value?.status === 'active')
const hasPayments = computed(() => (loan.value?.payments?.length ?? 0) > 0)
const statusVariant = { active: 'primary', paid_off: 'success', cancelled: 'neutral' } as const

const interestText = computed(() => {
  const l = loan.value
  if (!l || l.interest_type === 'none') return t('admin.borrowings.interestTypes.none')
  return t('admin.borrowings.interestShort', { rate: Number(l.interest_rate), period: t(`admin.borrowings.periods.${l.interest_period}`), type: t(`admin.borrowings.interestTypes.${l.interest_type}`) })
})

async function load() {
  try {
    loan.value = await customerLoansService.get(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.customerLoans.loadFailed')
  } finally {
    loading.value = false
  }
}

function failed(e: unknown, fallback: string) {
  if (e instanceof ApiRequestError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined
    actionError.value = first ?? e.message
  } else {
    actionError.value = fallback
  }
}

const payOpen = ref(false)
const editOpen = ref(false)

async function removePayment(payment: CustomerLoanPayment) {
  if (!(await confirmDialog.confirm({ message: t('admin.customerLoans.removePaymentConfirm'), danger: true }))) return
  actionError.value = null
  try {
    loan.value = await customerLoansService.removePayment(payment.id)
  } catch (e) {
    failed(e, t('admin.customerLoans.deleteFailed'))
  }
}

async function cancelLoan() {
  if (!loan.value || !(await confirmDialog.confirm({ message: t('admin.customerLoans.cancelConfirm'), danger: true }))) return
  actionError.value = null
  try {
    loan.value = await customerLoansService.cancel(loan.value.id)
  } catch (e) {
    failed(e, t('admin.customerLoans.saveFailed'))
  }
}

async function removeLoan() {
  if (!loan.value || !(await confirmDialog.confirm({ message: t('admin.customerLoans.deleteConfirm'), danger: true }))) return
  actionError.value = null
  try {
    await customerLoansService.remove(loan.value.id)
    router.push('/admin/customer-loans')
  } catch (e) {
    failed(e, t('admin.customerLoans.deleteFailed'))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseButton variant="outline" @click="router.push('/admin/customer-loans')">{{ t('admin.customerLoans.backToList') }}</BaseButton>
      <template v-if="loan">
        <BaseButton v-if="canCreate && isActive" @click="payOpen = true">{{ t('admin.customerLoans.recordPayment') }}</BaseButton>
        <BaseButton variant="outline" @click="printPage">{{ t('admin.customerLoans.print') }}</BaseButton>
        <div class="ml-auto flex flex-wrap gap-2">
          <BaseButton v-if="canUpdate && isActive" variant="outline" @click="editOpen = true">{{ t('admin.borrowings.edit') }}</BaseButton>
          <BaseButton v-if="canUpdate && isActive && !hasPayments" variant="outline" @click="cancelLoan">{{ t('admin.borrowings.cancelLoan') }}</BaseButton>
          <BaseButton v-if="canDelete && !hasPayments" variant="danger" @click="removeLoan">{{ t('admin.borrowings.delete') }}</BaseButton>
        </div>
      </template>
    </div>
    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4 print:hidden">{{ error || actionError }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="loan" class="print-area rounded-[--radius-card] border border-neutral-200 bg-white p-5 print:border-0 print:p-0">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 pb-3">
        <div>
          <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('admin.customerLoans.statementTitle') }}</h1>
          <p class="text-sm text-neutral-600">
            {{ loan.customer?.name ?? '—' }}<template v-if="loan.customer?.code"> ({{ loan.customer.code }})</template><template v-if="loan.customer?.phone"> · {{ loan.customer.phone }}</template>
          </p>
          <p class="text-sm text-neutral-600">{{ loan.loan_no }}<template v-if="loan.reference"> · {{ loan.reference }}</template></p>
        </div>
        <div class="text-right">
          <p class="text-xs text-neutral-500">{{ t('admin.savings.asOf', { date: todayText }) }}</p>
          <BaseBadge v-if="loan.late_count" variant="danger">{{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</BaseBadge>
          <BaseBadge v-else :variant="statusVariant[loan.status]">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
        </div>
      </div>

      <p class="mt-3 text-sm text-neutral-700">
        <template v-if="loan.flexible">{{ t('admin.customerLoans.termsLineFlexible', { amount: money(loan.amount), date: formatDate(loan.lent_on), interest: interestText }) }}</template>
        <template v-else>{{ t('admin.customerLoans.termsLine', { amount: money(loan.amount), date: formatDate(loan.lent_on), interest: interestText, months: loan.term_months, first: formatDate(loan.schedule?.[0]?.due_on ?? loan.first_payment_on) }) }}</template>
      </p>
      <p v-if="loan.purpose" class="text-sm text-neutral-500">{{ loan.purpose }}</p>
      <p v-if="!loan.posted && isActive" class="mt-1 text-xs text-amber-700 print:hidden">{{ t('admin.customerLoans.notPostedHint') }}</p>

      <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-neutral-500">{{ t('admin.customerLoans.amount') }}</dt><dd class="font-semibold tabular-nums">{{ money(loan.amount) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.customerLoans.principalBack') }}</dt><dd class="font-semibold tabular-nums">{{ money(loan.paid_principal) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.customerLoans.interestReceived') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ money(loan.paid_interest) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.customerLoans.owed') }}</dt><dd class="text-base font-semibold tabular-nums text-primary-800">{{ money(loan.outstanding) }}</dd></div>
      </dl>

      <template v-if="!loan.flexible && loan.schedule?.length">
        <h2 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.borrowings.schedule') }}</h2>
        <!-- Phone: one card per month -->
        <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 sm:hidden print:hidden">
          <li v-for="row in loan.schedule" :key="row.no" class="px-3 py-2 text-sm" :class="row.paid ? 'text-neutral-400' : ''">
            <div class="flex items-center justify-between gap-2">
              <p class="font-medium">
                {{ row.no }}. {{ formatDate(row.due_on) }}
                <BaseBadge v-if="row.paid" variant="success" class="ml-1">{{ t('admin.borrowings.paid') }}</BaseBadge>
                <BaseBadge v-else-if="row.late && isActive" variant="danger" class="ml-1">{{ t('admin.borrowings.late') }}</BaseBadge>
              </p>
              <p class="font-semibold tabular-nums">{{ money(row.total) }}</p>
            </div>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t('admin.customerLoans.startAmount') }} {{ money(row.start ?? row.balance + row.principal) }} → {{ money(row.balance) }}
              · {{ t('admin.customerLoans.principalPayment') }} {{ money(row.principal) }} · {{ t('admin.borrowings.interest') }} {{ money(row.interest) }}
            </p>
          </li>
        </ul>
        <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 sm:block print:block">
          <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 font-medium">{{ t('admin.customerLoans.no') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('admin.customerLoans.payDate') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.customerLoans.startAmount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.customerLoans.endAmount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.customerLoans.principalPayment') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.borrowings.payment') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in loan.schedule" :key="row.no" :class="row.paid ? 'text-neutral-400' : ''">
                <td class="px-3 py-2 text-neutral-500">{{ row.no }}</td>
                <td class="whitespace-nowrap px-3 py-2">
                  {{ formatDate(row.due_on) }}
                  <BaseBadge v-if="row.paid" variant="success" class="ml-1">{{ t('admin.borrowings.paid') }}</BaseBadge>
                  <BaseBadge v-else-if="row.late && isActive" variant="danger" class="ml-1">{{ t('admin.borrowings.late') }}</BaseBadge>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.start ?? row.balance + row.principal) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.balance) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.principal) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.interest) }}</td>
                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ money(row.total) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="loan.totals" class="border-t border-neutral-200 bg-neutral-50 font-semibold">
              <tr>
                <td class="px-3 py-2" colspan="4">{{ t('admin.customerLoans.total') }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(loan.totals.principal) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(loan.totals.interest) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(loan.totals.total) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
      <p v-else-if="loan.flexible" class="mt-4 rounded-lg bg-neutral-50 px-3 py-3 text-sm text-neutral-600">{{ t('admin.borrowings.flexibleHint') }}</p>

      <h2 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.customerLoans.payments') }}</h2>
      <p v-if="!hasPayments" class="rounded-lg bg-neutral-50 px-3 py-3 text-center text-sm text-neutral-500">{{ t('admin.customerLoans.noPayments') }}</p>
      <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
        <li v-for="row in loan.payments" :key="row.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
          <div class="min-w-0">
            <p class="font-medium text-neutral-800">
              {{ money(row.amount) }}
              <span class="font-normal text-neutral-500">· {{ formatDate(row.paid_on) }}<template v-if="row.cash_account"> · {{ row.cash_account.name }}</template></span>
            </p>
            <p class="text-xs text-neutral-500">
              {{ t('admin.borrowings.principal') }} {{ money(row.principal) }} · {{ t('admin.borrowings.interest') }} {{ money(row.interest) }}
              <template v-if="row.note"> · {{ row.note }}</template>
            </p>
          </div>
          <button v-if="canDelete && loan.status !== 'cancelled'" type="button" class="shrink-0 text-sm font-medium text-danger-600 hover:text-red-700 print:hidden" @click="removePayment(row)">
            {{ t('admin.savings.removeEntry') }}
          </button>
        </li>
      </ul>
    </div>

    <CustomerLoanPaymentModal v-model="payOpen" :loan="loan" @saved="load" />
    <CustomerLoanFormModal v-model="editOpen" :loan="loan" @saved="(saved) => (loan = saved)" />
  </div>
</template>
