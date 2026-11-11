<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

import { quotationsApi, type Quotation, type QuotationStatus } from '../../api'
import { useSession } from '../../session'

/** Billing > Quotations: every quotation, newest first; open, delete, or start a new one. */
const { t } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const { items, meta, loading, error, search, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Quotation>((query) => quotationsApi.list(query))

const status = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('bo.billing.quotes.allStatuses') },
  ...(['draft', 'sent', 'accepted', 'rejected'] as const).map((s) => ({ value: s, label: t(`bo.billing.quotes.statuses.${s}`) })),
])
function onStatus(value: string): void {
  status.value = value
  setFilter('status', value || undefined)
}

const statusVariant: Record<QuotationStatus, 'neutral' | 'primary' | 'success' | 'danger'> = { draft: 'neutral', sent: 'primary', accepted: 'success', rejected: 'danger' }

const columns = computed(() => [
  { key: 'number', label: t('bo.billing.quotes.number') },
  { key: 'customer_name', label: t('bo.billing.quotes.customer') },
  { key: 'issue_date', label: t('bo.billing.quotes.date') },
  { key: 'total', label: t('bo.billing.quotes.total'), align: 'text-right' },
  { key: 'status', label: t('bo.billing.quotes.status') },
  { key: 'actions', label: '', align: 'text-right' },
])

const actionError = ref<string | null>(null)
async function remove(q: Quotation): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.billing.quotes.deleteConfirm', { number: q.number }), danger: true }))) return
  try {
    await quotationsApi.remove(q.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(fetch)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
      <div class="min-w-0 flex-1"><BaseInput :model-value="search" :placeholder="t('bo.billing.quotes.search')" @update:model-value="setSearch" /></div>
      <div class="w-full sm:w-48"><BaseSelect :model-value="status" :options="statusOptions" @update:model-value="onStatus" /></div>
      <BaseButton v-if="canManage" to="/billing/quotations/new">+ {{ t('bo.billing.quotes.newQuote') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading && !items.length" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.billing.quotes.empty') }}
      </p>
      <div v-else class="space-y-2">
        <RouterLink
          v-for="q in items"
          :key="q.id"
          :to="`/billing/quotations/${q.id}`"
          class="block rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-mono text-xs text-neutral-500">{{ q.number }}</p>
              <p class="truncate font-medium text-neutral-900">{{ q.customer_name }}</p>
            </div>
            <BaseBadge :variant="statusVariant[q.status]">{{ t(`bo.billing.quotes.statuses.${q.status}`) }}</BaseBadge>
          </div>
          <div class="mt-1 flex justify-between text-sm">
            <span class="text-neutral-500">{{ formatDate(q.issue_date) }}</span>
            <span class="font-semibold tabular-nums text-neutral-900">{{ formatMoney(q.total, q.currency) }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading && !items.length" :empty-message="t('bo.billing.quotes.empty')">
        <template #cell-number="{ row }">
          <RouterLink :to="`/billing/quotations/${row.id}`" class="font-mono text-sm text-primary-700 hover:underline">{{ row.number }}</RouterLink>
        </template>
        <template #cell-customer_name="{ row }">
          <span class="text-neutral-900">{{ row.customer_name }}</span>
          <p v-if="row.phone || row.email" class="text-xs text-neutral-500">{{ [row.phone, row.email].filter(Boolean).join(' · ') }}</p>
        </template>
        <template #cell-issue_date="{ row }">
          <span class="whitespace-nowrap">{{ formatDate(row.issue_date) }}</span>
          <p v-if="row.valid_until" class="text-xs text-neutral-500">→ {{ formatDate(row.valid_until) }}</p>
        </template>
        <template #cell-total="{ row }">
          <span class="font-medium tabular-nums">{{ formatMoney(row.total as number, row.currency as 'USD' | 'KHR') }}</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status as QuotationStatus]">{{ t(`bo.billing.quotes.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <BaseButton size="sm" variant="ghost" :to="`/billing/quotations/${row.id}`">{{ t('bo.billing.quotes.open') }}</BaseButton>
            <BaseButton v-if="canManage" size="sm" variant="ghost" @click="remove(row as Quotation)">{{ t('bo.billing.quotes.delete') }}</BaseButton>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>
