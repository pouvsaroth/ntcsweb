<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { borrowingsService, type Borrowing } from '@/services/borrowings'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Accounting > Borrowings: one loan's statement — terms, the monthly plan
 * (paid / late), every payment and what is still owed. Print prints only
 * the statement (.print-area).
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loan = ref<Borrowing | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const money = (value: number | string) => formatMoney(Number(value), loan.value?.currency ?? 'USD')
const todayText = formatDate(new Date())
const printPage = () => window.print()

const interestText = computed(() => {
  const l = loan.value
  if (!l || l.interest_type === 'none') return t('admin.borrowings.interestTypes.none')
  return t('admin.borrowings.interestShort', { rate: Number(l.interest_rate), period: t(`admin.borrowings.periods.${l.interest_period}`), type: t(`admin.borrowings.interestTypes.${l.interest_type}`) })
})

onMounted(async () => {
  try {
    loan.value = await borrowingsService.get(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseButton variant="outline" @click="router.push('/admin/borrowings/loans')">{{ t('admin.borrowings.backToLoans') }}</BaseButton>
      <BaseButton v-if="loan" @click="printPage">{{ t('admin.borrowings.printStatement') }}</BaseButton>
    </div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="loan" class="print-area rounded-[--radius-card] border border-neutral-200 bg-white p-5 print:border-0 print:p-0">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 pb-3">
        <div>
          <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('admin.borrowings.statementTitle') }}</h1>
          <p class="text-sm text-neutral-600">{{ loan.lender?.name }} · {{ t('admin.borrowings.forName', { name: loan.borrowed_for?.name ?? '—' }) }}<template v-if="loan.reference"> · {{ loan.reference }}</template></p>
        </div>
        <div class="text-right">
          <p class="text-xs text-neutral-500">{{ t('admin.borrowings.statementAsOf', { date: todayText }) }}</p>
          <BaseBadge :variant="loan.status === 'paid_off' ? 'success' : loan.status === 'cancelled' ? 'neutral' : 'primary'">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
        </div>
      </div>

      <p class="mt-3 text-sm text-neutral-700">
        {{ t('admin.borrowings.termsLine', { amount: money(loan.amount), date: formatDate(loan.received_on), interest: interestText, months: loan.term_months, first: formatDate(loan.first_payment_on) }) }}
      </p>
      <p v-if="loan.purpose" class="text-sm text-neutral-500">{{ loan.purpose }}</p>

      <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-neutral-500">{{ t('admin.borrowings.amount') }}</dt><dd class="font-semibold">{{ money(loan.amount) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.borrowings.paidBack') }}</dt><dd class="font-semibold">{{ money(loan.paid_principal) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.borrowings.interestPaid') }}</dt><dd class="font-semibold">{{ money(loan.paid_interest) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.borrowings.outstanding') }}</dt><dd class="font-semibold text-primary-800">{{ money(loan.outstanding) }}</dd></div>
      </dl>

      <h2 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.borrowings.schedule') }}</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 text-neutral-500">
            <tr>
              <th class="py-2 pr-3 font-medium">#</th>
              <th class="py-2 pr-3 font-medium">{{ t('admin.borrowings.dueOn') }}</th>
              <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.principal') }}</th>
              <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
              <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.payment') }}</th>
              <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.balance') }}</th>
              <th class="py-2 font-medium" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in loan.schedule" :key="row.no">
              <td class="py-1.5 pr-3 text-neutral-500">{{ row.no }}</td>
              <td class="py-1.5 pr-3">{{ formatDate(row.due_on) }}</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ money(row.principal) }}</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ money(row.interest) }}</td>
              <td class="py-1.5 pr-3 text-right font-semibold tabular-nums">{{ money(row.total) }}</td>
              <td class="py-1.5 pr-3 text-right tabular-nums">{{ money(row.balance) }}</td>
              <td class="py-1.5 text-xs">
                <span v-if="row.paid" class="text-green-700">{{ t('admin.borrowings.paid') }}</span>
                <span v-else-if="row.late && loan.status === 'active'" class="text-danger-700">{{ t('admin.borrowings.late') }}</span>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t border-neutral-200 font-semibold">
            <tr>
              <td colspan="2" class="py-2">{{ t('admin.accountingReports.total') }}</td>
              <td class="py-2 pr-3 text-right tabular-nums">{{ money(loan.totals.principal) }}</td>
              <td class="py-2 pr-3 text-right tabular-nums">{{ money(loan.totals.interest) }}</td>
              <td class="py-2 pr-3 text-right tabular-nums">{{ money(loan.totals.total) }}</td>
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>

      <h2 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.borrowings.history') }}</h2>
      <p v-if="!loan.repayments || loan.repayments.length === 0" class="text-sm text-neutral-500">{{ t('admin.borrowings.noRepayments') }}</p>
      <table v-else class="w-full text-left text-sm">
        <thead class="border-b border-neutral-200 text-neutral-500">
          <tr>
            <th class="py-2 pr-3 font-medium">{{ t('admin.borrowings.paidOn') }}</th>
            <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.principal') }}</th>
            <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
            <th class="py-2 pr-3 text-right font-medium">{{ t('admin.borrowings.payTotal') }}</th>
            <th class="py-2 font-medium">{{ t('admin.borrowings.note') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="row in [...loan.repayments].reverse()" :key="row.id">
            <td class="py-1.5 pr-3">{{ formatDate(row.paid_on) }}</td>
            <td class="py-1.5 pr-3 text-right tabular-nums">{{ money(row.principal) }}</td>
            <td class="py-1.5 pr-3 text-right tabular-nums">{{ money(row.interest) }}</td>
            <td class="py-1.5 pr-3 text-right font-semibold tabular-nums">{{ money(row.amount) }}</td>
            <td class="py-1.5 text-neutral-600">{{ row.note ?? '' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style>
/* Print only the statement. */
@media print {
  body * {
    visibility: hidden;
  }
  .print-area,
  .print-area * {
    visibility: visible;
  }
  .print-area {
    position: absolute;
    inset: 0 auto auto 0;
    width: 100%;
  }
  @page {
    size: portrait;
    margin: 12mm;
  }
}
</style>
