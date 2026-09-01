<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { assetDashboardService, type AssetDashboardSummary } from '@/services/assetDashboard'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const summary = ref<AssetDashboardSummary | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const totalAssets = computed(() => {
  if (!summary.value) return 0
  return Object.values(summary.value.counts_by_status).reduce((sum, n) => sum + n, 0)
})

const stats = computed(() => {
  if (!summary.value) return []
  const s = summary.value
  return [
    { label: t('admin.assetDashboard.statTotalAssets'), value: String(totalAssets.value) },
    { label: t('admin.assetDashboard.statTotalInvestment'), value: `$${s.total_investment.toFixed(2)}` },
    { label: t('admin.assetDashboard.statTotalRepairCost'), value: `$${s.total_repair_cost.toFixed(2)}` },
    { label: t('admin.assetDashboard.statOpenIssues'), value: String(s.open_issues_count) },
    { label: t('admin.assetDashboard.statOpenRepairs'), value: String(s.open_repairs_count) },
    { label: t('admin.assetDashboard.statActiveAssignments'), value: String(s.assignment_totals.active) },
    { label: t('admin.assetDashboard.statOverdueReturns'), value: String(s.assignment_totals.overdue) },
  ]
})

function statusLabel(status: string): string {
  const key = status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
  return t(`admin.assets.status${key}`)
}

onMounted(async () => {
  loading.value = true
  loadError.value = null

  try {
    summary.value = await assetDashboardService.summary()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.assetDashboard.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetDashboard.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetDashboard.pageSubtitle') }}</p>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="summary">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard v-for="stat in stats" :key="stat.label">
          <p class="text-sm font-medium text-neutral-500">{{ stat.label }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ stat.value }}</p>
        </BaseCard>
      </div>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.byStatusTitle') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="(count, status) in summary.counts_by_status" :key="status" class="flex justify-between">
              <dt class="text-neutral-500">{{ statusLabel(status) }}</dt>
              <dd class="font-medium text-neutral-900">{{ count }}</dd>
            </div>
          </dl>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.byCategoryTitle') }}</h2>
          <p v-if="summary.counts_by_category.length === 0" class="text-sm text-neutral-400">{{ t('admin.assetDashboard.noData') }}</p>
          <dl v-else class="space-y-2 text-sm">
            <div v-for="row in summary.counts_by_category" :key="row.category_id" class="flex justify-between">
              <dt class="text-neutral-500">{{ row.category_name }}</dt>
              <dd class="font-medium text-neutral-900">{{ row.total }}</dd>
            </div>
          </dl>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.topRepairShopsTitle') }}</h2>
          <p v-if="summary.top_repair_shops.length === 0" class="text-sm text-neutral-400">{{ t('admin.assetDashboard.noData') }}</p>
          <dl v-else class="space-y-2 text-sm">
            <div v-for="shop in summary.top_repair_shops" :key="shop.repair_shop_id" class="flex justify-between">
              <dt class="text-neutral-500">{{ shop.repair_shop_name }} ({{ shop.repair_count }})</dt>
              <dd class="font-medium text-neutral-900">${{ shop.total_cost.toFixed(2) }}</dd>
            </div>
          </dl>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.recentIssuesTitle') }}</h2>
          <p v-if="summary.recent_issues.length === 0" class="text-sm text-neutral-400">{{ t('admin.assetDashboard.noData') }}</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="issue in summary.recent_issues" :key="issue.id" class="flex justify-between">
              <RouterLink :to="`/admin/assets/${issue.asset?.id}`" class="text-primary-700 hover:underline">{{ issue.asset?.asset_number }}</RouterLink>
              <span class="text-neutral-500">{{ issue.title }}</span>
            </li>
          </ul>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.upcomingMaintenanceTitle') }}</h2>
          <p v-if="summary.upcoming_maintenance.length === 0" class="text-sm text-neutral-400">{{ t('admin.assetDashboard.noData') }}</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="record in summary.upcoming_maintenance" :key="record.id" class="flex justify-between">
              <RouterLink :to="`/admin/assets/${record.asset?.id}`" class="text-primary-700 hover:underline">{{ record.asset?.asset_number }}</RouterLink>
              <span class="text-neutral-500">{{ record.scheduled_date }}</span>
            </li>
          </ul>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetDashboard.warrantyExpiringTitle') }}</h2>
          <p v-if="summary.warranty_expiring.length === 0" class="text-sm text-neutral-400">{{ t('admin.assetDashboard.noData') }}</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="asset in summary.warranty_expiring" :key="asset.id" class="flex justify-between">
              <RouterLink :to="`/admin/assets/${asset.id}`" class="text-primary-700 hover:underline">{{ asset.asset_number }}</RouterLink>
              <span class="text-neutral-500">{{ asset.warranty_end_date }}</span>
            </li>
          </ul>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
