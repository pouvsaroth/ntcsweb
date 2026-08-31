<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProductFormModal from '@/components/admin/ProductFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { productsService, productTypes, type Product, type ProductType } from '@/services/products'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Product>(
  (query) => productsService.list(query),
)

const selectedType = ref('')

function typeKey(type: ProductType): string {
  return type
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const typeFilterOptions = computed(() => productTypes.map((type) => ({ value: type, label: t(`admin.products.type${typeKey(type)}`) })))

function onTypeFilterChange(value: string) {
  selectedType.value = value
  setFilter('type', value || undefined)
}

const columns = [
  { key: 'code', label: t('admin.products.columnCode'), sortable: true },
  { key: 'name', label: t('admin.products.columnName'), sortable: true },
  { key: 'type', label: t('admin.products.columnType') },
  { key: 'price', label: t('admin.products.columnPrice'), sortable: true, align: 'text-right' },
  { key: 'is_active', label: t('admin.products.columnStatus') },
  { key: 'actions', label: t('admin.products.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingProduct = ref<Product | null>(null)

function openCreate() {
  editingProduct.value = null
  modalOpen.value = true
}

function openEdit(product: Product) {
  editingProduct.value = product
  modalOpen.value = true
}

async function remove(product: Product) {
  if (!window.confirm(t('admin.products.deleteConfirm'))) return
  await productsService.remove(product.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.products.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.products.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.products.addProduct') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.products.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedType"
        :options="typeFilterOptions"
        :placeholder="t('admin.products.filterAllTypes')"
        @update:model-value="onTypeFilterChange"
      />
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.products.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-type="{ row }">{{ t(`admin.products.type${typeKey(row.type as ProductType)}`) }}</template>
      <template #cell-price="{ row }">${{ row.price.toFixed(2) }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.products.statusActive') : t('admin.products.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.products.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ProductFormModal v-model="modalOpen" :product="editingProduct" @saved="fetch" />
  </div>
</template>
