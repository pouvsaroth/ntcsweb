<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import FundEntryModal from '@/components/admin/FundEntryModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { fundEntriesService, fundsService, type Fund, type FundEntryFilters, type FundEntryRow, type FundEntryTotals } from '@/services/funds'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { moneyOrDash } from '@/utils/currency'
import { formatDate, formatMonth } from '@/utils/date'

/**
 * Fund > Money in & out: every money in or out of every fund in one list,
 * newest first. Money out is shown first (switch to Money in or All).
 * Narrow by dates or For month, fund, fund type and a search; the totals
 * add up everything the filters keep, in USD and KHR at each day's rate.
 * Edit / Remove work as on the fund's statement (active funds only).
 */
const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canUpdate = computed(() => auth.can('funds.update'))
const canDelete = computed(() => auth.can('funds.delete'))

const totals = ref<FundEntryTotals | null>(null)
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<FundEntryRow>(async (query) => {
  const result = await fundEntriesService.list(query)
  totals.value = result.totals
  return result
})

const filters = ref<FundEntryFilters>({ type: 'out', date_from: '', date_to: '', for_month: '', fund_id: '', fund_type: '' })
const search = ref('')
function filter<K extends keyof FundEntryFilters>(key: K, value: FundEntryFilters[K]) {
  filters.value = { ...filters.value, [key]: value }
  setFilter(key, value || undefined)
}
function onSearch(value: string) {
  search.value = value
  setSearch(value)
}
function clearDates() {
  for (const key of ['date_from', 'date_to', 'for_month'] as const) if (filters.value[key]) filter(key, '')
}

const funds = ref<Fund[]>([])
const types = ref<LookupOption[]>([])
async function loadTypes() {
  types.value = await lookupsService.values('FUND_TYPE', locale.value).catch(() => [])
}
watch(locale, loadTypes)
const typeOptions = computed(() => [{ value: '', label: t('admin.funds.allTypes') }, ...types.value.map((type) => ({ value: type.code, label: type.name }))])
const fundOptions = computed(() => [{ value: '', label: t('admin.fundEntries.allFunds') }, ...funds.value.map((f) => ({ value: String(f.id), label: `${f.fund_no} · ${f.name}` }))])
const kindOptions = computed(() => [
  { value: 'out', label: t('admin.funds.moneyOut') },
  { value: 'in', label: t('admin.funds.moneyIn') },
  { value: '', label: t('admin.fundEntries.both') },
])

const showIn = computed(() => filters.value.type !== 'out')
const showOut = computed(() => filters.value.type !== 'in')
const rowActions = computed(() => canUpdate.value || canDelete.value)
const editable = (row: FundEntryRow) => row.fund?.status === 'active'

// Edit opens the statement's form, which needs the whole fund (its balance).
const entryModal = ref(false)
const entryFund = ref<Fund | null>(null)
const editingEntry = ref<FundEntryRow | null>(null)
const actionError = ref<string | null>(null)
async function edit(row: FundEntryRow) {
  actionError.value = null
  try {
    entryFund.value = await fundsService.get(row.fund_id)
    editingEntry.value = row
    entryModal.value = true
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.funds.loadFailed')
  }
}
async function remove(row: FundEntryRow) {
  if (!(await confirmDialog.confirm({ message: t('admin.funds.removeEntryConfirm'), danger: true }))) return
  actionError.value = null
  try {
    await fundsService.removeEntry(row.id)
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError) actionError.value = (e.errors ? Object.values(e.errors)[0]?.[0] : undefined) ?? e.message
    else actionError.value = t('admin.funds.removeFailed')
  }
}

const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  actionError.value = null
  try {
    await fundEntriesService.excel(filters.value, search.value.trim())
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.fundEntries.exportFailed')
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  setFilter('type', 'out')
  void loadTypes()
  funds.value = (await fundsService.list({ page: 1, per_page: 100 }).catch(() => ({ data: [] as Fund[] }))).data
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('adminNav.items.fundEntries') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.fundEntries.subtitle') }}</p>
      </div>
      <BaseButton variant="outline" :loading="exporting" @click="exportExcel">{{ t('admin.fundEntries.excel') }}</BaseButton>
    </div>

    <div class="mb-3 inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
      <button
        v-for="kind in kindOptions"
        :key="kind.value"
        type="button"
        class="rounded-md px-3 py-1.5 text-sm font-medium"
        :class="filters.type === kind.value ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:text-neutral-900'"
        @click="filter('type', kind.value as FundEntryFilters['type'])"
      >
        {{ kind.label }}
      </button>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseInput :model-value="filters.date_from" type="date" :label="t('admin.fundEntries.from')" @update:model-value="filter('date_from', $event)" />
      <BaseInput :model-value="filters.date_to" type="date" :label="t('admin.fundEntries.to')" @update:model-value="filter('date_to', $event)" />
      <BaseInput :model-value="filters.for_month" type="month" :label="t('admin.funds.forMonth')" @update:model-value="filter('for_month', $event)" />
      <div class="flex items-end">
        <BaseButton v-if="filters.date_from || filters.date_to || filters.for_month" variant="outline" @click="clearDates">{{ t('admin.fundEntries.anyDate') }}</BaseButton>
      </div>
      <SearchableSelect :model-value="filters.fund_id" :options="fundOptions" :label="t('admin.fundEntries.fund')" @update:model-value="filter('fund_id', $event)" />
      <BaseSelect :model-value="filters.fund_type" :options="typeOptions" :label="t('admin.funds.type')" @update:model-value="filter('fund_type', $event)" />
      <div class="sm:col-span-2">
        <BaseInput :model-value="search" type="search" :label="t('admin.fundEntries.searchLabel')" :placeholder="t('admin.fundEntries.searchPlaceholder')" @update:model-value="onSearch($event)" />
      </div>
    </div>

    <!-- Totals of everything the filters keep. -->
    <div class="mb-4 grid gap-3" :class="showIn && showOut ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
      <div v-if="showIn" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs text-neutral-500">{{ t('admin.funds.totalIn') }} · {{ t('admin.fundEntries.entries', { n: totals?.in_count ?? 0 }) }}</p>
        <p class="mt-1 text-xl font-semibold tabular-nums text-green-700">{{ moneyOrDash(totals?.in_usd, 'USD') }}</p>
        <p class="text-sm tabular-nums text-neutral-500">{{ moneyOrDash(totals?.in_khr, 'KHR') }}</p>
      </div>
      <div v-if="showOut" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs text-neutral-500">{{ t('admin.funds.totalOut') }} · {{ t('admin.fundEntries.entries', { n: totals?.out_count ?? 0 }) }}</p>
        <p class="mt-1 text-xl font-semibold tabular-nums text-amber-700">{{ moneyOrDash(totals?.out_usd, 'USD') }}</p>
        <p class="text-sm tabular-nums text-neutral-500">{{ moneyOrDash(totals?.out_khr, 'KHR') }}</p>
      </div>
      <div v-if="showIn && showOut" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs text-neutral-500">{{ t('admin.fundEntries.net') }}</p>
        <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-900">{{ totals?.in_usd != null && totals?.out_usd != null ? moneyOrDash(Math.round((totals.in_usd - totals.out_usd) * 100) / 100, 'USD') : '—' }}</p>
        <p class="text-sm tabular-nums text-neutral-500">{{ totals?.in_khr != null && totals?.out_khr != null ? moneyOrDash(totals.in_khr - totals.out_khr, 'KHR') : '—' }}</p>
      </div>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div v-if="loading && items.length === 0" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.fundEntries.empty') }}</p>

    <template v-else>
      <!-- Phone: one card per entry. -->
      <div class="space-y-2 sm:hidden">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-medium text-neutral-900">{{ row.party || '—' }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ formatDate(row.transacted_on) }}<template v-if="row.for_month"> · {{ t('admin.funds.forMonth') }} {{ formatMonth(row.for_month) }}</template></p>
              <button type="button" class="mt-0.5 text-left text-xs font-medium text-primary-700 hover:underline" @click="router.push(`/admin/funds/${row.fund_id}`)">{{ row.fund?.fund_no }} · {{ row.fund?.name }}</button>
              <p v-if="row.remark" class="mt-0.5 text-xs text-neutral-500">{{ row.remark }}</p>
            </div>
            <p class="text-right font-semibold tabular-nums" :class="row.type === 'out' ? 'text-amber-700' : 'text-green-700'">
              {{ row.type === 'out' ? '-' : '+' }}{{ moneyOrDash(row.amount_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.amount_khr, 'KHR') }}</span>
            </p>
          </div>
          <div v-if="rowActions && editable(row)" class="mt-2 flex justify-end gap-3 text-sm">
            <button v-if="canUpdate" type="button" class="font-medium text-primary-600 hover:text-primary-700" @click="edit(row)">{{ t('admin.funds.editEntry') }}</button>
            <button v-if="canDelete" type="button" class="font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.funds.removeEntry') }}</button>
          </div>
        </div>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-4 py-2 font-medium">{{ t('admin.funds.date') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('admin.funds.forMonth') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('admin.fundEntries.fund') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('admin.funds.party') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('admin.funds.remark') }}</th>
              <th v-if="showIn" class="py-2 pr-3 text-right font-medium">{{ t('admin.funds.moneyIn') }}</th>
              <th v-if="showOut" class="py-2 pr-3 text-right font-medium">{{ t('admin.funds.moneyOut') }}</th>
              <th v-if="rowActions" class="py-2 pr-4"></th>
            </tr>
          </thead>
          <tbody :class="{ 'opacity-60': loading }">
            <tr v-for="row in items" :key="row.id" class="border-b border-neutral-100">
              <td class="whitespace-nowrap px-4 py-2 tabular-nums">{{ formatDate(row.transacted_on) }}</td>
              <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ row.for_month ? formatMonth(row.for_month) : '' }}</td>
              <td class="py-2 pr-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="router.push(`/admin/funds/${row.fund_id}`)">{{ row.fund?.name }}</button>
                <p class="text-xs text-neutral-500">{{ row.fund?.fund_no }} · {{ row.fund?.currency }}</p>
              </td>
              <td class="py-2 pr-3">{{ row.party ?? '' }}</td>
              <td class="py-2 pr-3 text-neutral-600">{{ row.remark ?? '' }}</td>
              <td v-if="showIn" class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-green-700"><template v-if="row.type === 'in'">{{ moneyOrDash(row.amount_usd, 'USD') }}<span class="block text-xs text-neutral-500">{{ moneyOrDash(row.amount_khr, 'KHR') }}</span></template></td>
              <td v-if="showOut" class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-amber-700"><template v-if="row.type === 'out'">{{ moneyOrDash(row.amount_usd, 'USD') }}<span class="block text-xs text-neutral-500">{{ moneyOrDash(row.amount_khr, 'KHR') }}</span></template></td>
              <td v-if="rowActions" class="whitespace-nowrap py-2 pr-4 text-right">
                <template v-if="editable(row)">
                  <button v-if="canUpdate" type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="edit(row)">{{ t('admin.funds.editEntry') }}</button>
                  <button v-if="canDelete" type="button" class="ml-3 text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.funds.removeEntry') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-neutral-300 font-semibold">
              <td colspan="5" class="px-4 py-2">{{ t('admin.funds.total') }} <span class="font-normal text-neutral-500">· {{ t('admin.fundEntries.allPages', { n: totals?.count ?? 0 }) }}</span></td>
              <td v-if="showIn" class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-green-700">{{ moneyOrDash(totals?.in_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(totals?.in_khr, 'KHR') }}</span></td>
              <td v-if="showOut" class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-amber-700">{{ moneyOrDash(totals?.out_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(totals?.out_khr, 'KHR') }}</span></td>
              <td v-if="rowActions"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
    </template>

    <FundEntryModal v-model="entryModal" :fund="entryFund" :type="editingEntry?.type ?? 'out'" :entry="editingEntry" @saved="fetch" />
  </div>
</template>
