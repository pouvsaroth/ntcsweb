<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CompleteAssetRepairModal from '@/components/admin/CompleteAssetRepairModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetRepairsService, repairStatuses, type AssetRepair, type RepairStatus } from '@/services/assetRepairs'
import { ApiRequestError } from '@/types/api'

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
  { key: 'total_cost', label: t('admin.assetRepairs.totalCost'), align: 'text-right' },
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.assetRepairs.emptyMessage')">
      <template #cell-asset="{ row }">
        <RouterLink v-if="row.asset" :to="`/admin/assets/${row.asset.id}`" class="text-primary-700 hover:underline">{{ row.asset.asset_number }}</RouterLink>
        <span v-else>—</span>
      </template>
      <template #cell-repair_shop="{ row }">{{ row.repair_shop?.name ?? '—' }}</template>
      <template #cell-status="{ row }">{{ t(`admin.assetRepairs.repairStatus${statusKey(row.status)}`) }}</template>
      <template #cell-total_cost="{ row }">${{ row.total_cost.toFixed(2) }}</template>
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
      :submitting="completeSubmitting"
      :error="completeError"
      @update:model-value="completingRepair = null"
      @confirm="confirmComplete"
    />
  </div>
</template>
