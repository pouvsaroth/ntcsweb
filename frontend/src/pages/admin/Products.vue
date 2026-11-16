<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProductFormModal from '@/components/admin/ProductFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { productsService, productTypes, type Product, type ProductType } from '@/services/products'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { moneyOrDash } from '@/utils/currency'

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

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
  // USD and KHR at today's rate.
  { key: 'price', label: `${t('admin.products.columnPrice')} (USD)`, sortable: true, align: 'text-right' },
  { key: 'price_khr', label: `${t('admin.products.columnPrice')} (KHR)`, align: 'text-right' },
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
  if (!(await confirmDialog.confirm({ message: t('admin.products.deleteConfirm'), danger: true }))) return
  await productsService.remove(product.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
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
      <BaseButton v-if="auth.can('products.create')" @click="openCreate">{{ t('admin.products.addProduct') }}</BaseButton>
    </div>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.products.emptyMessage') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="text-xs text-neutral-500">{{ row.code }} · {{ t(`admin.products.type${typeKey(row.type as ProductType)}`) }}</p>
            </div>
            <div class="shrink-0 text-right font-semibold tabular-nums text-neutral-900">{{ moneyOrDash(row.price_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.price_khr, 'KHR') }}</span></div>
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.products.statusActive') : t('admin.products.statusInactive') }}</BaseBadge>
            <div class="flex items-center gap-2">
              <EditIconButton v-if="auth.can('products.update')" @click="openEdit(row)" />
              <button v-if="auth.can('products.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.products.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable
      class="hidden sm:block"
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.products.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-type="{ row }">{{ t(`admin.products.type${typeKey(row.type as ProductType)}`) }}</template>
      <template #cell-price="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.price_usd, 'USD') }}</span></template>
      <template #cell-price_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.price_khr, 'KHR') }}</span></template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.products.statusActive') : t('admin.products.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="auth.can('products.update')" @click="openEdit(row)" />
          <button v-if="auth.can('products.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.products.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ProductFormModal v-model="modalOpen" :product="editingProduct" @saved="fetch" />
  </div>
</template>
