<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { apiDownload } from '@/services/http'
import { assetReportsService, type AssetRepairCostReport, type AssetStatusReport } from '@/services/assetReports'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

type Tab = 'inventory' | 'status' | 'repairs' | 'repair-cost' | 'maintenance' | 'assignments' | 'history'

const tabs: { key: Tab; labelKey: string }[] = [
  { key: 'inventory', labelKey: 'admin.assetReports.tabInventory' },
  { key: 'status', labelKey: 'admin.assetReports.tabStatus' },
  { key: 'repairs', labelKey: 'admin.assetReports.tabRepairs' },
  { key: 'repair-cost', labelKey: 'admin.assetReports.tabRepairCost' },
  { key: 'maintenance', labelKey: 'admin.assetReports.tabMaintenance' },
  { key: 'assignments', labelKey: 'admin.assetReports.tabAssignments' },
  { key: 'history', labelKey: 'admin.assetReports.tabHistory' },
]

const activeTab = ref<Tab>('inventory')

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

const statusReport = ref<AssetStatusReport | null>(null)
const repairCostReport = ref<AssetRepairCostReport | null>(null)
const loadingAggregate = ref(false)
const loadError = ref<string | null>(null)

const inventory = usePaginatedResource((query) => assetReportsService.inventory(query))
const repairs = usePaginatedResource((query) => assetReportsService.repairs(query))
const maintenance = usePaginatedResource((query) => assetReportsService.maintenance(query))
const assignments = usePaginatedResource((query) => assetReportsService.assignments(query))
const history = usePaginatedResource((query) => assetReportsService.history({ ...query, date_from: dateFrom.value, date_to: dateTo.value }))

async function loadAggregate() {
  loadingAggregate.value = true
  loadError.value = null

  try {
    if (activeTab.value === 'status') statusReport.value = await assetReportsService.status()
    else if (activeTab.value === 'repair-cost') repairCostReport.value = await assetReportsService.repairCost({ date_from: dateFrom.value, date_to: dateTo.value })
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.assetReports.loadFailed')
  } finally {
    loadingAggregate.value = false
  }
}

async function loadActiveTab() {
  if (activeTab.value === 'inventory') await inventory.fetch()
  else if (activeTab.value === 'repairs') await repairs.fetch()
  else if (activeTab.value === 'maintenance') await maintenance.fetch()
  else if (activeTab.value === 'assignments') await assignments.fetch()
  else if (activeTab.value === 'history') await history.fetch()
  else await loadAggregate()
}

async function exportCsv() {
  const endpointMap: Record<Tab, string> = {
    inventory: 'inventory', status: 'status', repairs: 'repairs', 'repair-cost': 'repair-cost',
    maintenance: 'maintenance', assignments: 'assignments', history: 'history',
  }
  const query = new URLSearchParams({ format: 'csv', date_from: dateFrom.value, date_to: dateTo.value }).toString()
  await apiDownload(`/assets/reports/${endpointMap[activeTab.value]}?${query}`, `${endpointMap[activeTab.value]}-report-${dateTo.value}.csv`)
}

watch(activeTab, () => loadActiveTab())
onMounted(() => loadActiveTab())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetReports.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetReports.pageSubtitle') }}</p>
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
      <div class="flex flex-wrap gap-2 border-b border-neutral-200">
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
        <template v-if="activeTab === 'repair-cost' || activeTab === 'history'">
          <BaseInput v-model="dateFrom" type="date" :label="t('admin.assetReports.dateFrom')" @update:model-value="loadActiveTab" />
          <BaseInput v-model="dateTo" type="date" :label="t('admin.assetReports.dateTo')" @update:model-value="loadActiveTab" />
        </template>
        <BaseButton variant="outline" @click="exportCsv">{{ t('admin.assetReports.exportCsv') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <BaseSpinner v-if="loadingAggregate" class="mx-auto" />

    <template v-else-if="activeTab === 'status' && statusReport">
      <div class="grid gap-6 lg:grid-cols-2">
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetReports.byStatus') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="(count, status) in statusReport.counts_by_status" :key="status" class="flex justify-between">
              <dt class="text-neutral-500">{{ status }}</dt>
              <dd class="font-medium text-neutral-900">{{ count }}</dd>
            </div>
          </dl>
        </BaseCard>
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetReports.byCategory') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="row in statusReport.counts_by_category" :key="row.category_id" class="flex justify-between">
              <dt class="text-neutral-500">{{ row.category_name }}</dt>
              <dd class="font-medium text-neutral-900">{{ row.total }}</dd>
            </div>
          </dl>
        </BaseCard>
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assetReports.byLocation') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="row in statusReport.counts_by_location" :key="row.location_id" class="flex justify-between">
              <dt class="text-neutral-500">{{ row.location_name }}</dt>
              <dd class="font-medium text-neutral-900">{{ row.total }}</dd>
            </div>
          </dl>
        </BaseCard>
      </div>
    </template>

    <template v-else-if="activeTab === 'repair-cost' && repairCostReport">
      <BaseCard>
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assetReports.byRepairShop') }}</h2>
          <p class="text-lg font-semibold text-neutral-900">${{ repairCostReport.total_repair_cost.toFixed(2) }}</p>
        </div>
        <dl class="space-y-2 text-sm">
          <div v-for="shop in repairCostReport.by_repair_shop" :key="shop.repair_shop_id" class="flex justify-between">
            <dt class="text-neutral-500">{{ shop.repair_shop_name }} ({{ shop.repair_count }})</dt>
            <dd class="font-medium text-neutral-900">${{ shop.total_cost.toFixed(2) }}</dd>
          </div>
        </dl>
      </BaseCard>
    </template>

    <template v-else-if="activeTab === 'inventory'">
      <DataTable
        :columns="[
          { key: 'asset_number', label: t('admin.assets.columnAssetNumber') },
          { key: 'name', label: t('admin.assets.columnName') },
          { key: 'category', label: t('admin.assets.columnCategory') },
          { key: 'status', label: t('admin.assets.columnStatus') },
          { key: 'purchase_price', label: t('admin.assets.purchasePrice'), align: 'text-right' },
        ]"
        :rows="inventory.items.value"
        row-key="id"
        :loading="inventory.loading.value"
      >
        <template #cell-category="{ row }">{{ row.category?.name ?? '—' }}</template>
        <template #cell-purchase_price="{ row }">${{ row.purchase_price.toFixed(2) }}</template>
      </DataTable>
      <BasePagination v-if="inventory.meta.value" :meta="inventory.meta.value" class="mt-4" @update:page="inventory.setPage" />
    </template>

    <template v-else-if="activeTab === 'repairs'">
      <DataTable
        :columns="[
          { key: 'repair_number', label: t('admin.assetRepairs.columnNumber') },
          { key: 'asset', label: t('admin.assetRepairs.columnAsset') },
          { key: 'status', label: t('admin.assetRepairs.status') },
          { key: 'total_cost', label: t('admin.assetRepairs.totalCost'), align: 'text-right' },
        ]"
        :rows="repairs.items.value"
        row-key="id"
        :loading="repairs.loading.value"
      >
        <template #cell-asset="{ row }">{{ row.asset?.asset_number ?? '—' }}</template>
        <template #cell-total_cost="{ row }">${{ row.total_cost.toFixed(2) }}</template>
      </DataTable>
      <BasePagination v-if="repairs.meta.value" :meta="repairs.meta.value" class="mt-4" @update:page="repairs.setPage" />
    </template>

    <template v-else-if="activeTab === 'maintenance'">
      <DataTable
        :columns="[
          { key: 'maintenance_number', label: t('admin.assetMaintenance.columnNumber') },
          { key: 'asset', label: t('admin.assetMaintenance.columnAsset') },
          { key: 'maintenance_type', label: t('admin.assetMaintenance.maintenanceType') },
          { key: 'status', label: t('admin.assetMaintenance.status') },
        ]"
        :rows="maintenance.items.value"
        row-key="id"
        :loading="maintenance.loading.value"
      >
        <template #cell-asset="{ row }">{{ row.asset?.asset_number ?? '—' }}</template>
      </DataTable>
      <BasePagination v-if="maintenance.meta.value" :meta="maintenance.meta.value" class="mt-4" @update:page="maintenance.setPage" />
    </template>

    <template v-else-if="activeTab === 'assignments'">
      <DataTable
        :columns="[
          { key: 'assignable_label', label: t('admin.assets.assignee') },
          { key: 'assigned_date', label: t('admin.assets.assignedDate') },
          { key: 'status', label: t('admin.assets.columnStatus') },
        ]"
        :rows="assignments.items.value"
        row-key="id"
        :loading="assignments.loading.value"
      />
      <BasePagination v-if="assignments.meta.value" :meta="assignments.meta.value" class="mt-4" @update:page="assignments.setPage" />
    </template>

    <template v-else-if="activeTab === 'history'">
      <DataTable
        :columns="[
          { key: 'occurred_at', label: t('admin.assets.historyDate') },
          { key: 'event_type', label: t('admin.assets.historyEvent') },
          { key: 'description', label: t('admin.assets.historyDescription') },
        ]"
        :rows="history.items.value"
        row-key="id"
        :loading="history.loading.value"
      >
        <template #cell-occurred_at="{ row }">{{ new Date(row.occurred_at).toLocaleString() }}</template>
      </DataTable>
      <BasePagination v-if="history.meta.value" :meta="history.meta.value" class="mt-4" @update:page="history.setPage" />
    </template>
  </div>
</template>
