<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountingReportsService, type CashFlowReport, type ProfitLossReport, type ReportLine } from '@/services/accounting'
import { apiDownload } from '@/services/http'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

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

const revenue = ref<{ lines: ReportLine[]; total: number } | null>(null)
const expenses = ref<{ lines: ReportLine[]; total: number } | null>(null)
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
          <thead class="text-neutral-500"><tr><th class="pb-2 font-medium">{{ t('admin.accountingReports.columnAccount') }}</th><th class="pb-2 text-right font-medium">{{ t('admin.accountingReports.columnAmount') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="line in revenue.lines" :key="line.account_id"><td class="py-2">{{ line.account_code }} — {{ line.account_name }}</td><td class="py-2 text-right">${{ line.amount.toFixed(2) }}</td></tr>
          </tbody>
          <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-2">{{ t('admin.accountingReports.total') }}</td><td class="pt-2 text-right">${{ revenue.total.toFixed(2) }}</td></tr></tfoot>
        </table>
        <p v-if="revenue.lines.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t('admin.accountingReports.noData') }}</p>
      </div>

      <div v-else-if="activeTab === 'expenses' && expenses" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <table class="w-full text-left text-sm">
          <thead class="text-neutral-500"><tr><th class="pb-2 font-medium">{{ t('admin.accountingReports.columnAccount') }}</th><th class="pb-2 text-right font-medium">{{ t('admin.accountingReports.columnAmount') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="line in expenses.lines" :key="line.account_id"><td class="py-2">{{ line.account_code }} — {{ line.account_name }}</td><td class="py-2 text-right">${{ line.amount.toFixed(2) }}</td></tr>
          </tbody>
          <tfoot><tr class="border-t border-neutral-200 font-semibold text-neutral-900"><td class="pt-2">{{ t('admin.accountingReports.total') }}</td><td class="pt-2 text-right">${{ expenses.total.toFixed(2) }}</td></tr></tfoot>
        </table>
        <p v-if="expenses.lines.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t('admin.accountingReports.noData') }}</p>
      </div>

      <div v-else-if="activeTab === 'profit-loss' && profitLoss" class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingReports.tabRevenue') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="line in profitLoss.revenue.lines" :key="line.account_name" class="flex justify-between"><dt class="text-neutral-500">{{ line.account_name }}</dt><dd class="font-medium text-neutral-900">${{ line.amount.toFixed(2) }}</dd></div>
          </dl>
          <div class="mt-3 flex justify-between border-t border-neutral-200 pt-3 text-sm font-semibold text-neutral-900"><span>{{ t('admin.accountingReports.total') }}</span><span>${{ profitLoss.revenue.total.toFixed(2) }}</span></div>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingReports.tabExpenses') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="line in profitLoss.expenses.lines" :key="line.account_name" class="flex justify-between"><dt class="text-neutral-500">{{ line.account_name }}</dt><dd class="font-medium text-neutral-900">${{ line.amount.toFixed(2) }}</dd></div>
          </dl>
          <div class="mt-3 flex justify-between border-t border-neutral-200 pt-3 text-sm font-semibold text-neutral-900"><span>{{ t('admin.accountingReports.total') }}</span><span>${{ profitLoss.expenses.total.toFixed(2) }}</span></div>
        </div>
        <div class="lg:col-span-2 rounded-[--radius-card] border p-5 text-center" :class="profitLoss.is_profit ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
          <p class="text-sm font-medium" :class="profitLoss.is_profit ? 'text-green-700' : 'text-danger-700'">{{ profitLoss.is_profit ? t('admin.accountingReports.netProfit') : t('admin.accountingReports.netLoss') }}</p>
          <p class="mt-1 text-3xl font-bold" :class="profitLoss.is_profit ? 'text-green-800' : 'text-danger-800'">${{ Math.abs(profitLoss.net_profit).toFixed(2) }}</p>
        </div>
      </div>

      <div v-else-if="activeTab === 'cash-flow' && cashFlow" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <dl class="space-y-3 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.accountingReports.openingBalance') }}</dt><dd class="font-medium text-neutral-900">${{ cashFlow.opening.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.accountingReports.studentPayments') }}</dt><dd class="font-medium text-green-700">+${{ cashFlow.student_payments.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.accountingReports.otherIncome') }}</dt><dd class="font-medium text-green-700">+${{ cashFlow.other_income.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.accountingReports.expensesOut') }}</dt><dd class="font-medium text-danger-700">-${{ cashFlow.expenses.toFixed(2) }}</dd></div>
          <div class="flex justify-between border-t border-neutral-200 pt-3 font-semibold text-neutral-900"><dt>{{ t('admin.accountingReports.closingBalance') }}</dt><dd>${{ cashFlow.closing.toFixed(2) }}</dd></div>
        </dl>
      </div>
    </template>
  </div>
</template>
