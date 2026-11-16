<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { loanReportsService, type LoanMoney, type LoanReport, type PositionMoney, type SavingsMoney } from '@/services/loanReports'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Reports: Savings and Owe for a period. Totals, then per customer;
 * with both menus, each customer's position (savings kept vs money owed).
 * Every amount shows as two columns, USD | KHR (converted by the API: money
 * that moved at its own date's rate, balances now at today's rate). Print
 * prints the report only.
 */
const { t } = useI18n()
const auth = useAuthStore()

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), 0, 1)))
const to = ref(iso(now))

const report = ref<LoanReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    report.value = await loanReportsService.get({ from: from.value || undefined, to: to.value || undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.loanReports.loadFailed')
  } finally {
    loading.value = false
  }
}

// Currencies with nothing in them are left out of the cards.
const savingsTotals = computed(() => report.value?.savings?.totals.filter((r) => r.accounts > 0) ?? [])
const loanTotals = computed(() => report.value?.loans?.totals.filter((r) => r.active_loans > 0 || r.lent > 0 || r.collected > 0 || r.interest > 0) ?? [])
const savingsRows = computed(() => report.value?.savings?.customers.filter((r) => r.balance !== 0 || r.deposits > 0 || r.withdrawals > 0) ?? [])
const loanRows = computed(() => report.value?.loans?.customers.filter((r) => r.owed > 0 || r.lent > 0 || r.collected > 0 || r.interest > 0) ?? [])

type Field<F> = { key: F; labelKey: string; color: string }
const loanFields: Field<LoanMoney>[] = [
  { key: 'lent', labelKey: 'admin.loanReports.lent', color: '' },
  { key: 'collected', labelKey: 'admin.loanReports.collected', color: '' },
  { key: 'interest', labelKey: 'admin.loanReports.interest', color: 'text-green-700' },
  { key: 'owed', labelKey: 'admin.loanReports.owedNow', color: 'font-semibold' },
  { key: 'late', labelKey: 'admin.loanReports.lateNow', color: 'text-danger-600' },
]
const savingsFields: Field<SavingsMoney>[] = [
  { key: 'deposits', labelKey: 'admin.loanReports.deposits', color: 'text-green-700' },
  { key: 'withdrawals', labelKey: 'admin.loanReports.withdrawals', color: 'text-amber-700' },
  { key: 'balance', labelKey: 'admin.loanReports.balanceNow', color: 'font-semibold' },
]
const positionFields: Field<PositionMoney>[] = [
  { key: 'savings', labelKey: 'admin.loanReports.savingsKept', color: '' },
  { key: 'owed', labelKey: 'admin.loanReports.owedNow', color: '' },
  { key: 'net', labelKey: 'admin.loanReports.net', color: 'font-semibold' },
]
// Reads `<field>_usd` / `<field>_khr` off any report row.
const usd = (row: object, field: string) => formatMoney((row as Record<string, number>)[`${field}_usd`] ?? 0, 'USD')
const khr = (row: object, field: string) => formatMoney((row as Record<string, number>)[`${field}_khr`] ?? 0, 'KHR')
const activeLoans = computed(() => report.value?.loans?.totals.reduce((n, r) => n + r.active_loans, 0) ?? 0)
const lateLoans = computed(() => report.value?.loans?.totals.reduce((n, r) => n + r.late_loans, 0) ?? 0)
const openAccounts = computed(() => report.value?.savings?.totals.reduce((n, r) => n + r.open_accounts, 0) ?? 0)

const printPage = () => window.print()

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 print:hidden">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.loanReports.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.loanReports.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-end gap-3 print:hidden">
      <BaseInput v-model="from" type="date" class="w-full sm:w-44" :label="t('admin.loanReports.from')" />
      <BaseInput v-model="to" type="date" class="w-full sm:w-44" :label="t('admin.loanReports.to')" />
      <BaseButton :loading="loading" @click="load">{{ t('admin.loanReports.show') }}</BaseButton>
      <BaseButton v-if="report" variant="outline" @click="printPage">{{ t('admin.loanReports.print') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="report" class="print-area space-y-8">
      <div class="hidden print:block">
        <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
        <h1 class="text-lg font-semibold">{{ t('admin.loanReports.title') }}</h1>
      </div>
      <p class="text-sm text-neutral-600">{{ t('admin.loanReports.period', { from: formatDate(report.from), to: formatDate(report.to) }) }}</p>

      <!-- Owe -->
      <section v-if="report.loans">
        <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('adminNav.items.customerLoans') }}</h2>
        <p v-if="loanTotals.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.loanReports.nothing') }}</p>
        <div v-else class="max-w-xl rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.customerLoans.loansCount', { n: activeLoans }) }}<template v-if="lateLoans"> · {{ t('admin.borrowings.lateCount', { count: lateLoans }) }}</template></p>
          <table class="mt-2 w-full text-sm">
            <thead class="text-xs text-neutral-500"><tr><th></th><th class="pb-1 text-right font-medium">USD</th><th class="pb-1 pl-4 text-right font-medium">KHR</th></tr></thead>
            <tbody>
              <tr v-for="field in loanFields" :key="field.key">
                <td class="py-1 text-neutral-500">{{ t(field.labelKey) }}</td>
                <td class="whitespace-nowrap py-1 text-right font-semibold tabular-nums" :class="field.color">{{ usd(report.loans.all, field.key) }}</td>
                <td class="whitespace-nowrap py-1 pl-4 text-right font-semibold tabular-nums" :class="field.color">{{ khr(report.loans.all, field.key) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <template v-if="loanRows.length">
          <h3 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.loanReports.byCustomer') }}</h3>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in loanRows" :key="`${row.customer_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></p>
                <p class="text-right font-semibold tabular-nums">{{ usd(row, 'owed') }}<br />{{ khr(row, 'owed') }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                <template v-for="(field, i) in loanFields.filter((f) => f.key !== 'owed')" :key="field.key"><template v-if="i"> · </template>{{ t(field.labelKey) }} {{ usd(row, field.key) }} / {{ khr(row, field.key) }}</template>
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.customerLoans.customer') }}</th>
                  <th v-for="field in loanFields" :key="field.key" colspan="2" class="px-4 pt-2 text-center font-medium">{{ t(field.labelKey) }}</th>
                </tr>
                <tr class="text-xs">
                  <template v-for="field in loanFields" :key="field.key"><th class="px-4 pb-2 text-right font-medium">USD</th><th class="px-4 pb-2 text-right font-medium">KHR</th></template>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in loanRows" :key="`${row.customer_id}-${row.currency}`">
                  <td class="px-4 py-2">{{ row.name }} <span v-if="row.code" class="text-xs text-neutral-500">({{ row.code }})</span> <span class="text-xs text-neutral-500">· {{ row.currency }}</span></td>
                  <template v-for="field in loanFields" :key="field.key">
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Savings -->
      <section v-if="report.savings">
        <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('adminNav.items.savings') }}</h2>
        <p v-if="savingsTotals.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.loanReports.nothing') }}</p>
        <div v-else class="max-w-xl rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.savings.openAccounts', { n: openAccounts }) }}</p>
          <table class="mt-2 w-full text-sm">
            <thead class="text-xs text-neutral-500"><tr><th></th><th class="pb-1 text-right font-medium">USD</th><th class="pb-1 pl-4 text-right font-medium">KHR</th></tr></thead>
            <tbody>
              <tr v-for="field in savingsFields" :key="field.key">
                <td class="py-1 text-neutral-500">{{ t(field.labelKey) }}</td>
                <td class="whitespace-nowrap py-1 text-right font-semibold tabular-nums" :class="field.color">{{ usd(report.savings.all, field.key) }}</td>
                <td class="whitespace-nowrap py-1 pl-4 text-right font-semibold tabular-nums" :class="field.color">{{ khr(report.savings.all, field.key) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <template v-if="savingsRows.length">
          <h3 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.loanReports.byCustomer') }}</h3>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in savingsRows" :key="`${row.customer_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></p>
                <p class="text-right font-semibold tabular-nums">{{ usd(row, 'balance') }}<br />{{ khr(row, 'balance') }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                <template v-for="(field, i) in savingsFields.filter((f) => f.key !== 'balance')" :key="field.key"><template v-if="i"> · </template>{{ t(field.labelKey) }} {{ usd(row, field.key) }} / {{ khr(row, field.key) }}</template>
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.savings.customer') }}</th>
                  <th v-for="field in savingsFields" :key="field.key" colspan="2" class="px-4 pt-2 text-center font-medium">{{ t(field.labelKey) }}</th>
                </tr>
                <tr class="text-xs">
                  <template v-for="field in savingsFields" :key="field.key"><th class="px-4 pb-2 text-right font-medium">USD</th><th class="px-4 pb-2 text-right font-medium">KHR</th></template>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in savingsRows" :key="`${row.customer_id}-${row.currency}`">
                  <td class="px-4 py-2">{{ row.name }} <span v-if="row.code" class="text-xs text-neutral-500">({{ row.code }})</span> <span class="text-xs text-neutral-500">· {{ row.currency }}</span></td>
                  <template v-for="field in savingsFields" :key="field.key">
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Both: each customer position -->
      <section v-if="report.positions && report.positions.length">
        <h2 class="mb-1 text-base font-semibold text-neutral-900">{{ t('admin.loanReports.positions') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('admin.loanReports.positionsHint') }}</p>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in report.positions" :key="`${row.customer_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <p class="font-medium text-neutral-900">{{ row.name }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></p>
              <table class="mt-2 w-full text-xs">
                <thead class="text-neutral-500"><tr><th></th><th class="text-right font-medium">USD</th><th class="pl-3 text-right font-medium">KHR</th></tr></thead>
                <tbody>
                  <tr v-for="field in positionFields" :key="field.key">
                    <td class="py-0.5 text-neutral-500">{{ t(field.labelKey) }}</td>
                    <td class="whitespace-nowrap py-0.5 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap py-0.5 pl-3 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th rowspan="2" class="px-4 py-2 font-medium">{{ t('admin.savings.customer') }}</th>
                  <th v-for="field in positionFields" :key="field.key" colspan="2" class="px-4 pt-2 text-center font-medium">{{ t(field.labelKey) }}</th>
                </tr>
                <tr class="text-xs">
                  <template v-for="field in positionFields" :key="field.key"><th class="px-4 pb-2 text-right font-medium">USD</th><th class="px-4 pb-2 text-right font-medium">KHR</th></template>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.positions" :key="`${row.customer_id}-${row.currency}`">
                  <td class="px-4 py-2">{{ row.name }} <span class="text-xs text-neutral-500">· {{ row.currency }}</span></td>
                  <template v-for="field in positionFields" :key="field.key">
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ usd(row, field.key) }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="field.color">{{ khr(row, field.key) }}</td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
      </section>
    </div>
  </div>
</template>
