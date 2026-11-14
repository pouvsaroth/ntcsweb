<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountingReportsService, type CashFlowKey, type CashFlowReport, type ProfitLossReport, type ReportLine, type ReportTotals } from '@/services/accounting'
import { apiDownload } from '@/services/http'
import { ApiRequestError } from '@/types/api'
import { useMoney } from '@/composables/useMoney'

const { t } = useI18n()
const { money } = useMoney()

type Tab = 'revenue' | 'expenses' | 'profit-loss' | 'cash-flow'

const tabs: { key: Tab; labelKey: string }[] = [
  { key: 'revenue', labelKey: 'admin.accountingReports.tabRevenue' },
  { key: 'expenses', labelKey: 'admin.accountingReports.tabExpenses' },
  { key: 'profit-loss', labelKey: 'admin.accountingReports.tabProfitLoss' },
  { key: 'cash-flow', labelKey: 'admin.accountingReports.tabCashFlow' },
]

const activeTab = ref<Tab>('revenue')

function firstOfMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const dateFrom = ref(firstOfMonth())
const dateTo = ref(today())

const loading = ref(false)
const loadError = ref<string | null>(null)

const revenue = ref<ReportTotals<ReportLine> | null>(null)
const expenses = ref<ReportTotals<ReportLine> | null>(null)
const profitLoss = ref<ProfitLossReport | null>(null)
const cashFlow = ref<CashFlowReport | null>(null)

const params = computed(() => ({ date_from: dateFrom.value, date_to: dateTo.value }))

async function load() {
  loading.value = true
  loadError.value = null

  try {
    if (activeTab.value === 'revenue') revenue.value = await accountingReportsService.revenue(params.value)
    else if (activeTab.value === 'expenses') expenses.value = await accountingReportsService.expenses(params.value)
    else if (activeTab.value === 'profit-loss') profitLoss.value = await accountingReportsService.profitLoss(params.value)
    else cashFlow.value = await accountingReportsService.cashFlow(params.value)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.accountingReports.loadFailed')
  } finally {
    loading.value = false
  }
}

async function exportCsv() {
  const endpoint = activeTab.value === 'revenue' ? 'revenue' : 'expenses'
  const query = new URLSearchParams({ format: 'csv', date_from: dateFrom.value, date_to: dateTo.value }).toString()
  await apiDownload(`/accounting/reports/${endpoint}?${query}`, `${endpoint}-report-${dateTo.value}.csv`)
}

// Every amount shows in USD and in KHR (two columns); each transaction is
// converted at the exchange rate in effect on its own date (done by the API).
const usd = (amount: number) => money(amount, 'USD')
const khr = (amount: number) => money(amount, 'KHR')

/** A cash flow row's amount with its direction: "+" in, "-" out, nothing for zero; a negative in-row reads as "-". */
function signed(sign: '+' | '-', amount: number, format: (n: number) => string): string {
  const value = sign === '+' ? amount : -amount
  const text = format(Math.abs(value))
  if (Math.abs(value) < 0.005) return text
  return (value < 0 ? '-' : '+') + text
}

/** Cash flow rows between opening and closing; `always` rows show even when zero. */
const cashFlowRows: { key: CashFlowKey; labelKey: string; sign: '+' | '-'; always?: boolean }[] = [
  { key: 'student_payments', labelKey: 'admin.accountingReports.studentPayments', sign: '+', always: true },
  { key: 'other_income', labelKey: 'admin.accountingReports.otherIncome', sign: '+', always: true },
  { key: 'loans_received', labelKey: 'admin.accountingReports.loansReceived', sign: '+' },
  { key: 'savings_deposits', labelKey: 'admin.accountingReports.savingsDeposits', sign: '+' },
  { key: 'loan_collections', labelKey: 'admin.accountingReports.loanCollections', sign: '+' },
  { key: 'fund_in', labelKey: 'admin.accountingReports.fundIn', sign: '+' },
  { key: 'loan_interest_received', labelKey: 'admin.accountingReports.loanInterestReceived', sign: '+' },
  { key: 'expenses', labelKey: 'admin.accountingReports.expensesOut', sign: '-', always: true },
  { key: 'loan_repayments', labelKey: 'admin.accountingReports.loanRepayments', sign: '-' },
  { key: 'savings_withdrawals', labelKey: 'admin.accountingReports.savingsWithdrawals', sign: '-' },
  { key: 'fund_out', labelKey: 'admin.accountingReports.fundOut', sign: '-' },
  { key: 'loans_given', labelKey: 'admin.accountingReports.loansGiven', sign: '-' },
]

watch([activeTab, dateFrom, dateTo], () => load())
onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.accountingReports.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.accountingReports.pageSubtitle') }}</p>
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
      <div class="flex gap-2 border-b border-neutral-200">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium"
          :class="activeTab === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
          @click="activeTab = tab.key"
        >
          {{ t(tab.labelKey) }}
        </button>
      </div>

      <div class="flex items-end gap-2">
        <BaseInput v-model="dateFrom" type="date" :label="t('admin.accountingReports.dateFrom')" />
        <BaseInput v-model="dateTo" type="date" :label="t('admin.accountingReports.dateTo')" />
        <BaseButton v-if="activeTab === 'revenue' || activeTab === 'expenses'" variant="outline" @click="exportCsv">
          {{ t('admin.accountingReports.exportCsv') }}
        </BaseButton>
      </div>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else>
      <div v-if="activeTab === 'revenue' && revenue" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <table class="w-full text-left text-sm">
          <thead class="text-neutral-500"><tr><th class="pb-2 font-medium">{{ t('admin.accountingReports.columnAccount') }}</th><th class="pb-2 text-right font-medium">USD</th><th class="pb-2 pl-4 text-right font-medium">KHR</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="line in revenue.lines" :key="line.account_id"><td class="py-2">{{ line.account_code }} — {{ line.account_name }}</td><td class="py-2 text-right whitespace-nowrap">{{ usd(line.amount_usd) }}</td><td class="py-2 pl-4 text-right whitespace-nowrap">{{ khr(line.amount_khr) }}</td></tr>
          </tbody>
          <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-2">{{ t('admin.accountingReports.total') }}</td><td class="pt-2 text-right whitespace-nowrap">{{ usd(revenue.total_usd) }}</td><td class="pt-2 pl-4 text-right whitespace-nowrap">{{ khr(revenue.total_khr) }}</td></tr></tfoot>
        </table>
        <p v-if="revenue.lines.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t('admin.accountingReports.noData') }}</p>
      </div>

      <div v-else-if="activeTab === 'expenses' && expenses" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <table class="w-full text-left text-sm">
          <thead class="text-neutral-500"><tr><th class="pb-2 font-medium">{{ t('admin.accountingReports.columnAccount') }}</th><th class="pb-2 text-right font-medium">USD</th><th class="pb-2 pl-4 text-right font-medium">KHR</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="line in expenses.lines" :key="line.account_id"><td class="py-2">{{ line.account_code }} — {{ line.account_name }}</td><td class="py-2 text-right whitespace-nowrap">{{ usd(line.amount_usd) }}</td><td class="py-2 pl-4 text-right whitespace-nowrap">{{ khr(line.amount_khr) }}</td></tr>
          </tbody>
          <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-2">{{ t('admin.accountingReports.total') }}</td><td class="pt-2 text-right whitespace-nowrap">{{ usd(expenses.total_usd) }}</td><td class="pt-2 pl-4 text-right whitespace-nowrap">{{ khr(expenses.total_khr) }}</td></tr></tfoot>
        </table>
        <p v-if="expenses.lines.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t('admin.accountingReports.noData') }}</p>
      </div>

      <div v-else-if="activeTab === 'profit-loss' && profitLoss" class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingReports.tabRevenue') }}</h2>
          <table class="w-full text-left text-sm">
            <thead class="text-neutral-500"><tr><th class="pb-2 font-medium"></th><th class="pb-2 text-right font-medium">USD</th><th class="pb-2 pl-4 text-right font-medium">KHR</th></tr></thead>
            <tbody>
              <tr v-for="line in profitLoss.revenue.lines" :key="line.account_name"><td class="py-1 text-neutral-500">{{ line.account_name }}</td><td class="py-1 text-right font-medium whitespace-nowrap text-neutral-900">{{ usd(line.amount_usd) }}</td><td class="py-1 pl-4 text-right font-medium whitespace-nowrap text-neutral-900">{{ khr(line.amount_khr) }}</td></tr>
            </tbody>
            <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-3">{{ t('admin.accountingReports.total') }}</td><td class="pt-3 text-right whitespace-nowrap">{{ usd(profitLoss.revenue.total_usd) }}</td><td class="pt-3 pl-4 text-right whitespace-nowrap">{{ khr(profitLoss.revenue.total_khr) }}</td></tr></tfoot>
          </table>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingReports.tabExpenses') }}</h2>
          <table class="w-full text-left text-sm">
            <thead class="text-neutral-500"><tr><th class="pb-2 font-medium"></th><th class="pb-2 text-right font-medium">USD</th><th class="pb-2 pl-4 text-right font-medium">KHR</th></tr></thead>
            <tbody>
              <tr v-for="line in profitLoss.expenses.lines" :key="line.account_name"><td class="py-1 text-neutral-500">{{ line.account_name }}</td><td class="py-1 text-right font-medium whitespace-nowrap text-neutral-900">{{ usd(line.amount_usd) }}</td><td class="py-1 pl-4 text-right font-medium whitespace-nowrap text-neutral-900">{{ khr(line.amount_khr) }}</td></tr>
            </tbody>
            <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-3">{{ t('admin.accountingReports.total') }}</td><td class="pt-3 text-right whitespace-nowrap">{{ usd(profitLoss.expenses.total_usd) }}</td><td class="pt-3 pl-4 text-right whitespace-nowrap">{{ khr(profitLoss.expenses.total_khr) }}</td></tr></tfoot>
          </table>
        </div>
        <div class="lg:col-span-2 rounded-[--radius-card] border p-5 text-center" :class="profitLoss.is_profit ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
          <p class="text-sm font-medium" :class="profitLoss.is_profit ? 'text-green-700' : 'text-danger-700'">{{ profitLoss.is_profit ? t('admin.accountingReports.netProfit') : t('admin.accountingReports.netLoss') }}</p>
          <p class="mt-1 flex flex-wrap justify-center gap-x-6 text-3xl font-bold" :class="profitLoss.is_profit ? 'text-green-800' : 'text-danger-800'">
            <span>{{ usd(Math.abs(profitLoss.net_profit_usd)) }}</span><span>{{ khr(Math.abs(profitLoss.net_profit_khr)) }}</span>
          </p>
        </div>
      </div>

      <div v-else-if="activeTab === 'cash-flow' && cashFlow" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <table class="w-full text-left text-sm">
          <thead class="text-neutral-500"><tr><th class="pb-2 font-medium"></th><th class="pb-2 text-right font-medium">USD</th><th class="pb-2 pl-4 text-right font-medium">KHR</th></tr></thead>
          <tbody>
            <tr><td class="py-1.5 text-neutral-500">{{ t('admin.accountingReports.openingBalance') }}</td><td class="py-1.5 text-right font-medium whitespace-nowrap text-neutral-900">{{ usd(cashFlow.opening_usd) }}</td><td class="py-1.5 pl-4 text-right font-medium whitespace-nowrap text-neutral-900">{{ khr(cashFlow.opening_khr) }}</td></tr>
            <template v-for="row in cashFlowRows" :key="row.key">
              <tr v-if="row.always || cashFlow[row.key]">
                <td class="py-1.5 text-neutral-500">{{ t(row.labelKey) }}</td>
                <td class="py-1.5 text-right font-medium whitespace-nowrap" :class="row.sign === '+' ? 'text-green-700' : 'text-danger-700'">{{ signed(row.sign, cashFlow[`${row.key}_usd`], usd) }}</td>
                <td class="py-1.5 pl-4 text-right font-medium whitespace-nowrap" :class="row.sign === '+' ? 'text-green-700' : 'text-danger-700'">{{ signed(row.sign, cashFlow[`${row.key}_khr`], khr) }}</td>
              </tr>
            </template>
          </tbody>
          <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-3">{{ t('admin.accountingReports.closingBalance') }}</td><td class="pt-3 text-right whitespace-nowrap">{{ usd(cashFlow.closing_usd) }}</td><td class="pt-3 pl-4 text-right whitespace-nowrap">{{ khr(cashFlow.closing_khr) }}</td></tr></tfoot>
        </table>
      </div>
    </template>
  </div>
</template>
