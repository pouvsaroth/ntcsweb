<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { invoicesService } from '@/services/invoices'
import { monthlyInvoicesService, type MonthlyInvoice } from '@/services/monthlyInvoices'
import { ApiRequestError } from '@/types/api'

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
  { key: 'actions', label: t('admin.invoices.columnActions'), align: 'text-right' },
]

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

const reprintError = ref<string | null>(null)
const reprintingId = ref<number | null>(null)

async function reprint(row: MonthlyInvoice) {
  if (row.latest_invoice_id === null || row.latest_invoice_number === null) return

  reprintError.value = null
  reprintingId.value = row.id

  try {
    await invoicesService.downloadPdf(row.latest_invoice_id, row.latest_invoice_number)
  } catch (e) {
    reprintError.value = e instanceof ApiRequestError ? e.message : t('admin.invoices.downloadFailed')
  } finally {
    reprintingId.value = null
  }
}
</script>

<template>
  <div>
    <InvoicesTabs />

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="reprintError" variant="danger" class="mb-4">{{ reprintError }}</BaseAlert>

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
      <template #cell-actions="{ row }">
        <div class="flex justify-end">
          <button
            type="button"
            class="text-sm font-medium text-primary-700 hover:text-primary-800 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="row.latest_invoice_id === null || reprintingId === row.id"
            @click="reprint(row)"
          >
            {{ reprintingId === row.id ? t('common.loading') : t('admin.invoices.monthlyReprintAction') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
