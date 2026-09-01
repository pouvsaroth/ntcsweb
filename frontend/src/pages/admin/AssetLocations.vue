<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AssetLocationFormModal from '@/components/admin/AssetLocationFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetLocationsService, type AssetLocation } from '@/services/assetLocations'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<AssetLocation>((query) =>
  assetLocationsService.list(query),
)

const columns = computed(() => [
  { key: 'code', label: t('admin.assetLocations.columnCode') },
  { key: 'name', label: t('admin.assetLocations.columnName') },
  { key: 'type', label: t('admin.assetLocations.columnType') },
  { key: 'parent', label: t('admin.assetLocations.columnParent') },
  { key: 'is_active', label: t('admin.assetLocations.columnStatus') },
  { key: 'actions', label: t('admin.assetLocations.columnActions'), align: 'text-right' },
])

function typeLabel(type: string): string {
  return t(`admin.assetLocations.type${type.charAt(0)}${type.slice(1).toLowerCase()}`)
}

const modalOpen = ref(false)
const editingLocation = ref<AssetLocation | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingLocation.value = null
  modalOpen.value = true
}

function openEdit(location: AssetLocation) {
  editingLocation.value = location
  modalOpen.value = true
}

async function remove(location: AssetLocation) {
  if (!window.confirm(t('admin.assetLocations.deleteConfirm'))) return
  deleteError.value = null

  try {
    await assetLocationsService.remove(location.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.assetLocations.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetLocations.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetLocations.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.assetLocations.addLocation') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.assetLocations.emptyMessage')"
    >
      <template #cell-type="{ row }">{{ typeLabel(row.type) }}</template>
      <template #cell-parent="{ row }">{{ row.parent?.name ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.assetLocations.statusActive') : t('admin.assetLocations.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.assetLocations.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AssetLocationFormModal v-model="modalOpen" :location="editingLocation" @saved="fetch" />
  </div>
</template>
