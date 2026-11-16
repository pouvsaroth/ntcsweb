<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CompleteAssetRepairModal from '@/components/admin/CompleteAssetRepairModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetRepairsService, repairStatuses, type AssetRepair, type RepairStatus } from '@/services/assetRepairs'
import { ApiRequestError } from '@/types/api'
import { moneyOrDash } from '@/utils/currency'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<AssetRepair>((query) => assetRepairsService.list(query))

function statusKey(status: RepairStatus): string {
  return status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
}

const selectedStatus = ref('')
const statusOptions = computed(() => repairStatuses.map((s) => ({ value: s, label: t(`admin.assetRepairs.repairStatus${statusKey(s)}`) })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const actionError = ref<string | null>(null)
const completingRepair = ref<AssetRepair | null>(null)
const completeSubmitting = ref(false)
const completeError = ref<string | null>(null)

async function confirmComplete(payload: Parameters<typeof assetRepairsService.complete>[1]) {
  if (!completingRepair.value) return
  completeSubmitting.value = true
  completeError.value = null

  try {
    await assetRepairsService.complete(completingRepair.value.id, payload)
    completingRepair.value = null
    await fetch()
  } catch (e) {
    completeError.value = e instanceof ApiRequestError ? e.message : t('admin.assetRepairs.actionFailed')
  } finally {
    completeSubmitting.value = false
  }
}

async function cancelRepair(repair: AssetRepair) {
  const reason = window.prompt(t('admin.assetRepairs.cancelReasonPrompt'))
  if (!reason) return
  actionError.value = null

  try {
    await assetRepairsService.cancel(repair.id, reason)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.assetRepairs.actionFailed')
  }
}

const columns = computed(() => [
  { key: 'repair_number', label: t('admin.assetRepairs.columnNumber') },
  { key: 'asset', label: t('admin.assetRepairs.columnAsset') },
  { key: 'repair_shop', label: t('admin.assetRepairs.repairShop') },
  { key: 'status', label: t('admin.assetRepairs.status') },
  // USD and KHR at the rate on the day it was sent.
  { key: 'total_cost', label: `${t('admin.assetRepairs.totalCost')} (USD)`, align: 'text-right' },
  { key: 'total_cost_khr', label: `${t('admin.assetRepairs.totalCost')} (KHR)`, align: 'text-right' },
  { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
])

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetRepairs.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetRepairs.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('common.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="selectedStatus" :options="statusOptions" :placeholder="t('admin.assetRepairs.filterAllStatuses')" @update:model-value="onStatusFilterChange" />
    </div>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.assetRepairs.emptyMessage') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.repair_number }}</p>
              <p class="text-xs text-neutral-500">
                <RouterLink v-if="row.asset" :to="`/admin/assets/${row.asset.id}`" class="text-primary-700">{{ row.asset.asset_number }}</RouterLink>
                · {{ row.repair_shop?.name ?? '—' }}
              </p>
              <p class="text-xs text-neutral-500">{{ t(`admin.assetRepairs.repairStatus${statusKey(row.status)}`) }}</p>
            </div>
            <div class="shrink-0 text-right font-semibold tabular-nums text-neutral-900">{{ moneyOrDash(row.total_cost_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.total_cost_khr, 'KHR') }}</span></div>
          </div>
          <div class="mt-2 flex justify-end gap-3">
            <button v-if="!['REPAIR_COMPLETED', 'RETURNED', 'CANCELLED'].includes(row.status)" type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="completingRepair = row">{{ t('admin.assetRepairs.complete') }}</button>
            <button v-if="!['RETURNED', 'CANCELLED'].includes(row.status)" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="cancelRepair(row)">{{ t('common.cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.assetRepairs.emptyMessage')">
      <template #cell-asset="{ row }">
        <RouterLink v-if="row.asset" :to="`/admin/assets/${row.asset.id}`" class="text-primary-700 hover:underline">{{ row.asset.asset_number }}</RouterLink>
        <span v-else>—</span>
      </template>
      <template #cell-repair_shop="{ row }">{{ row.repair_shop?.name ?? '—' }}</template>
      <template #cell-status="{ row }">{{ t(`admin.assetRepairs.repairStatus${statusKey(row.status)}`) }}</template>
      <template #cell-total_cost="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.total_cost_usd, 'USD') }}</span></template>
      <template #cell-total_cost_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.total_cost_khr, 'KHR') }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3">
          <button
            v-if="!['REPAIR_COMPLETED', 'RETURNED', 'CANCELLED'].includes(row.status)"
            type="button"
            class="text-sm font-medium text-primary-700 hover:underline"
            @click="completingRepair = row"
          >
            {{ t('admin.assetRepairs.complete') }}
          </button>
          <button
            v-if="!['RETURNED', 'CANCELLED'].includes(row.status)"
            type="button"
            class="text-sm font-medium text-danger-600 hover:text-red-700"
            @click="cancelRepair(row)"
          >
            {{ t('common.cancel') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CompleteAssetRepairModal
      :model-value="completingRepair !== null"
      :currency="completingRepair?.currency"
      :submitting="completeSubmitting"
      :error="completeError"
      @update:model-value="completingRepair = null"
      @confirm="confirmComplete"
    />
  </div>
</template>
