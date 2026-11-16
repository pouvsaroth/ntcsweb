<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { fundReportsService, type FundMoneyField, type FundReport, type FundReportBoth } from '@/services/fundReports'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Fund > Reports: for a period — balance at start, money in, money out,
 * balance at end; then by fund type, by fund and by provider. Every amount
 * shows as two columns, USD | KHR (converted by the API at the exchange rate
 * on each transaction's date; balances at the rate on the day measured).
 * Print prints the report only.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), 0, 1)))
const to = ref(iso(now))
const fundType = ref('')

const types = ref<LookupOption[]>([])
async function loadTypes() {
  types.value = await lookupsService.values('FUND_TYPE', locale.value).catch(() => [])
}
watch(locale, loadTypes)
const typeName = (code: string) => types.value.find((type) => type.code === code)?.name ?? code
const typeOptions = computed(() => [{ value: '', label: t('admin.fundReports.allTypes') }, ...types.value.map((type) => ({ value: type.code, label: type.name }))])

const report = ref<FundReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    report.value = await fundReportsService.get({ from: from.value || undefined, to: to.value || undefined, fund_type: fundType.value || undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.fundReports.loadFailed')
  } finally {
    loading.value = false
  }
}

const fields: { key: FundMoneyField; labelKey: string; color: string }[] = [
  { key: 'opening', labelKey: 'admin.fundReports.opening', color: '' },
  { key: 'money_in', labelKey: 'admin.fundReports.moneyIn', color: 'text-green-700' },
  { key: 'money_out', labelKey: 'admin.fundReports.moneyOut', color: 'text-amber-700' },
  { key: 'closing', labelKey: 'admin.fundReports.closing', color: 'font-semibold' },
]
const usd = (row: FundReportBoth, field: FundMoneyField) => formatMoney(row[`${field}_usd`], 'USD')
const khr = (row: FundReportBoth, field: FundMoneyField) => formatMoney(row[`${field}_khr`], 'KHR')
const zeroBoth = (): FundReportBoth => ({
  opening_usd: 0, opening_khr: 0, money_in_usd: 0, money_in_khr: 0, money_out_usd: 0, money_out_khr: 0, closing_usd: 0, closing_khr: 0,
})

// By fund type: USD and KHR funds of one type together.
const typeRows = computed(() => {
  const byType = new Map<string, FundReportBoth & { fund_type: string; funds: number }>()
  for (const row of report.value?.types ?? []) {
    const into = byType.get(row.fund_type) ?? { fund_type: row.fund_type, funds: 0, ...zeroBoth() }
    into.funds += row.funds
    for (const key of Object.keys(zeroBoth()) as (keyof FundReportBoth)[]) into[key] += row[key]
    byType.set(row.fund_type, into)
  }
  return [...byType.values()].sort((a, b) => b.closing_usd - a.closing_usd)
})

// By provider: money in in USD and in KHR, whatever the fund's currency.
const providerRows = computed(() => {
  const byProvider = new Map<string, { key: string; provider_id: number | null; code: string | null; name: string | null; entries: number; money_in_usd: number; money_in_khr: number }>()
  for (const row of report.value?.providers ?? []) {
    const key = String(row.provider_id ?? 'none')
    const into = byProvider.get(key) ?? { key, provider_id: row.provider_id, code: row.code, name: row.name, entries: 0, money_in_usd: 0, money_in_khr: 0 }
    into.entries += row.entries
    into.money_in_usd += row.money_in_usd
    into.money_in_khr += row.money_in_khr
    byProvider.set(key, into)
  }
  return [...byProvider.values()].sort((a, b) => Number(a.provider_id === null) - Number(b.provider_id === null) || b.money_in_usd - a.money_in_usd)
})

const goal = (target: number | null, closing: number) => (target ? `${Math.max(0, Math.min(100, Math.round((closing / target) * 100)))}%` : '')

const printPage = () => window.print()

onMounted(() => {
  loadTypes()
  load()
})
</script>

<template>
  <div>
    <div class="mb-6 print:hidden">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.fundReports.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.fundReports.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-end gap-3 print:hidden">
      <BaseInput v-model="from" type="date" class="w-full sm:w-44" :label="t('admin.fundReports.from')" />
      <BaseInput v-model="to" type="date" class="w-full sm:w-44" :label="t('admin.fundReports.to')" />
      <BaseSelect v-model="fundType" class="w-full sm:w-48" :label="t('admin.fundReports.type')" :options="typeOptions" />
      <BaseButton :loading="loading" @click="load">{{ t('admin.fundReports.show') }}</BaseButton>
      <BaseButton v-if="report" variant="outline" @click="printPage">{{ t('admin.fundReports.print') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="report" class="print-area space-y-8">
      <div class="hidden print:block">
        <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
        <h1 class="text-lg font-semibold">{{ t('admin.fundReports.title') }}</h1>
      </div>
      <p class="text-sm text-neutral-600">
        {{ t('admin.fundReports.period', { from: formatDate(report.from), to: formatDate(report.to) }) }}
        <span v-if="fundType"> · {{ typeName(fundType) }}</span>
      </p>

      <p v-if="report.all.funds === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.fundReports.nothing') }}</p>

      <template v-else>
        <!-- All funds, USD | KHR -->
        <div class="max-w-xl rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.fundReports.fundsCount', { n: report.all.funds }) }}</p>
          <table class="mt-2 w-full text-sm">
            <thead class="text-xs text-neutral-500"><tr><th></th><th class="pb-1 text-right font-medium">USD</th><th class="pb-1 pl-4 text-right font-medium">KHR</th></tr></thead>
            <tbody>
              <tr v-for="field in fields" :key="field.key">
                <td class="py-1 text-neutral-500">{{ t(field.labelKey) }}</td>
                <td class="whitespace-nowrap py-1 text-right font-semibold tabular-nums" :class="field.color">{{ usd(report.all, field.key) }}</td>
                <td class="whitespace-nowrap py-1 pl-4 text-right font-semibold tabular-nums" :class="field.color">{{ khr(report.all, field.key) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- By fund type -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.fundReports.byType') }}</h2>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in typeRows" :key="row.fund_type" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ typeName(row.fund_type) }}</p>
                <p class="text-right font-semibold tabular-nums">{{ usd(row, 'closing') }}<br />{{ khr(row, 'closing') }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.fundReports.opening') }} {{ usd(row, 'opening') }} / {{ khr(row, 'opening') }} · {{ t('admin.fundReports.moneyIn') }} {{ usd(row, 'money_in') }} / {{ khr(row, 'money_in') }} ·
                {{ t('admin.fundReports.moneyOut') }} {{ usd(row, 'money_out') }} / {{ khr(row, 'money_out') }}
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.fundReports.type') }}</th>
                  <th v-for="field in fields" :key="field.key" colspan="2" class="px-4 pt-2 text-center font-medium">{{ t(field.labelKey) }}</th>
                </tr>
                <tr class="text-xs">
                  <template v-for="field in fields" :key="field.key"><th class="px-4 pb-2 text-right font-medium">USD</th><th class="px-4 pb-2 text-right font-medium">KHR</th></template>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in typeRows" :key="row.fund_type">
                  <td class="px-4 py-2">{{ typeName(row.fund_type) }} <span class="text-xs text-neutral-500">({{ t('admin.fundReports.fundsCount', { n: row.funds }) }})</span></td>
                  <template v-for="field in fields" :key="field.key">
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- By fund -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.fundReports.byFund') }}</h2>
          <div class="space-y-2 sm:hidden print:hidden">
            <RouterLink v-for="row in report.funds" :key="row.id" :to="`/admin/funds/${row.id}`" class="block rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
                  <p class="text-xs text-neutral-500">{{ row.fund_no }} · {{ typeName(row.fund_type) }} · {{ row.currency }}</p>
                </div>
                <p class="text-right font-semibold tabular-nums">{{ usd(row, 'closing') }}<br />{{ khr(row, 'closing') }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.fundReports.opening') }} {{ usd(row, 'opening') }} / {{ khr(row, 'opening') }} · {{ t('admin.fundReports.moneyIn') }} {{ usd(row, 'money_in') }} / {{ khr(row, 'money_in') }} ·
                {{ t('admin.fundReports.moneyOut') }} {{ usd(row, 'money_out') }} / {{ khr(row, 'money_out') }}
                <span v-if="row.target_amount"> · {{ t('admin.fundReports.goal') }} {{ goal(row.target_amount, row.closing) }}</span>
              </p>
            </RouterLink>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.fundReports.fund') }}</th>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.fundReports.type') }}</th>
                  <th v-for="field in fields" :key="field.key" colspan="2" class="px-4 pt-2 text-center font-medium">{{ t(field.labelKey) }}</th>
                  <th rowspan="2" class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.goal') }}</th>
                </tr>
                <tr class="text-xs">
                  <template v-for="field in fields" :key="field.key"><th class="px-4 pb-2 text-right font-medium">USD</th><th class="px-4 pb-2 text-right font-medium">KHR</th></template>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.funds" :key="row.id">
                  <td class="px-4 py-2">
                    <RouterLink :to="`/admin/funds/${row.id}`" class="font-medium text-primary-700 hover:underline print:text-neutral-900 print:no-underline">{{ row.name }}</RouterLink>
                    <span class="text-xs text-neutral-500"> ({{ row.fund_no }} · {{ row.currency }})</span>
                    <span v-if="row.status === 'closed'" class="text-xs text-neutral-500"> · {{ t('admin.funds.statuses.closed') }}</span>
                  </td>
                  <td class="px-4 py-2 text-neutral-600">{{ typeName(row.fund_type) }}</td>
                  <template v-for="field in fields" :key="field.key">
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </template>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-neutral-600">{{ goal(row.target_amount, row.closing) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- By provider: money in during the period by who gave it -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.fundReports.byProvider') }}</h2>
          <p v-if="providerRows.length === 0" class="rounded-lg bg-neutral-50 px-3 py-3 text-center text-sm text-neutral-500">{{ t('admin.fundReports.noProviderMoney') }}</p>
          <template v-else>
            <div class="space-y-2 sm:hidden print:hidden">
              <div v-for="row in providerRows" :key="row.key" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
                <div class="flex justify-between gap-2">
                  <p class="font-medium text-neutral-900">{{ row.name ?? t('admin.fundReports.noProvider') }}</p>
                  <p class="text-right font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in_usd, 'USD') }}<br />{{ formatMoney(row.money_in_khr, 'KHR') }}</p>
                </div>
                <p class="mt-1 text-xs text-neutral-500"><template v-if="row.code">{{ row.code }} · </template>{{ t('admin.fundReports.timesGiven', { n: row.entries }) }}</p>
              </div>
            </div>
            <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
              <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                  <tr>
                    <th class="px-4 py-2 font-medium">{{ t('admin.fundProviders.code') }}</th>
                    <th class="px-4 py-2 font-medium">{{ t('admin.funds.providedBy') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.times') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyIn') }} (USD)</th>
                    <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyIn') }} (KHR)</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in providerRows" :key="row.key">
                    <td class="px-4 py-2 tabular-nums text-neutral-600">{{ row.code ?? '—' }}</td>
                    <td class="px-4 py-2" :class="row.name ? '' : 'italic text-neutral-500'">{{ row.name ?? t('admin.fundReports.noProvider') }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ row.entries }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in_usd, 'USD') }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in_khr, 'KHR') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>
      </template>
    </div>
  </div>
</template>
