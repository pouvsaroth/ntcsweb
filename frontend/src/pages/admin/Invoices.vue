<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  invoiceStatuses,
  invoicesService,
  paymentTypes,
  type Invoice,
  type InvoiceStatusValue,
  type PaymentTypeValue,
} from '@/services/invoices'
import { moneyOrDash } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const { t } = useI18n()
const auth = useAuthStore()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter } = usePaginatedResource<Invoice>((query) =>
  invoicesService.list(query),
)

// Defaults to Issued + Partially Paid (shown to staff as "unpaid"/"partially
// paid" — see the km translation) rather than every invoice, so the list
// opens on what actually needs action instead of burying it under
// settled/cancelled ones.
const selectedStatuses = ref<string[]>(['ISSUED', 'PARTIALLY_PAID'])

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
  STOPPED: 'neutral',
}

const statusFilterOptions = computed(() => invoiceStatuses.map((status) => ({ value: status, label: t(`admin.invoices.status${statusKey(status)}`) })))

function onStatusFilterChange(values: string[]) {
  selectedStatuses.value = values
  setFilter('status', values.length > 0 ? values.join(',') : undefined)
}

function paymentTypeKey(type: PaymentTypeValue): string {
  return type
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const selectedPaymentType = ref('')

const paymentTypeFilterOptions = computed(() =>
  paymentTypes.map((type) => ({ value: type, label: t(`admin.invoices.paymentType${paymentTypeKey(type)}`) })),
)

function onPaymentTypeFilterChange(value: string) {
  selectedPaymentType.value = value
  setFilter('payment_type', value || undefined)
}

const columns = [
  { key: 'actions', label: t('admin.invoices.columnActions') },
  { key: 'invoice_number', label: t('admin.invoices.columnNumber'), sortable: true },
  { key: 'student', label: t('admin.invoices.columnStudent') },
  { key: 'course', label: t('admin.invoices.monthlyColumnCourse') },
  { key: 'payment_type', label: t('admin.invoices.columnPaymentType') },
  // Each amount in USD and KHR, at the rate on the invoice date.
  { key: 'total', label: `${t('admin.invoices.columnTotal')} (USD)`, sortable: true, align: 'text-right' },
  { key: 'total_khr', label: `${t('admin.invoices.columnTotal')} (KHR)`, align: 'text-right' },
  { key: 'balance', label: `${t('admin.invoices.columnBalance')} (USD)`, sortable: true, align: 'text-right' },
  { key: 'balance_khr', label: `${t('admin.invoices.columnBalance')} (KHR)`, align: 'text-right' },
  { key: 'status', label: t('admin.invoices.columnStatus') },
  { key: 'due_date', label: t('admin.invoices.columnDueDate'), sortable: true },
  { key: 'created_at', label: t('admin.invoices.columnCreatedAt'), sortable: true },
]

onMounted(() => setFilter('status', selectedStatuses.value.length > 0 ? selectedStatuses.value.join(',') : undefined))
</script>

<template>
  <div>
    <InvoicesTabs />

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.invoices.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseMultiSelect
        :model-value="selectedStatuses"
        :options="statusFilterOptions"
        :placeholder="t('admin.invoices.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
      <BaseSelect
        :model-value="selectedPaymentType"
        :options="paymentTypeFilterOptions"
        :placeholder="t('admin.invoices.filterAllPaymentTypes')"
        @update:model-value="onPaymentTypeFilterChange"
      />
      <BaseButton v-if="auth.can('invoices.create')" variant="outline" to="/admin/invoices/company-class">{{ t('admin.invoices.companyInvoiceTitle') }}</BaseButton>
      <BaseButton v-if="auth.can('invoices.create')" to="/admin/invoices/new">{{ t('admin.invoices.addInvoice') }}</BaseButton>
    </div>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.invoices.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <RouterLink
          v-for="row in items"
          :key="row.id"
          :to="`/admin/invoices/${row.id}`"
          class="block rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.student?.name ?? row.company?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ [row.invoice_number, row.course, formatDate(row.due_date)].filter(Boolean).join(' · ') }}</p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.invoices.status${statusKey(row.status)}`) }}</BaseBadge>
          </div>
          <table class="mt-2 w-full text-sm">
            <thead class="text-xs text-neutral-500">
              <tr><th></th><th class="text-right font-medium">USD</th><th class="text-right font-medium">KHR</th></tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-neutral-500">{{ t('admin.invoices.columnTotal') }}</td>
                <td class="whitespace-nowrap text-right text-neutral-800">{{ moneyOrDash(row.total_usd, 'USD') }}</td>
                <td class="whitespace-nowrap pl-2 text-right text-neutral-800">{{ moneyOrDash(row.total_khr, 'KHR') }}</td>
              </tr>
              <tr>
                <td class="text-neutral-500">{{ t('admin.invoices.columnBalance') }}</td>
                <td class="whitespace-nowrap text-right font-semibold text-neutral-900">{{ moneyOrDash(row.balance_usd, 'USD') }}</td>
                <td class="whitespace-nowrap pl-2 text-right font-semibold text-neutral-900">{{ moneyOrDash(row.balance_khr, 'KHR') }}</td>
              </tr>
            </tbody>
          </table>
        </RouterLink>
      </div>
    </div>

    <DataTable
      class="hidden sm:block"
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.invoices.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-student="{ row }">{{ row.student?.name ?? row.company?.name ?? '—' }}</template>
      <template #cell-course="{ row }">{{ row.course ?? '—' }}</template>
      <template #cell-payment_type="{ row }">
        {{ row.payment_type ? t(`admin.invoices.paymentType${paymentTypeKey(row.payment_type)}`) : '—' }}
      </template>
      <template #cell-total="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.total_usd, 'USD') }}</span></template>
      <template #cell-total_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.total_khr, 'KHR') }}</span></template>
      <template #cell-balance="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.balance_usd, 'USD') }}</span></template>
      <template #cell-balance_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.balance_khr, 'KHR') }}</span></template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.invoices.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
      <template #cell-due_date="{ row }">{{ formatDate(row.due_date) }}</template>
      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      <template #cell-actions="{ row }">
        <RouterLink :to="`/admin/invoices/${row.id}`" class="text-sm font-medium text-primary-700 hover:text-primary-800">
          {{ t('admin.invoices.view') }}
        </RouterLink>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
