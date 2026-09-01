<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetsService, type Asset, type AssetStatus } from '@/services/assets'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<Asset>((query) => assetsService.myAssets(query))

function statusKey(status: AssetStatus): string {
  return status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
}

const columns = computed(() => [
  { key: 'asset_number', label: t('admin.assets.columnAssetNumber') },
  { key: 'name', label: t('admin.assets.columnName') },
  { key: 'category', label: t('admin.assets.columnCategory') },
  { key: 'status', label: t('admin.assets.columnStatus') },
  { key: 'condition', label: t('admin.assets.columnCondition') },
])

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('adminNav.items.myAssets') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myAssets.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.myAssets.emptyMessage')">
      <template #cell-asset_number="{ row }">
        <RouterLink :to="`/admin/assets/${row.id}`" class="font-medium text-primary-700 hover:underline">{{ row.asset_number }}</RouterLink>
      </template>
      <template #cell-category="{ row }">{{ row.category?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge variant="primary">{{ t(`admin.assets.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
      <template #cell-condition="{ row }">{{ t(`admin.assets.condition${row.condition.charAt(0)}${row.condition.slice(1).toLowerCase()}`) }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
