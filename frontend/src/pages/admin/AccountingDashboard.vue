<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountingReportsService, type AccountingSummary } from '@/services/accounting'
import { ApiRequestError } from '@/types/api'
import { useMoney } from '@/composables/useMoney'

const { t } = useI18n()
const { money } = useMoney()

const summary = ref<AccountingSummary | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const stats = computed(() => {
  if (!summary.value) return []
  const s = summary.value
  return [
    { label: t('admin.accountingDashboard.statTotalRevenue'), value: money(s.total_revenue) },
    { label: t('admin.accountingDashboard.statTotalExpenses'), value: money(s.total_expenses) },
    { label: t('admin.accountingDashboard.statNetProfit'), value: money(s.net_profit), negative: s.net_profit < 0 },
    { label: t('admin.accountingDashboard.statOutstanding'), value: money(s.outstanding_receivables) },
    { label: t('admin.accountingDashboard.statTodaysIncome'), value: money(s.todays_income) },
    { label: t('admin.accountingDashboard.statTodaysExpenses'), value: money(s.todays_expenses) },
    { label: t('admin.accountingDashboard.statOverdue'), value: money(s.overdue_receivables) },
    { label: t('admin.accountingDashboard.statCashBalance'), value: money(s.total_cash_balance) },
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
              <dd class="font-medium text-neutral-900">{{ money(account.balance) }}</dd>
            </div>
          </dl>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
