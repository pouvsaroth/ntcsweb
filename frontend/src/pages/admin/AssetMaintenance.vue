<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetMaintenanceService, maintenanceStatuses, type AssetMaintenance, type MaintenanceStatus } from '@/services/assetMaintenance'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<AssetMaintenance>((query) =>
  assetMaintenanceService.list(query),
)

function statusKey(status: MaintenanceStatus): string {
  return status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
}

const selectedStatus = ref('')
const statusOptions = computed(() => maintenanceStatuses.map((s) => ({ value: s, label: t(`admin.assetMaintenance.maintenanceStatus${statusKey(s)}`) })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const actionError = ref<string | null>(null)

async function completeMaintenance(record: AssetMaintenance) {
  const cost = window.prompt(t('admin.assetMaintenance.completeCostPrompt'))
  if (cost === null) return
  actionError.value = null

  try {
    await assetMaintenanceService.complete(record.id, { cost: cost || undefined })
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.assetMaintenance.actionFailed')
  }
}

async function cancelMaintenance(record: AssetMaintenance) {
  if (!window.confirm(t('admin.assetMaintenance.cancelConfirm'))) return
  actionError.value = null

  try {
    await assetMaintenanceService.cancel(record.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.assetMaintenance.actionFailed')
  }
}

const columns = computed(() => [
  { key: 'maintenance_number', label: t('admin.assetMaintenance.columnNumber') },
  { key: 'asset', label: t('admin.assetMaintenance.columnAsset') },
  { key: 'maintenance_type', label: t('admin.assetMaintenance.maintenanceType') },
  { key: 'scheduled_date', label: t('admin.assetMaintenance.scheduledDate') },
  { key: 'status', label: t('admin.assetMaintenance.status') },
  { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
])

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetMaintenance.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetMaintenance.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('common.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="selectedStatus" :options="statusOptions" :placeholder="t('admin.assetMaintenance.filterAllStatuses')" @update:model-value="onStatusFilterChange" />
    </div>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.assetMaintenance.emptyMessage')">
      <template #cell-asset="{ row }">
        <RouterLink v-if="row.asset" :to="`/admin/assets/${row.asset.id}`" class="text-primary-700 hover:underline">{{ row.asset.asset_number }}</RouterLink>
        <span v-else>—</span>
      </template>
      <template #cell-scheduled_date="{ row }">
        {{ row.scheduled_date ? new Date(row.scheduled_date).toLocaleDateString() : '—' }}
        <BaseBadge v-if="row.is_overdue" variant="danger" class="ml-2">{{ t('admin.assetMaintenance.overdue') }}</BaseBadge>
      </template>
      <template #cell-status="{ row }">{{ t(`admin.assetMaintenance.maintenanceStatus${statusKey(row.status)}`) }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3">
          <button v-if="row.status === 'SCHEDULED'" type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="completeMaintenance(row)">
            {{ t('admin.assetMaintenance.complete') }}
          </button>
          <button v-if="row.status === 'SCHEDULED'" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="cancelMaintenance(row)">
            {{ t('common.cancel') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
