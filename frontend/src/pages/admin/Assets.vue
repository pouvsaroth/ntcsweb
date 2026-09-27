<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetCategoriesService, type AssetCategory } from '@/services/assetCategories'
import { assetsService, assetStatuses, type Asset, type AssetStatus } from '@/services/assets'
import { useAdminUiStore } from '@/stores/adminUi'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const adminUi = useAdminUiStore()

const { items, meta, loading, error, perPage, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Asset>((query) =>
  assetsService.list(query),
)

const perPageOptions = [10, 25, 50, 100]

function statusKey(status: AssetStatus): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<AssetStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  IN_STOCK: 'neutral',
  ASSIGNED: 'primary',
  IN_USE: 'success',
  ISSUE_REPORTED: 'warning',
  UNDER_INSPECTION: 'warning',
  BROKEN: 'danger',
  UNDER_REPAIR: 'warning',
  REPAIR_COMPLETED: 'primary',
  READY_FOR_USE: 'success',
  STOPPED_USE: 'neutral',
  RETIRED: 'neutral',
  DISPOSED: 'danger',
  LOST: 'danger',
  MISSING: 'danger',
}

const selectedStatus = ref('')
const selectedCategory = ref('')
const categories = ref<AssetCategory[]>([])

const statusFilterOptions = computed(() => assetStatuses.map((status) => ({ value: status, label: t(`admin.assets.status${statusKey(status)}`) })))
const categoryFilterOptions = computed(() => categories.value.map((c) => ({ value: String(c.id), label: c.name })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

function onCategoryFilterChange(value: string) {
  selectedCategory.value = value
  setFilter('category_id', value || undefined)
}

const columns = [
  { key: 'asset_number', label: t('admin.assets.columnAssetNumber') },
  { key: 'name', label: t('admin.assets.columnName'), sortable: true },
  { key: 'category', label: t('admin.assets.columnCategory') },
  { key: 'status', label: t('admin.assets.columnStatus') },
  { key: 'condition', label: t('admin.assets.columnCondition') },
  { key: 'location', label: t('admin.assets.columnLocation') },
  { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
]

const actionError = ref<string | null>(null)

async function remove(asset: Asset) {
  if (!window.confirm(t('admin.assets.deleteConfirm'))) return

  actionError.value = null
  try {
    await assetsService.remove(asset.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.assets.deleteFailed')
  }
}

onMounted(async () => {
  categories.value = await assetCategoriesService.listAll()
  await fetch()
})
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.assets.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.assets.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
      <BaseSelect
        :model-value="selectedCategory"
        :options="categoryFilterOptions"
        :placeholder="t('admin.assets.filterAllCategories')"
        @update:model-value="onCategoryFilterChange"
      />
      <BaseButton to="/admin/assets/new">{{ t('admin.assets.addAsset') }}</BaseButton>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.assets.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-asset_number="{ row }">
        <RouterLink :to="`/admin/assets/${row.id}`" class="font-medium text-primary-700 hover:underline">{{ row.asset_number }}</RouterLink>
      </template>
      <template #cell-category="{ row }">{{ row.category?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.assets.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
      <template #cell-condition="{ row }">{{ t(`admin.assets.condition${row.condition.charAt(0)}${row.condition.slice(1).toLowerCase()}`) }}</template>
      <template #cell-location="{ row }">{{ row.location?.name ?? '—' }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton :to="`/admin/assets/${row.id}/edit`" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.assets.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <!-- The per-page selector and pager stick together as one bar — sticky
         only on the BasePagination inside would leave this selector behind
         when the pager pins to the bottom. `fixed`, not `sticky` — see
         BasePagination's `sticky` prop doc for why. -->
    <div
      v-if="meta"
      class="fixed inset-x-0 bottom-0 z-10 mt-4 flex flex-col items-center gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:flex-row sm:justify-between sm:px-6"
      :class="adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64'"
    >
      <label class="flex items-center gap-2 text-sm text-neutral-500">
        {{ t('admin.assets.perPage') }}
        <select
          v-model.number="perPage"
          class="rounded-lg border border-neutral-300 py-1.5 pl-2 pr-7 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        >
          <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
        </select>
      </label>

      <BasePagination :meta="meta" @update:page="setPage" />
    </div>
  </div>
</template>
