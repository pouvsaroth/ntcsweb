<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BuildingFormModal from '@/components/admin/BuildingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { buildingsService, type Building } from '@/services/buildings'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Building>((query) => buildingsService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.buildings.columnName') },
  { key: 'code', label: t('admin.buildings.columnCode') },
  { key: 'address', label: t('admin.buildings.columnAddress') },
  { key: 'classrooms_count', label: t('admin.buildings.columnClassrooms') },
  { key: 'status', label: t('admin.buildings.columnStatus') },
  { key: 'actions', label: t('admin.buildings.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingBuilding = ref<Building | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingBuilding.value = null
  modalOpen.value = true
}

function openEdit(building: Building) {
  editingBuilding.value = building
  modalOpen.value = true
}

async function remove(building: Building) {
  if (!window.confirm(t('admin.buildings.deleteConfirm'))) return
  deleteError.value = null

  try {
    await buildingsService.remove(building.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.buildings.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.buildings.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.buildings.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.buildings.addBuilding') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.buildings.emptyMessage')">
      <template #cell-code="{ row }">
        {{ row.code || '—' }}
      </template>
      <template #cell-address="{ row }">
        {{ row.address || '—' }}
      </template>
      <template #cell-classrooms_count="{ row }">
        {{ row.classrooms_count ?? 0 }}
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.buildings.statusActive') : t('admin.buildings.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.buildings.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BuildingFormModal v-model="modalOpen" :building="editingBuilding" @saved="fetch" />
  </div>
</template>
