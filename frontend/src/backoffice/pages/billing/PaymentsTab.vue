<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

import { paymentsApi, type SchoolPaymentRow } from '../../api'
import ReasonModal from '../../components/ReasonModal.vue'
import { useSession } from '../../session'

/** Every payment schools made to the platform: filter, download a receipt, void a mistake. */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.billing.manage'))

const { items, meta, loading, error, search, setSearch, setPage, setSort, setFilter, sort, fetch } = usePaginatedResource<SchoolPaymentRow>((query) =>
  paymentsApi.list(query),
)

const method = ref('')
const from = ref('')
const to = ref('')
const methodOptions = computed(() => [
  { value: '', label: t('bo.billing.payments.allMethods') },
  ...(['cash', 'bank', 'khqr', 'cheque', 'other'] as const).map((m) => ({ value: m, label: t(`bo.billing.payments.methods.${m}`) })),
])

const filters = { method, from, to }

function filterBy(key: keyof typeof filters, value: string): void {
  filters[key].value = value
  setFilter(key, value || undefined)
}

const columns = computed(() => [
  { key: 'receipt_number', label: t('bo.billing.payments.receipt'), sortable: true },
  { key: 'school', label: t('bo.billing.payments.school') },
  { key: 'paid_on', label: t('bo.billing.payments.date'), sortable: true },
  { key: 'amount', label: t('bo.billing.payments.amount'), sortable: true },
  { key: 'method', label: t('bo.billing.payments.method') },
  { key: 'actions', label: '' },
])

const money = (p: SchoolPaymentRow) => formatMoney(Number(p.amount), p.currency)

const voiding = ref<SchoolPaymentRow | null>(null)
const voidBusy = ref(false)
const voidError = ref<string | undefined>(undefined)

async function confirmVoid(reason: string): Promise<void> {
  if (!voiding.value) return
  voidBusy.value = true
  voidError.value = undefined
  try {
    await paymentsApi.void(voiding.value.id, reason)
    voiding.value = null
    await fetch()
  } catch (e) {
    voidError.value = e instanceof ApiRequestError ? (e.fieldError('reason') ?? e.message) : t('bo.common.failed')
  } finally {
    voidBusy.value = false
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseInput :model-value="search" :placeholder="t('bo.billing.payments.search')" @update:model-value="setSearch" />
      <BaseSelect :model-value="method" :options="methodOptions" @update:model-value="filterBy('method', $event)" />
      <BaseInput :model-value="from" type="date" :label="t('bo.billing.payments.from')" @update:model-value="filterBy('from', $event)" />
      <BaseInput :model-value="to" type="date" :label="t('bo.billing.payments.to')" @update:model-value="filterBy('to', $event)" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.billing.payments.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div
          v-for="row in items"
          :key="row.id"
          class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]"
          :class="{ 'opacity-60': row.void }"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-mono text-xs text-neutral-500">{{ row.receipt_number }}</p>
              <p class="truncate font-medium text-neutral-900">{{ row.school?.name }}</p>
            </div>
            <p class="font-semibold whitespace-nowrap text-neutral-900" :class="{ 'line-through': row.void }">{{ money(row) }}</p>
          </div>
          <p class="mt-1 text-xs text-neutral-500">
            {{ formatDate(row.paid_on) }} · {{ t(`bo.billing.payments.methods.${row.method}`) }}<template v-if="row.reference"> · {{ row.reference }}</template>
          </p>
          <BaseBadge v-if="row.void" variant="danger" class="mt-1">{{ t('bo.billing.payments.voided') }}</BaseBadge>
          <div class="mt-2 flex gap-3 text-sm">
            <a :href="paymentsApi.receiptUrl(row.id)" class="text-secondary-700 hover:underline">{{ t('bo.billing.payments.download') }}</a>
            <button v-if="canManage && !row.void" type="button" class="text-red-600 hover:underline" @click="voiding = row">{{ t('bo.billing.payments.void') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable
        :columns="columns"
        :rows="items"
        row-key="id"
        :loading="loading"
        :sort="sort"
        :empty-message="t('bo.billing.payments.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-receipt_number="{ row }">
          <span class="font-mono text-xs" :class="{ 'line-through': row.void }">{{ row.receipt_number }}</span>
          <BaseBadge v-if="row.void" variant="danger" class="ml-1">{{ t('bo.billing.payments.voided') }}</BaseBadge>
        </template>
        <template #cell-school="{ row }">
          <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="text-secondary-700 hover:underline">{{ row.school.name }}</RouterLink>
          <p class="text-xs text-neutral-500">{{ row.plan }}</p>
        </template>
        <template #cell-paid_on="{ row }">
          {{ formatDate(row.paid_on) }}
          <p v-if="row.covered_from && row.covered_to" class="text-xs text-neutral-500">
            {{ t('bo.billing.payments.covers', { from: formatDate(row.covered_from), to: formatDate(row.covered_to) }) }}
          </p>
        </template>
        <template #cell-amount="{ row }">
          <span class="font-medium" :class="{ 'line-through': row.void }">{{ money(row) }}</span>
        </template>
        <template #cell-method="{ row }">
          {{ t(`bo.billing.payments.methods.${row.method}`) }}
          <p v-if="row.reference" class="text-xs text-neutral-500">{{ row.reference }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-3 text-sm whitespace-nowrap">
            <a :href="paymentsApi.receiptUrl(row.id)" class="text-secondary-700 hover:underline">{{ t('bo.billing.payments.receipt') }}</a>
            <button v-if="canManage && !row.void" type="button" class="text-red-600 hover:underline" @click="voiding = row">{{ t('bo.billing.payments.void') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <ReasonModal
      :model-value="voiding !== null"
      :title="t('bo.billing.payments.voidTitle')"
      :hint="t('bo.billing.payments.voidHint')"
      :confirm-label="t('bo.billing.payments.void')"
      :busy="voidBusy"
      :error="voidError"
      @update:model-value="voiding = null"
      @confirm="confirmVoid"
    />
  </div>
</template>
