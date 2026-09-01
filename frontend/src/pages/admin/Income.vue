<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import IncomeFormModal from '@/components/admin/IncomeFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { incomeService } from '@/services/financialTransactions'
import type { FinancialTransaction } from '@/services/financialTransactions'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<FinancialTransaction>((query) => incomeService.list(query))

const columns = [
  { key: 'transaction_date', label: t('admin.income.columnDate') },
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
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.income.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.income.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="modalOpen = true">{{ t('admin.income.addIncome') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.income.emptyMessage')">
      <template #cell-credit_account="{ row }">{{ row.credit_account.code }} — {{ row.credit_account.name }}</template>
      <template #cell-description="{ row }">{{ row.description ?? '—' }}</template>
      <template #cell-amount="{ row }">${{ row.amount.toFixed(2) }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <IncomeFormModal v-model="modalOpen" @saved="fetch" />
  </div>
</template>
