<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LookupCategoryFormModal from '@/components/admin/LookupCategoryFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lookupCategoriesService, type LookupCategory } from '@/services/lookupCategories'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<LookupCategory>((query) =>
  lookupCategoriesService.list(query),
)

const columns = [
  { key: 'code', label: t('admin.lookupCategories.columnCode') },
  { key: 'name', label: t('admin.lookupCategories.columnName') },
  { key: 'values_count', label: t('admin.lookupCategories.columnValues') },
  { key: 'is_active', label: t('admin.lookupCategories.columnStatus') },
  { key: 'actions', label: t('admin.lookupCategories.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingCategory = ref<LookupCategory | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingCategory.value = null
  modalOpen.value = true
}

function openEdit(category: LookupCategory) {
  editingCategory.value = category
  modalOpen.value = true
}

async function remove(category: LookupCategory) {
  if (!window.confirm(t('admin.lookupCategories.deleteConfirm'))) return
  deleteError.value = null

  try {
    await lookupCategoriesService.remove(category.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.lookupCategories.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.lookupCategories.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.lookupCategories.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.lookupCategories.addCategory') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.lookupCategories.emptyMessage')">
      <template #cell-values_count="{ row }">{{ row.values_count ?? 0 }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.lookupCategories.statusActive') : t('admin.lookupCategories.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <BaseButton size="sm" variant="outline" :to="`/admin/lookup-categories/${row.id}/values`">
            {{ t('admin.lookupCategories.viewValues') }}
          </BaseButton>
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.lookupCategories.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <LookupCategoryFormModal v-model="modalOpen" :category="editingCategory" @saved="fetch" />
  </div>
</template>
