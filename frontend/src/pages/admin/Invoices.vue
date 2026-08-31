<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { invoiceStatuses, invoicesService, type Invoice, type InvoiceStatusValue } from '@/services/invoices'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Invoice>((query) =>
  invoicesService.list(query),
)

const selectedStatus = ref('')

function statusKey(status: InvoiceStatusValue): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<InvoiceStatusValue, 'success' | 'warning' | 'danger' | 'neutral' | 'primary'> = {
  DRAFT: 'neutral',
  ISSUED: 'primary',
  PARTIALLY_PAID: 'warning',
  PAID: 'success',
  OVERDUE: 'danger',
  CANCELLED: 'neutral',
  VOID: 'neutral',
}

const statusFilterOptions = computed(() => invoiceStatuses.map((status) => ({ value: status, label: t(`admin.invoices.status${statusKey(status)}`) })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const columns = [
  { key: 'invoice_number', label: t('admin.invoices.columnNumber'), sortable: true },
  { key: 'student', label: t('admin.invoices.columnStudent') },
  { key: 'total', label: t('admin.invoices.columnTotal'), sortable: true, align: 'text-right' },
  { key: 'balance', label: t('admin.invoices.columnBalance'), sortable: true, align: 'text-right' },
  { key: 'status', label: t('admin.invoices.columnStatus') },
  { key: 'due_date', label: t('admin.invoices.columnDueDate'), sortable: true },
  { key: 'created_at', label: t('admin.invoices.columnCreatedAt'), sortable: true },
  { key: 'actions', label: t('admin.invoices.columnActions'), align: 'text-right' },
]

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.invoices.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.invoices.pageSubtitle') }}</p>
      </div>
      <BaseButton to="/admin/invoices/new">{{ t('admin.invoices.addInvoice') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.invoices.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.invoices.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.invoices.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-student="{ row }">{{ row.student?.name ?? '—' }}</template>
      <template #cell-total="{ row }">${{ row.total.toFixed(2) }}</template>
      <template #cell-balance="{ row }">${{ row.balance.toFixed(2) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.invoices.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
      <template #cell-due_date="{ row }">{{ formatDate(row.due_date) }}</template>
      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end">
          <RouterLink :to="`/admin/invoices/${row.id}`" class="text-sm font-medium text-primary-700 hover:text-primary-800">
            {{ t('admin.invoices.view') }}
          </RouterLink>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
