<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import SupplierFormModal from '@/components/admin/SupplierFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { suppliersService, type Supplier } from '@/services/suppliers'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Supplier>((query) => suppliersService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.suppliers.columnName') },
  { key: 'contact_person', label: t('admin.suppliers.columnContact') },
  { key: 'phone', label: t('admin.suppliers.columnPhone') },
  { key: 'is_active', label: t('admin.suppliers.columnStatus') },
  { key: 'actions', label: t('admin.suppliers.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingSupplier = ref<Supplier | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingSupplier.value = null
  modalOpen.value = true
}

function openEdit(supplier: Supplier) {
  editingSupplier.value = supplier
  modalOpen.value = true
}

async function remove(supplier: Supplier) {
  if (!window.confirm(t('admin.suppliers.deleteConfirm'))) return
  deleteError.value = null

  try {
    await suppliersService.remove(supplier.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.suppliers.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.suppliers.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.suppliers.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.suppliers.addSupplier') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.suppliers.emptyMessage')">
      <template #cell-contact_person="{ row }">{{ row.contact_person ?? '—' }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.suppliers.statusActive') : t('admin.suppliers.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.suppliers.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <SupplierFormModal v-model="modalOpen" :supplier="editingSupplier" @saved="fetch" />
  </div>
</template>
