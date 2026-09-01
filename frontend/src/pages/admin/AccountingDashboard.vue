<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountingReportsService, type AccountingSummary } from '@/services/accounting'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const summary = ref<AccountingSummary | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const stats = computed(() => {
  if (!summary.value) return []
  const s = summary.value
  return [
    { label: t('admin.accountingDashboard.statTotalRevenue'), value: `$${s.total_revenue.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statTotalExpenses'), value: `$${s.total_expenses.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statNetProfit'), value: `$${s.net_profit.toFixed(2)}`, negative: s.net_profit < 0 },
    { label: t('admin.accountingDashboard.statOutstanding'), value: `$${s.outstanding_receivables.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statTodaysIncome'), value: `$${s.todays_income.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statTodaysExpenses'), value: `$${s.todays_expenses.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statOverdue'), value: `$${s.overdue_receivables.toFixed(2)}` },
    { label: t('admin.accountingDashboard.statCashBalance'), value: `$${s.total_cash_balance.toFixed(2)}` },
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
        </BaseCard>
      </div>

      <div class="mt-6">
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.accountingDashboard.cashAccountsTitle') }}</h2>
          <p v-if="summary.cash_accounts.length === 0" class="text-sm text-neutral-400">{{ t('admin.accountingDashboard.noData') }}</p>
          <dl v-else class="space-y-2 text-sm">
            <div v-for="account in summary.cash_accounts" :key="account.id" class="flex justify-between">
              <dt class="text-neutral-500">{{ account.code }} — {{ account.name }}</dt>
              <dd class="font-medium text-neutral-900">${{ account.balance.toFixed(2) }}</dd>
            </div>
          </dl>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
