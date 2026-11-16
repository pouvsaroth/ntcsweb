<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { marketingReportService, type MarketingCampaignRow, type MarketingReportData } from '@/services/marketingReport'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Marketing > Report: inquiries dated in the period (leads), how many
 * became students, by source and by campaign — with each campaign's real
 * cost and the fees those students paid since they asked. Print prints the report.
 */
const { t, locale } = useI18n()

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), now.getMonth(), 1)))
const to = ref(iso(new Date(now.getFullYear(), now.getMonth() + 1, 0)))

const channels = ref<LookupOption[]>([])
async function loadChannels() {
  channels.value = await lookupsService.values('MARKETING_CHANNEL', locale.value).catch(() => [])
}
watch(locale, loadChannels)
const channelName = (code: string | null) => (code ? (channels.value.find((c) => c.code === code)?.name ?? code) : '')

const report = ref<MarketingReportData | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    report.value = await marketingReportService.get({ from: from.value || undefined, to: to.value || undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('marketing.report.loadFailed')
  } finally {
    loading.value = false
  }
}

// Every amount shows as USD | KHR, converted by the API (cost at the rate on
// the campaign's start date, each fee payment at its payment date's rate).
const usd = (value: number | null | undefined) => (value === null || value === undefined ? '—' : formatMoney(value, 'USD'))
const khr = (value: number | null | undefined) => (value === null || value === undefined ? '—' : formatMoney(value, 'KHR'))
type CampaignMoney = 'spent' | 'cost_per_lead' | 'cost_per_student' | 'fees'
const moneyCols: { key: CampaignMoney; labelKey: string }[] = [
  { key: 'spent', labelKey: 'marketing.report.cost' },
  { key: 'cost_per_lead', labelKey: 'marketing.report.perLead' },
  { key: 'cost_per_student', labelKey: 'marketing.report.perStudent' },
  { key: 'fees', labelKey: 'marketing.report.fees' },
]
const campaignName = (row: MarketingCampaignRow) => row.name ?? t('marketing.report.noCampaign')
const percent = (value: number) => `${Number(value)}%`

const tiles = computed(() => {
  const totals = report.value?.totals
  if (!totals) return []
  return [
    { label: t('marketing.report.leads'), value: String(totals.leads), hint: t('marketing.report.openHint', { n: totals.open }) },
    { label: t('marketing.report.enrolled'), value: String(totals.enrolled), hint: t('marketing.report.notInterestedHint', { n: totals.not_interested }) },
    { label: t('marketing.report.conversion'), value: percent(totals.conversion), hint: t('marketing.report.conversionHint') },
    { label: t('marketing.report.fees'), value: usd(totals.fees_usd), sub: khr(totals.fees_khr), hint: t('marketing.report.feesHint') },
  ]
})

const printPage = () => window.print()

onMounted(() => {
  loadChannels()
  load()
})
</script>

<template>
  <div>
    <div class="mb-6 print:hidden">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('marketing.report.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('marketing.report.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-end gap-3 print:hidden">
      <BaseInput v-model="from" type="date" class="w-full sm:w-44" :label="t('marketing.report.from')" />
      <BaseInput v-model="to" type="date" class="w-full sm:w-44" :label="t('marketing.report.to')" />
      <BaseButton :loading="loading" @click="load">{{ t('marketing.report.show') }}</BaseButton>
      <BaseButton v-if="report" variant="outline" @click="printPage">{{ t('marketing.report.print') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-if="report">
      <h2 class="mb-3 hidden text-lg font-semibold text-neutral-900 print:block">{{ t('marketing.report.title') }}</h2>
      <p class="mb-4 text-sm text-neutral-500">{{ t('marketing.report.period', { from: formatDate(report.from), to: formatDate(report.to) }) }}</p>

      <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="tile in tiles" :key="tile.label" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ tile.label }}</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-900 sm:text-2xl">{{ tile.value }}</p>
          <p v-if="'sub' in tile" class="text-sm font-semibold tabular-nums text-neutral-700">{{ tile.sub }}</p>
          <p class="mt-0.5 text-xs text-neutral-500">{{ tile.hint }}</p>
        </div>
      </div>

      <p v-if="report.totals.leads === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('marketing.report.nothing') }}
      </p>

      <template v-else>
        <!-- By campaign -->
        <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('marketing.report.byCampaign') }}</h3>
        <div class="mb-6 space-y-2 sm:hidden">
          <div v-for="row in report.by_campaign" :key="row.id ?? 'none'" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <p class="font-medium text-neutral-900">{{ campaignName(row) }}</p>
            <p v-if="row.id" class="text-xs text-neutral-500">{{ row.code }} · {{ channelName(row.channel) }}</p>
            <dl class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
              <div class="rounded-md bg-neutral-50 p-1.5">
                <dt class="text-neutral-500">{{ t('marketing.report.leads') }}</dt>
                <dd class="font-semibold tabular-nums">{{ row.leads }}</dd>
              </div>
              <div class="rounded-md bg-neutral-50 p-1.5">
                <dt class="text-neutral-500">{{ t('marketing.report.enrolled') }}</dt>
                <dd class="font-semibold tabular-nums">{{ row.enrolled }}</dd>
              </div>
              <div class="rounded-md bg-neutral-50 p-1.5">
                <dt class="text-neutral-500">{{ t('marketing.report.conversion') }}</dt>
                <dd class="font-semibold tabular-nums">{{ percent(row.conversion) }}</dd>
              </div>
            </dl>
            <table class="mt-2 w-full text-xs">
              <thead class="text-neutral-500"><tr><th></th><th class="text-right font-medium">USD</th><th class="pl-3 text-right font-medium">KHR</th></tr></thead>
              <tbody>
                <template v-for="col in moneyCols" :key="col.key">
                  <tr v-if="row.id || col.key === 'fees'">
                    <td class="py-0.5 text-neutral-500">{{ t(col.labelKey) }}</td>
                    <td class="whitespace-nowrap py-0.5 text-right tabular-nums text-neutral-800">{{ usd(row[`${col.key}_usd`]) }}</td>
                    <td class="whitespace-nowrap py-0.5 pl-3 text-right tabular-nums text-neutral-800">{{ khr(row[`${col.key}_khr`]) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
        <div class="mb-6 hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th rowspan="2" class="px-3 py-2 font-medium">{{ t('marketing.report.campaign') }}</th>
                <th rowspan="2" class="px-3 py-2 text-right font-medium">{{ t('marketing.report.leads') }}</th>
                <th rowspan="2" class="px-3 py-2 text-right font-medium">{{ t('marketing.report.enrolled') }}</th>
                <th rowspan="2" class="px-3 py-2 text-right font-medium">{{ t('marketing.report.conversion') }}</th>
                <th v-for="col in moneyCols" :key="col.key" colspan="2" class="px-3 pt-2 text-center font-medium">{{ t(col.labelKey) }}</th>
              </tr>
              <tr class="text-xs">
                <template v-for="col in moneyCols" :key="col.key"><th class="px-3 pb-2 text-right font-medium">USD</th><th class="px-3 pb-2 text-right font-medium">KHR</th></template>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in report.by_campaign" :key="row.id ?? 'none'">
                <td class="px-3 py-2">
                  <p class="font-medium text-neutral-900" :class="{ 'italic text-neutral-500': !row.id }">{{ campaignName(row) }}</p>
                  <p v-if="row.id" class="text-xs text-neutral-500">{{ row.code }} · {{ channelName(row.channel) }}</p>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.leads }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.enrolled }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ percent(row.conversion) }}</td>
                <template v-for="col in moneyCols" :key="col.key">
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ usd(row[`${col.key}_usd`]) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ khr(row[`${col.key}_khr`]) }}</td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- By source -->
        <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('marketing.report.bySource') }}</h3>
        <div class="space-y-2 sm:hidden">
          <div v-for="row in report.by_source" :key="row.source" class="flex items-center justify-between rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <p class="font-medium text-neutral-900">{{ t(`frontOffice.inquiries.sources.${row.source}`) }}</p>
            <p class="text-sm tabular-nums text-neutral-700">{{ row.enrolled }} / {{ row.leads }} · {{ percent(row.conversion) }}</p>
          </div>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 font-medium">{{ t('marketing.report.source') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('marketing.report.leads') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('marketing.report.enrolled') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('marketing.report.stillOpen') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('marketing.report.notInterested') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('marketing.report.conversion') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in report.by_source" :key="row.source">
                <td class="px-3 py-2 font-medium text-neutral-900">{{ t(`frontOffice.inquiries.sources.${row.source}`) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.leads }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.enrolled }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.open }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ row.not_interested }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ percent(row.conversion) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-3 text-xs text-neutral-500">{{ t('marketing.report.note') }}</p>
      </template>
    </template>
  </div>
</template>
