<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CurrencyRateFormModal from '@/components/admin/CurrencyRateFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { currencyRatesService, type CurrencyRate } from '@/services/currencyRates'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<CurrencyRate>((query) =>
  currencyRatesService.list(query),
)

const columns = computed(() => [
  { key: 'effective_date', label: t('admin.currencyRates.columnEffectiveDate') },
  { key: 'khr_per_usd', label: t('admin.currencyRates.columnRate') },
  { key: 'created_by', label: t('admin.currencyRates.columnCreatedBy') },
  { key: 'actions', label: t('admin.currencyRates.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingRate = ref<CurrencyRate | null>(null)

function openCreate() {
  editingRate.value = null
  modalOpen.value = true
}

function openEdit(rate: CurrencyRate) {
  editingRate.value = rate
  modalOpen.value = true
}

async function remove(rate: CurrencyRate) {
  if (!window.confirm(t('admin.currencyRates.deleteConfirm'))) return
  await currencyRatesService.remove(rate.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.currencyRates.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.currencyRates.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.currencyRates.addRate') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.currencyRates.emptyMessage')"
    >
      <template #cell-khr_per_usd="{ row }">{{ row.khr_per_usd.toLocaleString() }} ៛</template>
      <template #cell-created_by="{ row }">{{ row.created_by ?? '—' }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.currencyRates.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CurrencyRateFormModal v-model="modalOpen" :rate="editingRate" @saved="fetch" />
  </div>
</template>
