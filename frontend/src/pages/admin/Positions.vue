<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import PositionFormModal from '@/components/admin/PositionFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { positionsService, type Position } from '@/services/positions'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Position>((query) =>
  positionsService.list(query),
)

const columns = computed(() => [
  { key: 'name', label: t('admin.positions.columnName') },
  { key: 'role', label: t('admin.positions.columnRole') },
  { key: 'staff_count', label: t('admin.positions.columnStaffCount') },
  { key: 'status', label: t('admin.positions.columnStatus') },
  { key: 'actions', label: t('admin.positions.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingPosition = ref<Position | null>(null)

function openCreate() {
  editingPosition.value = null
  modalOpen.value = true
}

function openEdit(position: Position) {
  editingPosition.value = position
  modalOpen.value = true
}

async function remove(position: Position) {
  if (!window.confirm(t('admin.positions.deleteConfirm'))) return
  await positionsService.remove(position.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.positions.title') }}</h1>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.positions.addPosition') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.positions.emptyMessage')"
    >
      <template #cell-role="{ row }">{{ row.role?.name ?? '—' }}</template>
      <template #cell-staff_count="{ row }">{{ row.staff_count ?? 0 }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.positions.statusActive') : t('admin.positions.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.positions.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PositionFormModal v-model="modalOpen" :position="editingPosition" @saved="fetch" />
  </div>
</template>
