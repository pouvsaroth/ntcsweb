<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetCategoriesService, type AssetCategory } from '@/services/assetCategories'
import { assetsService, assetStatuses, type Asset, type AssetStatus } from '@/services/assets'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Asset>((query) =>
  assetsService.list(query),
)

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
]

onMounted(async () => {
  categories.value = await assetCategoriesService.listAll()
  await fetch()
})
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assets.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assets.pageSubtitle') }}</p>
      </div>
      <BaseButton to="/admin/assets/new">{{ t('admin.assets.addAsset') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

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
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
