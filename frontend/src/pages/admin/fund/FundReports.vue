<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { fundReportsService, type FundReport } from '@/services/fundReports'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Fund > Reports: for a period, per currency (never added together) —
 * balance at start, money in, money out, balance at end; then by fund type
 * and by fund. Print prints the report only.
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

// Currencies with no fund in the period are left out of the cards.
const totals = computed(() => report.value?.totals.filter((row) => row.funds > 0) ?? [])
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

      <p v-if="totals.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.fundReports.nothing') }}</p>

      <template v-else>
        <!-- Per currency -->
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="row in totals" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ row.currency }} · {{ t('admin.fundReports.fundsCount', { n: row.funds }) }}</p>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <div><dt class="text-neutral-500">{{ t('admin.fundReports.opening') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(row.opening, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.fundReports.moneyIn') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.fundReports.moneyOut') }}</dt><dd class="font-semibold tabular-nums text-amber-700">{{ formatMoney(row.money_out, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.fundReports.closing') }}</dt><dd class="font-semibold tabular-nums text-primary-800">{{ formatMoney(row.closing, row.currency) }}</dd></div>
            </dl>
          </div>
        </div>

        <!-- By fund type -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.fundReports.byType') }}</h2>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in report.types" :key="`${row.fund_type}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ typeName(row.fund_type) }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></p>
                <p class="font-semibold tabular-nums">{{ formatMoney(row.closing, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.fundReports.opening') }} {{ formatMoney(row.opening, row.currency) }} · {{ t('admin.fundReports.moneyIn') }} {{ formatMoney(row.money_in, row.currency) }} ·
                {{ t('admin.fundReports.moneyOut') }} {{ formatMoney(row.money_out, row.currency) }}
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-2 font-medium">{{ t('admin.fundReports.type') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('admin.funds.currency') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.opening') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyIn') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyOut') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.closing') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.types" :key="`${row.fund_type}-${row.currency}`">
                  <td class="px-4 py-2">{{ typeName(row.fund_type) }} <span class="text-xs text-neutral-500">({{ t('admin.fundReports.fundsCount', { n: row.funds }) }})</span></td>
                  <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ formatMoney(row.opening, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-green-700">{{ formatMoney(row.money_in, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-amber-700">{{ formatMoney(row.money_out, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{{ formatMoney(row.closing, row.currency) }}</td>
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
                <p class="font-semibold tabular-nums">{{ formatMoney(row.closing, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.fundReports.opening') }} {{ formatMoney(row.opening, row.currency) }} · {{ t('admin.fundReports.moneyIn') }} {{ formatMoney(row.money_in, row.currency) }} ·
                {{ t('admin.fundReports.moneyOut') }} {{ formatMoney(row.money_out, row.currency) }}
                <span v-if="row.target_amount"> · {{ t('admin.fundReports.goal') }} {{ goal(row.target_amount, row.closing) }}</span>
              </p>
            </RouterLink>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-2 font-medium">{{ t('admin.fundReports.fund') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('admin.fundReports.type') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('admin.funds.currency') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.opening') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyIn') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyOut') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.closing') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.goal') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.funds" :key="row.id">
                  <td class="px-4 py-2">
                    <RouterLink :to="`/admin/funds/${row.id}`" class="font-medium text-primary-700 hover:underline print:text-neutral-900 print:no-underline">{{ row.name }}</RouterLink>
                    <span class="text-xs text-neutral-500"> ({{ row.fund_no }})</span>
                    <span v-if="row.status === 'closed'" class="text-xs text-neutral-500"> · {{ t('admin.funds.statuses.closed') }}</span>
                  </td>
                  <td class="px-4 py-2 text-neutral-600">{{ typeName(row.fund_type) }}</td>
                  <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ formatMoney(row.opening, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-green-700">{{ formatMoney(row.money_in, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-amber-700">{{ formatMoney(row.money_out, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{{ formatMoney(row.closing, row.currency) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-neutral-600">{{ goal(row.target_amount, row.closing) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- By provider: money in during the period by who gave it -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.fundReports.byProvider') }}</h2>
          <p v-if="report.providers.length === 0" class="rounded-lg bg-neutral-50 px-3 py-3 text-center text-sm text-neutral-500">{{ t('admin.fundReports.noProviderMoney') }}</p>
          <template v-else>
            <div class="space-y-2 sm:hidden print:hidden">
              <div v-for="row in report.providers" :key="`${row.provider_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
                <div class="flex justify-between gap-2">
                  <p class="font-medium text-neutral-900">{{ row.name ?? t('admin.fundReports.noProvider') }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></p>
                  <p class="font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in, row.currency) }}</p>
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
                    <th class="px-4 py-2 font-medium">{{ t('admin.funds.currency') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.times') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ t('admin.fundReports.moneyIn') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in report.providers" :key="`${row.provider_id}-${row.currency}`">
                    <td class="px-4 py-2 tabular-nums text-neutral-600">{{ row.code ?? '—' }}</td>
                    <td class="px-4 py-2" :class="row.name ? '' : 'italic text-neutral-500'">{{ row.name ?? t('admin.fundReports.noProvider') }}</td>
                    <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ row.entries }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-green-700">{{ formatMoney(row.money_in, row.currency) }}</td>
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
