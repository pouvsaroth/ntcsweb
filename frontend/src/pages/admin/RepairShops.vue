<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import RepairShopFormModal from '@/components/admin/RepairShopFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { repairShopsService, type RepairShop } from '@/services/repairShops'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<RepairShop>((query) => repairShopsService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.repairShops.columnName') },
  { key: 'specialization', label: t('admin.repairShops.columnSpecialization') },
  { key: 'phone', label: t('admin.repairShops.columnPhone') },
  { key: 'is_active', label: t('admin.repairShops.columnStatus') },
  { key: 'actions', label: t('admin.repairShops.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingShop = ref<RepairShop | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingShop.value = null
  modalOpen.value = true
}

function openEdit(shop: RepairShop) {
  editingShop.value = shop
  modalOpen.value = true
}

async function remove(shop: RepairShop) {
  if (!window.confirm(t('admin.repairShops.deleteConfirm'))) return
  deleteError.value = null

  try {
    await repairShopsService.remove(shop.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.repairShops.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.repairShops.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.repairShops.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.repairShops.addRepairShop') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.repairShops.emptyMessage')">
      <template #cell-specialization="{ row }">{{ row.specialization ?? '—' }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.repairShops.statusActive') : t('admin.repairShops.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.repairShops.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <RepairShopFormModal v-model="modalOpen" :repair-shop="editingShop" @saved="fetch" />
  </div>
</template>
