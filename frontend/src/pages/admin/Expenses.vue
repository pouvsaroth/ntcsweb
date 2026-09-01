<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { expensesService, expenseStatuses, type Expense, type ExpenseStatus } from '@/services/expenses'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Expense>(
  (query) => expensesService.list(query),
)

const selectedStatus = ref('')

function statusKey(status: ExpenseStatus): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<ExpenseStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  DRAFT: 'neutral',
  PENDING_APPROVAL: 'warning',
  APPROVED: 'primary',
  PAID: 'success',
  REJECTED: 'danger',
  CANCELLED: 'neutral',
}

const statusFilterOptions = computed(() => expenseStatuses.map((status) => ({ value: status, label: t(`admin.expenses.status${statusKey(status)}`) })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const columns = [
  { key: 'expense_date', label: t('admin.expenses.columnDate'), sortable: true },
  { key: 'expense_number', label: t('admin.expenses.columnNumber') },
  { key: 'account', label: t('admin.expenses.columnAccount') },
  { key: 'vendor', label: t('admin.expenses.columnVendor') },
  { key: 'amount', label: t('admin.expenses.columnAmount'), sortable: true, align: 'text-right' },
  { key: 'status', label: t('admin.expenses.columnStatus') },
]

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.expenses.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.expenses.pageSubtitle') }}</p>
      </div>
      <BaseButton to="/admin/expenses/new">{{ t('admin.expenses.addExpense') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.expenses.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.expenses.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.expenses.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-expense_number="{ row }">
        <RouterLink :to="`/admin/expenses/${row.id}`" class="font-medium text-primary-700 hover:underline">{{ row.expense_number }}</RouterLink>
      </template>
      <template #cell-account="{ row }">{{ row.account.code }} — {{ row.account.name }}</template>
      <template #cell-vendor="{ row }">{{ row.vendor ?? '—' }}</template>
      <template #cell-amount="{ row }">${{ row.amount.toFixed(2) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.expenses.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
