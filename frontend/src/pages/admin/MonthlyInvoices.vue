<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { monthlyInvoicesService, type MonthlyInvoice } from '@/services/monthlyInvoices'

const { t } = useI18n()

const { items, meta, loading, error, fetch, setPage, setSort, sort } = usePaginatedResource<MonthlyInvoice>((query) =>
  monthlyInvoicesService.list(query),
)

onMounted(fetch)

const columns = [
  { key: 'student', label: t('admin.invoices.columnStudent') },
  { key: 'course', label: t('admin.invoices.monthlyColumnCourse') },
  { key: 'class', label: t('admin.invoices.monthlyColumnClass') },
  { key: 'start_date', label: t('admin.invoices.monthlyColumnStartDate'), sortable: true },
  { key: 'end_date', label: t('admin.invoices.monthlyColumnEndDate') },
  { key: 'monthly_invoices_count', label: t('admin.invoices.monthlyColumnInvoicesSoFar'), align: 'text-right' },
  { key: 'next_payment_date', label: t('admin.invoices.monthlyColumnNextPayment') },
]

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}
</script>

<template>
  <div>
    <InvoicesTabs />

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.invoices.monthlyEmptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-student="{ row }">{{ row.student?.name ?? '—' }}</template>
      <template #cell-course="{ row }">{{ row.course ?? '—' }}</template>
      <template #cell-class="{ row }">{{ row.class ?? '—' }}</template>
      <template #cell-start_date="{ row }">{{ formatDate(row.start_date) }}</template>
      <template #cell-end_date="{ row }">{{ formatDate(row.end_date) }}</template>
      <template #cell-next_payment_date="{ row }">{{ formatDate(row.next_payment_date) }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
