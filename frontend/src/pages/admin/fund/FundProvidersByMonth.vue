<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { fundProvidersService } from '@/services/fundProviders'
import { fundProvidersByMonthService, type FundProvidersByMonth, type FundProvidersByMonthRow } from '@/services/fundReports'
import { fundsService } from '@/services/funds'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatMonth, thisMonth } from '@/utils/date'

/**
 * Fund > Providers by month: money in per provider (Fund > Providers) or
 * staff member (HRM > Staff), one column per month, with a Total column and
 * a Total row for each currency. Each money in counts under its For month.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()

const fromMonth = ref(`${new Date().getFullYear()}-01`)
const toMonth = ref(thisMonth())
const fundType = ref('')
const who = ref('')

const types = ref<LookupOption[]>([])
const whoOptions = ref<{ value: string; label: string; hint?: string }[]>([])
const typeOptions = computed(() => [{ value: '', label: t('admin.fundByMonth.allTypes') }, ...types.value.map((type) => ({ value: type.code, label: type.name }))])

const report = ref<FundProvidersByMonth | null>(null)
const loading = ref(false)
const exporting = ref(false)
const error = ref<string | null>(null)

const filters = () => ({ from_month: fromMonth.value || undefined, to_month: toMonth.value || undefined, who: who.value || undefined, fund_type: fundType.value || undefined })

async function loadOptions() {
  types.value = await lookupsService.values('FUND_TYPE', locale.value).catch(() => [])
  const [providers, staff] = await Promise.all([fundProvidersService.listAll().catch(() => []), fundsService.staffOptions().catch(() => [])])
  whoOptions.value = [
    { value: 'providers', label: t('admin.fundByMonth.allProviders') },
    { value: 'staff', label: t('admin.fundByMonth.allStaff') },
    ...providers.map((p) => ({ value: `provider:${p.id}`, label: p.name, hint: [t('admin.fundByMonth.provider'), p.code].filter(Boolean).join(' · ') })),
    ...staff.map((s) => ({ value: `staff:${s.id}`, label: s.name, hint: [t('admin.fundByMonth.staff'), s.employee_code].filter(Boolean).join(' · ') })),
  ]
}

async function load() {
  loading.value = true
  error.value = null
  try {
    report.value = await fundProvidersByMonthService.get(filters())
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.fundByMonth.loadFailed')
  } finally {
    loading.value = false
  }
}

async function exportExcel() {
  exporting.value = true
  error.value = null
  try {
    await fundProvidersByMonthService.excel(filters())
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.fundByMonth.exportFailed')
  } finally {
    exporting.value = false
  }
}

const printPage = () => window.print()

/** Each currency's rows with its own Total row — USD and KHR are never added together. */
const blocks = computed(() =>
  (report.value?.totals ?? []).map((total) => ({ total, rows: (report.value?.rows ?? []).filter((r) => r.currency === total.currency) })),
)

const money = (amount: number, currency: 'USD' | 'KHR') => formatMoney(amount, currency)
const nameOf = (row: FundProvidersByMonthRow) => row.name ?? t('admin.fundByMonth.noName')
const kindOf = (row: FundProvidersByMonthRow) =>
  row.provider_id !== null ? t('admin.fundByMonth.provider') : row.staff_id !== null ? t('admin.fundByMonth.staff') : ''
/** Phone cards list only the months with money. */
const givenMonths = (row: FundProvidersByMonthRow) => Object.entries(row.months).filter(([, v]) => v > 0)

onMounted(() => {
  loadOptions()
  load()
})
</script>

<template>
  <div>
    <div class="mb-6 print:hidden">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.fundByMonth.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.fundByMonth.subtitle') }}</p>
    </div>

    <div class="mb-6 grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap print:hidden">
      <BaseInput v-model="fromMonth" type="month" class="sm:w-44" :label="t('admin.fundByMonth.fromMonth')" />
      <BaseInput v-model="toMonth" type="month" class="sm:w-44" :label="t('admin.fundByMonth.toMonth')" />
      <BaseSelect v-model="fundType" class="col-span-2 sm:w-48" :label="t('admin.fundByMonth.type')" :options="typeOptions" />
      <div class="col-span-2 sm:w-72">
        <SearchableSelect v-model="who" :options="whoOptions" :label="t('admin.fundByMonth.providedBy')" :placeholder="t('admin.fundByMonth.everyone')" />
      </div>
      <div class="col-span-2 flex flex-wrap gap-2">
        <BaseButton :loading="loading" @click="load">{{ t('admin.fundByMonth.show') }}</BaseButton>
        <BaseButton v-if="report" variant="outline" :loading="exporting" @click="exportExcel">{{ t('admin.fundByMonth.excel') }}</BaseButton>
        <BaseButton v-if="report" variant="outline" @click="printPage">{{ t('admin.fundByMonth.print') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="report" class="print-area space-y-6">
      <div class="hidden print:block">
        <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
        <h1 class="text-lg font-semibold">{{ t('admin.fundByMonth.title') }}</h1>
      </div>
      <p class="text-sm text-neutral-600">{{ t('admin.fundByMonth.period', { from: formatMonth(report.from_month), to: formatMonth(report.to_month) }) }}</p>

      <p v-if="report.rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.fundByMonth.nothing') }}</p>

      <section v-for="block in blocks" :key="block.total.currency">
        <h2 class="mb-3 text-base font-semibold text-neutral-900">
          {{ t('admin.fundByMonth.inCurrency', { currency: block.total.currency }) }}
          <span class="ml-2 text-sm font-normal text-neutral-500">{{ t('admin.fundByMonth.people', { n: block.total.people }) }}</span>
        </h2>

        <!-- Phone: one card per provider, then the total. -->
        <div class="space-y-3 sm:hidden print:hidden">
          <div v-for="row in block.rows" :key="row.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-900">{{ nameOf(row) }}</p>
                <p class="text-xs text-neutral-500">{{ [kindOf(row), row.code].filter(Boolean).join(' · ') }}</p>
              </div>
              <p class="shrink-0 text-sm font-semibold text-green-700">{{ money(row.total, row.currency) }}</p>
            </div>
            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
              <template v-for="[month, amount] in givenMonths(row)" :key="month">
                <dt class="text-neutral-500">{{ formatMonth(month) }}</dt>
                <dd class="text-right text-neutral-800">{{ money(amount, row.currency) }}</dd>
              </template>
            </dl>
          </div>
          <div class="rounded-[--radius-card] border border-neutral-300 bg-neutral-50 p-4">
            <div class="flex items-center justify-between">
              <p class="text-sm font-semibold text-neutral-900">{{ t('admin.fundByMonth.total') }}</p>
              <p class="text-sm font-bold text-neutral-900">{{ money(block.total.total, block.total.currency) }}</p>
            </div>
            <dl class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
              <template v-for="month in report.months" :key="month">
                <dt class="text-neutral-500">{{ formatMonth(month) }}</dt>
                <dd class="text-right text-neutral-800">{{ money(block.total.months[month] ?? 0, block.total.currency) }}</dd>
              </template>
            </dl>
          </div>
        </div>

        <!-- Wider screens and print: the grid. -->
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block print:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="w-10 px-3 py-3 text-left">{{ t('admin.fundByMonth.no') }}</th>
                <th class="sticky left-0 bg-neutral-50 px-3 py-3 text-left">{{ t('admin.fundByMonth.providedBy') }}</th>
                <th v-for="month in report.months" :key="month" class="whitespace-nowrap px-3 py-3 text-right">{{ formatMonth(month) }}</th>
                <th class="px-3 py-3 text-right">{{ t('admin.fundByMonth.total') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, i) in block.rows" :key="row.key">
                <td class="px-3 py-2 text-neutral-500">{{ i + 1 }}</td>
                <td class="sticky left-0 bg-white px-3 py-2">
                  <p class="whitespace-nowrap font-medium text-neutral-900">{{ nameOf(row) }}</p>
                  <p class="whitespace-nowrap text-xs text-neutral-500">{{ [kindOf(row), row.code].filter(Boolean).join(' · ') }}</p>
                </td>
                <td v-for="month in report.months" :key="month" class="whitespace-nowrap px-3 py-2 text-right" :class="row.months[month] ? 'text-neutral-800' : 'text-neutral-300'">
                  {{ row.months[month] ? money(row.months[month], row.currency) : '—' }}
                </td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-green-700">{{ money(row.total, row.currency) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold text-neutral-900">
              <tr>
                <td class="px-3 py-2"></td>
                <td class="sticky left-0 bg-neutral-50 px-3 py-2">{{ t('admin.fundByMonth.total') }}</td>
                <td v-for="month in report.months" :key="month" class="whitespace-nowrap px-3 py-2 text-right">{{ block.total.months[month] ? money(block.total.months[month], block.total.currency) : '—' }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(block.total.total, block.total.currency) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>
