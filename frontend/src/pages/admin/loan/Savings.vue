<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import SavingsEntryModal from '@/components/admin/SavingsEntryModal.vue'
import SavingsOpenModal from '@/components/admin/SavingsOpenModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { savingsService, type SavingsAccount, type SavingsSummaryRow } from '@/services/savings'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Loan > Savings: every customer savings account, its balance, deposit / withdraw, and the statement.
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const canCreate = computed(() => auth.can('savings.create'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<SavingsAccount>((query) => savingsService.list(query))

const summary = ref<SavingsSummaryRow[]>([])
async function loadSummary() {
  summary.value = await savingsService.summary().catch(() => [])
}

const statusFilter = ref('open')
const statusOptions = computed(() => [
  { value: 'open', label: t('admin.savings.statuses.open') },
  { value: 'closed', label: t('admin.savings.statuses.closed') },
  { value: '', label: t('admin.savings.filterAll') },
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const currencyFilter = ref('')
const currencyOptions = computed(() => [
  { value: '', label: t('admin.savings.allCurrencies') },
  { value: 'USD', label: 'USD' },
  { value: 'KHR', label: 'KHR' },
])
function onCurrency(value: string) {
  currencyFilter.value = value
  setFilter('currency', value || undefined)
}

const columns = computed(() => [
  { key: 'account_no', label: t('admin.savings.accountNo') },
  { key: 'customer', label: t('admin.savings.customer') },
  { key: 'currency', label: t('admin.savings.currency') },
  { key: 'balance', label: t('admin.savings.balance'), align: 'text-right' },
  { key: 'last_entry_on', label: t('admin.savings.lastEntry') },
  { key: 'status', label: t('admin.savings.status') },
  { key: 'actions', label: '', align: 'text-right' },
])

const money = (row: SavingsAccount) => formatMoney(row.balance, row.currency)

const openModal = ref(false)
const entryModal = ref(false)
const entryType = ref<'deposit' | 'withdrawal'>('deposit')
const entryAccount = ref<SavingsAccount | null>(null)

function record(row: SavingsAccount, type: 'deposit' | 'withdrawal') {
  entryAccount.value = row
  entryType.value = type
  entryModal.value = true
}

function statement(row: SavingsAccount) {
  router.push(`/admin/savings/${row.id}`)
}

function refresh() {
  fetch()
  loadSummary()
}

function onOpened(account: SavingsAccount) {
  router.push(`/admin/savings/${account.id}`)
}

onMounted(() => {
  setFilter('status', 'open')
  loadSummary()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.savings.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.savings.subtitle') }}</p>
    </div>

    <div v-if="summary.length" class="mb-6 grid gap-3 sm:grid-cols-2">
      <div v-for="row in summary" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.savings.keptIn', { currency: row.currency }) }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ formatMoney(row.balance, row.currency) }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.savings.openAccounts', { n: row.accounts }) }}</p>
      </div>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('admin.savings.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-32" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseSelect class="w-40" :model-value="currencyFilter" :options="currencyOptions" @update:model-value="onCurrency" />
      <BaseButton v-if="canCreate" class="ml-auto shrink-0" @click="openModal = true">{{ t('admin.savings.open') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.savings.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button type="button" class="flex w-full items-start justify-between gap-2 text-left" @click="statement(row)">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.customer?.name ?? '—' }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ row.account_no }} · {{ row.currency }}<template v-if="row.customer?.phone"> · {{ row.customer.phone }}</template>
              </p>
            </div>
            <div class="text-right">
              <p class="font-semibold tabular-nums text-neutral-900">{{ money(row) }}</p>
              <BaseBadge v-if="row.status === 'closed'" variant="neutral">{{ t('admin.savings.statuses.closed') }}</BaseBadge>
            </div>
          </button>
          <div class="mt-2 flex items-center justify-end gap-3">
            <template v-if="canCreate && row.status === 'open'">
              <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="record(row, 'deposit')">{{ t('admin.savings.deposit') }}</button>
              <button type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="record(row, 'withdrawal')">{{ t('admin.savings.withdraw') }}</button>
            </template>
            <button type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="statement(row)">{{ t('admin.savings.statement') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.savings.empty')">
      <template #cell-account_no="{ row }"><span class="tabular-nums text-neutral-600">{{ row.account_no }}</span></template>
      <template #cell-customer="{ row }">
        <p class="font-medium text-neutral-900">{{ row.customer?.name ?? '—' }}</p>
        <p v-if="row.customer?.phone" class="text-xs text-neutral-500">{{ row.customer.phone }}</p>
      </template>
      <template #cell-balance="{ row }"><span class="font-semibold tabular-nums">{{ money(row) }}</span></template>
      <template #cell-last_entry_on="{ row }">{{ row.last_entry_on ? formatDate(row.last_entry_on) : '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'open' ? 'success' : 'neutral'">{{ t(`admin.savings.statuses.${row.status}`) }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3 whitespace-nowrap">
          <template v-if="canCreate && row.status === 'open'">
            <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="record(row, 'deposit')">{{ t('admin.savings.deposit') }}</button>
            <button type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="record(row, 'withdrawal')">{{ t('admin.savings.withdraw') }}</button>
          </template>
          <button type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="statement(row)">{{ t('admin.savings.statement') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <SavingsOpenModal v-model="openModal" @saved="onOpened" />
    <SavingsEntryModal v-model="entryModal" :account="entryAccount" :type="entryType" @saved="refresh" />
  </div>
</template>
