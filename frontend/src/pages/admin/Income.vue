<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import IncomeFormModal from '@/components/admin/IncomeFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { incomeService } from '@/services/financialTransactions'
import type { FinancialTransaction } from '@/services/financialTransactions'
import { formatMoney } from '@/utils/currency'
import { formatDate, formatMonth } from '@/utils/date'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<FinancialTransaction>((query) => incomeService.list(query))

const money = (row: FinancialTransaction) => formatMoney(row.amount, row.currency === 'KHR' ? 'KHR' : 'USD')

// For month: the month the income belongs to, not the day it came in.
const selectedMonth = ref('')
function onMonthFilterChange(value: string) {
  selectedMonth.value = value
  setFilter('for_month', value || undefined)
}

const columns = [
  { key: 'transaction_date', label: t('admin.income.columnDate') },
  { key: 'for_month', label: t('admin.income.forMonth') },
  { key: 'transaction_number', label: t('admin.income.columnReference') },
  { key: 'credit_account', label: t('admin.income.columnAccount') },
  { key: 'description', label: t('admin.income.columnDescription') },
  { key: 'amount', label: t('admin.income.columnAmount'), align: 'text-right' },
]

const modalOpen = ref(false)

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.income.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.income.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="modalOpen = true">{{ t('admin.income.addIncome') }}</BaseButton>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseInput :model-value="selectedMonth" type="month" :label="t('admin.income.filterForMonth')" @update:model-value="onMonthFilterChange" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.income.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.transaction_number }}</p>
              <p class="text-xs text-neutral-500">{{ formatDate(row.transaction_date) }} · {{ t('admin.income.forMonth') }} {{ formatMonth(row.for_month) }}</p>
            </div>
            <p class="shrink-0 text-base font-semibold text-neutral-900">{{ money(row) }}</p>
          </div>
          <p class="mt-2 truncate text-sm text-neutral-700">{{ row.credit_account.code }} — {{ row.credit_account.name }}</p>
          <p v-if="row.description" class="mt-0.5 text-xs text-neutral-500">{{ row.description }}</p>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.income.emptyMessage')">
      <template #cell-transaction_date="{ row }">{{ formatDate(row.transaction_date) }}</template>
      <template #cell-for_month="{ row }">{{ formatMonth(row.for_month) }}</template>
      <template #cell-credit_account="{ row }">{{ row.credit_account.code }} — {{ row.credit_account.name }}</template>
      <!-- Long payment descriptions are cut short so Amount stays in view. -->
      <template #cell-description="{ row }"><span class="block max-w-xs truncate" :title="row.description ?? ''">{{ row.description ?? '—' }}</span></template>
      <template #cell-amount="{ row }"><span class="whitespace-nowrap">{{ money(row as FinancialTransaction) }}</span></template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <IncomeFormModal v-model="modalOpen" @saved="fetch" />
  </div>
</template>
