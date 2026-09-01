<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AdjustmentFormModal from '@/components/admin/AdjustmentFormModal.vue'
import TransferFormModal from '@/components/admin/TransferFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { financialTransactionsService, transactionTypes, type FinancialTransaction, type TransactionType } from '@/services/financialTransactions'

const { t } = useI18n()

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
}

const columns = [
  { key: 'transaction_date', label: t('admin.transactions.columnDate') },
  { key: 'transaction_number', label: t('admin.transactions.columnNumber') },
  { key: 'type', label: t('admin.transactions.columnType') },
  { key: 'debit_account', label: t('admin.transactions.columnDebit') },
  { key: 'credit_account', label: t('admin.transactions.columnCredit') },
  { key: 'amount', label: t('admin.transactions.columnAmount'), align: 'text-right' },
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
        <BaseButton variant="outline" @click="adjustmentModalOpen = true">{{ t('admin.transactions.newAdjustment') }}</BaseButton>
        <BaseButton @click="transferModalOpen = true">{{ t('admin.transactions.newTransfer') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.transactions.emptyMessage')">
      <template #cell-type="{ row }">
        <BaseBadge :variant="typeVariant[row.type as TransactionType]">{{ t(`admin.transactions.type${typeKey(row.type as TransactionType)}`) }}</BaseBadge>
      </template>
      <template #cell-debit_account="{ row }">{{ row.debit_account.code }} — {{ row.debit_account.name }}</template>
      <template #cell-credit_account="{ row }">{{ row.credit_account.code }} — {{ row.credit_account.name }}</template>
      <template #cell-amount="{ row }">${{ row.amount.toFixed(2) }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <TransferFormModal v-model="transferModalOpen" @saved="fetch" />
    <AdjustmentFormModal v-model="adjustmentModalOpen" @saved="fetch" />
  </div>
</template>
