<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountingReportsService, type AccountingSummary, type AccountingSummaryMoney } from '@/services/accounting'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

const { t } = useI18n()

const summary = ref<AccountingSummary | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const stats = computed(() => {
  if (!summary.value) return []
  const s = summary.value
  // Every tile in USD and KHR — each amount at the rate on its own date.
  const tile = (labelKey: string, key: AccountingSummaryMoney) => ({
    label: t(labelKey),
    value: formatMoney(s[`${key}_usd`], 'USD'),
    khr: formatMoney(s[`${key}_khr`], 'KHR'),
    negative: key === 'net_profit' && s[`${key}_usd`] < 0,
  })
  return [
    tile('admin.accountingDashboard.statTotalRevenue', 'total_revenue'),
    tile('admin.accountingDashboard.statTotalExpenses', 'total_expenses'),
    tile('admin.accountingDashboard.statNetProfit', 'net_profit'),
    tile('admin.accountingDashboard.statOutstanding', 'outstanding_receivables'),
    tile('admin.accountingDashboard.statTodaysIncome', 'todays_income'),
    tile('admin.accountingDashboard.statTodaysExpenses', 'todays_expenses'),
    tile('admin.accountingDashboard.statOverdue', 'overdue_receivables'),
    tile('admin.accountingDashboard.statCashBalance', 'total_cash_balance'),
  ]
})

onMounted(async () => {
  loading.value = true
  loadError.value = null

  try {
    summary.value = await accountingReportsService.dashboard()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.accountingDashboard.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.accountingDashboard.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.accountingDashboard.pageSubtitle') }}</p>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="summary">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard v-for="stat in stats" :key="stat.label">
          <p class="text-sm font-medium text-neutral-500">{{ stat.label }}</p>
          <p class="mt-1 text-3xl font-bold" :class="stat.negative ? 'text-danger-600' : 'text-neutral-900'">{{ stat.value }}</p>
          <p class="text-base font-semibold" :class="stat.negative ? 'text-danger-600' : 'text-neutral-600'">{{ stat.khr }}</p>
        </BaseCard>
      </div>

      <div class="mt-6">
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingDashboard.cashAccountsTitle') }}</h2>
          <p v-if="summary.cash_accounts.length === 0" class="text-sm text-neutral-400">{{ t('admin.accountingDashboard.noData') }}</p>
          <table v-else class="w-full text-sm">
            <thead class="text-xs text-neutral-500">
              <tr><th></th><th class="pb-1 text-right font-medium">USD</th><th class="pb-1 pl-4 text-right font-medium">KHR</th></tr>
            </thead>
            <tbody>
              <tr v-for="account in summary.cash_accounts" :key="account.id">
                <td class="py-1 text-neutral-500">{{ account.code }} — {{ account.name }}</td>
                <td class="whitespace-nowrap py-1 text-right font-medium tabular-nums text-neutral-900">{{ formatMoney(account.balance_usd, 'USD') }}</td>
                <td class="whitespace-nowrap py-1 pl-4 text-right font-medium tabular-nums text-neutral-900">{{ formatMoney(account.balance_khr, 'KHR') }}</td>
              </tr>
            </tbody>
          </table>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
