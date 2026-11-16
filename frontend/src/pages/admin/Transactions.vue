<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AdjustmentFormModal from '@/components/admin/AdjustmentFormModal.vue'
import TransferFormModal from '@/components/admin/TransferFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { financialTransactionsService, transactionTypes, type FinancialTransaction, type TransactionType } from '@/services/financialTransactions'
import { formatDate } from '@/utils/date'
import { moneyOrDash } from '@/utils/currency'

const { t } = useI18n()
const auth = useAuthStore()

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<FinancialTransaction>((query) =>
  financialTransactionsService.list(query),
)

const selectedType = ref('')

function typeKey(type: TransactionType): string {
  return type.charAt(0) + type.slice(1).toLowerCase()
}

const typeFilterOptions = computed(() => transactionTypes.map((type) => ({ value: type, label: t(`admin.transactions.type${typeKey(type)}`) })))

function onTypeFilterChange(value: string) {
  selectedType.value = value
  setFilter('type', value || undefined)
}

const typeVariant: Record<TransactionType, 'success' | 'danger' | 'primary' | 'warning' | 'neutral'> = {
  INCOME: 'success',
  EXPENSE: 'danger',
  TRANSFER: 'primary',
  REFUND: 'warning',
  ADJUSTMENT: 'neutral',
  LOAN: 'warning',
  SAVINGS: 'primary',
  LENDING: 'warning',
  FUND: 'primary',
}

const columns = [
  { key: 'transaction_date', label: t('admin.transactions.columnDate') },
  { key: 'transaction_number', label: t('admin.transactions.columnNumber') },
  { key: 'type', label: t('admin.transactions.columnType') },
  { key: 'debit_account', label: t('admin.transactions.columnDebit') },
  { key: 'credit_account', label: t('admin.transactions.columnCredit') },
  // USD and KHR, at the rate on the transaction date.
  { key: 'amount', label: `${t('admin.transactions.columnAmount')} (USD)`, align: 'text-right' },
  { key: 'amount_khr', label: `${t('admin.transactions.columnAmount')} (KHR)`, align: 'text-right' },
]

const transferModalOpen = ref(false)
const adjustmentModalOpen = ref(false)

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.transactions.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.transactions.pageSubtitle') }}</p>
      </div>
      <div class="flex gap-2">
        <BaseButton v-if="auth.can('accounting.adjustment.create')" variant="outline" @click="adjustmentModalOpen = true">{{ t('admin.transactions.newAdjustment') }}</BaseButton>
        <BaseButton v-if="auth.can('transactions.create')" @click="transferModalOpen = true">{{ t('admin.transactions.newTransfer') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseSelect
        :model-value="selectedType"
        :options="typeFilterOptions"
        :placeholder="t('admin.transactions.filterAllTypes')"
        @update:model-value="onTypeFilterChange"
      />
    </div>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.transactions.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.transaction_number }}</p>
              <p class="text-xs text-neutral-500">{{ formatDate(row.transaction_date) }}</p>
            </div>
            <BaseBadge :variant="typeVariant[row.type as TransactionType]">{{ t(`admin.transactions.type${typeKey(row.type as TransactionType)}`) }}</BaseBadge>
          </div>
          <p class="mt-2 truncate text-xs text-neutral-600">{{ t('admin.transactions.columnDebit') }}: {{ row.debit_account.code }} — {{ row.debit_account.name }}</p>
          <p class="truncate text-xs text-neutral-600">{{ t('admin.transactions.columnCredit') }}: {{ row.credit_account.code }} — {{ row.credit_account.name }}</p>
          <div class="mt-2 flex justify-between text-sm font-semibold text-neutral-900">
            <span>{{ moneyOrDash(row.amount_usd, 'USD') }}</span>
            <span>{{ moneyOrDash(row.amount_khr, 'KHR') }}</span>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.transactions.emptyMessage')">
      <template #cell-transaction_date="{ row }">{{ formatDate(row.transaction_date) }}</template>
      <template #cell-type="{ row }">
        <BaseBadge :variant="typeVariant[row.type as TransactionType]">{{ t(`admin.transactions.type${typeKey(row.type as TransactionType)}`) }}</BaseBadge>
      </template>
      <template #cell-debit_account="{ row }">{{ row.debit_account.code }} — {{ row.debit_account.name }}</template>
      <template #cell-credit_account="{ row }">{{ row.credit_account.code }} — {{ row.credit_account.name }}</template>
      <template #cell-amount="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.amount_usd, 'USD') }}</span></template>
      <template #cell-amount_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.amount_khr, 'KHR') }}</span></template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <TransferFormModal v-model="transferModalOpen" @saved="fetch" />
    <AdjustmentFormModal v-model="adjustmentModalOpen" @saved="fetch" />
  </div>
</template>
