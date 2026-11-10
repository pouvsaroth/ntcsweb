<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import FundEntryModal from '@/components/admin/FundEntryModal.vue'
import FundFormModal from '@/components/admin/FundFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { fundsService, type Fund, type FundEntryType, type FundSummaryRow } from '@/services/funds'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Fund > Funds: every fund, its type and balance; money in / out, and the statement.
const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const canCreate = computed(() => auth.can('funds.create'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Fund>((query) => fundsService.list(query))

const summary = ref<FundSummaryRow[]>([])
async function loadSummary() {
  summary.value = await fundsService.summary().catch(() => [])
}

// Fund types come from Base Data (FUND_TYPE), named in the current language.
const types = ref<LookupOption[]>([])
async function loadTypes() {
  types.value = await lookupsService.values('FUND_TYPE', locale.value).catch(() => [])
}
watch(locale, loadTypes)
const typeName = (code: string) => types.value.find((type) => type.code === code)?.name ?? code

const statusFilter = ref('active')
const statusOptions = computed(() => [
  { value: 'active', label: t('admin.funds.statuses.active') },
  { value: 'closed', label: t('admin.funds.statuses.closed') },
  { value: '', label: t('admin.funds.filterAll') },
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const typeFilter = ref('')
const typeOptions = computed(() => [{ value: '', label: t('admin.funds.allTypes') }, ...types.value.map((type) => ({ value: type.code, label: type.name }))])
function onType(value: string) {
  typeFilter.value = value
  setFilter('fund_type', value || undefined)
}

const columns = computed(() => [
  { key: 'fund_no', label: t('admin.funds.fundNo') },
  { key: 'name', label: t('admin.funds.name') },
  { key: 'type', label: t('admin.funds.type') },
  { key: 'balance', label: t('admin.funds.balance'), align: 'text-right' },
  { key: 'last_entry_on', label: t('admin.funds.lastEntry') },
  { key: 'status', label: t('admin.funds.status') },
  { key: 'actions', label: '', align: 'text-right' },
])

const money = (row: Fund) => formatMoney(row.balance, row.currency)

const formOpen = ref(false)
const entryModal = ref(false)
const entryType = ref<FundEntryType>('in')
const entryFund = ref<Fund | null>(null)

function record(row: Fund, type: FundEntryType) {
  entryFund.value = row
  entryType.value = type
  entryModal.value = true
}

function statement(row: Fund) {
  router.push(`/admin/funds/${row.id}`)
}

function refresh() {
  fetch()
  loadSummary()
}

onMounted(() => {
  setFilter('status', 'active')
  loadSummary()
  loadTypes()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.funds.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.funds.subtitle') }}</p>
    </div>

    <div v-if="summary.length" class="mb-6 grid gap-3 sm:grid-cols-2">
      <div v-for="row in summary" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.funds.heldIn', { currency: row.currency }) }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ formatMoney(row.balance, row.currency) }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.funds.activeFunds', { n: row.funds }) }}</p>
      </div>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('admin.funds.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-32" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseSelect class="w-44" :model-value="typeFilter" :options="typeOptions" @update:model-value="onType" />
      <BaseButton v-if="canCreate" class="ml-auto shrink-0" @click="formOpen = true">{{ t('admin.funds.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.funds.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button type="button" class="flex w-full items-start justify-between gap-2 text-left" @click="statement(row)">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.fund_no }} · {{ typeName(row.fund_type) }} · {{ row.currency }}</p>
            </div>
            <div class="text-right">
              <p class="font-semibold tabular-nums text-neutral-900">{{ money(row) }}</p>
              <BaseBadge v-if="row.status === 'closed'" variant="neutral">{{ t('admin.funds.statuses.closed') }}</BaseBadge>
            </div>
          </button>
          <div class="mt-2 flex items-center justify-end gap-3">
            <template v-if="canCreate && row.status === 'active'">
              <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="record(row, 'in')">{{ t('admin.funds.moneyIn') }}</button>
              <button type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="record(row, 'out')">{{ t('admin.funds.moneyOut') }}</button>
            </template>
            <button type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="statement(row)">{{ t('admin.funds.statement') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.funds.empty')">
      <template #cell-fund_no="{ row }"><span class="tabular-nums text-neutral-600">{{ row.fund_no }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p class="text-xs text-neutral-500">{{ row.currency }}</p>
      </template>
      <template #cell-type="{ row }">{{ typeName(row.fund_type) }}</template>
      <template #cell-balance="{ row }"><span class="font-semibold tabular-nums">{{ money(row) }}</span></template>
      <template #cell-last_entry_on="{ row }">{{ row.last_entry_on ? formatDate(row.last_entry_on) : '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">{{ t(`admin.funds.statuses.${row.status}`) }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3 whitespace-nowrap">
          <template v-if="canCreate && row.status === 'active'">
            <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="record(row, 'in')">{{ t('admin.funds.moneyIn') }}</button>
            <button type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="record(row, 'out')">{{ t('admin.funds.moneyOut') }}</button>
          </template>
          <button type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="statement(row)">{{ t('admin.funds.statement') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <FundFormModal v-model="formOpen" :fund="null" @saved="(fund) => router.push(`/admin/funds/${fund.id}`)" />
    <FundEntryModal v-model="entryModal" :fund="entryFund" :type="entryType" @saved="refresh" />
  </div>
</template>
