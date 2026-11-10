<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { loanReportsService, type LoanReport } from '@/services/loanReports'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Reports: Savings and Owe for a period, per currency (never added
 * together). Totals, then per customer; with both menus, each customer's
 * position (savings kept vs money owed). Print prints the report only.
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
        <div v-else class="grid gap-3 sm:grid-cols-2">
          <div v-for="row in loanTotals" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ row.currency }} · {{ t('admin.customerLoans.loansCount', { n: row.active_loans }) }}</p>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.lent') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(row.lent, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.collected') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(row.collected, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.interest') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ formatMoney(row.interest, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.owedNow') }}</dt><dd class="font-semibold tabular-nums text-primary-800">{{ formatMoney(row.owed, row.currency) }}</dd></div>
              <div class="col-span-2">
                <dt class="text-neutral-500">{{ t('admin.loanReports.lateNow') }}</dt>
                <dd class="font-semibold tabular-nums" :class="row.late > 0 ? 'text-danger-600' : ''">
                  {{ formatMoney(row.late, row.currency) }}<span v-if="row.late_loans" class="font-normal text-neutral-500"> · {{ t('admin.borrowings.lateCount', { count: row.late_loans }) }}</span>
                </dd>
              </div>
            </dl>
          </div>
        </div>

        <template v-if="loanRows.length">
          <h3 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.loanReports.byCustomer') }}</h3>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in loanRows" :key="`${row.customer_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name }}</p>
                <p class="font-semibold tabular-nums">{{ formatMoney(row.owed, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.loanReports.lent') }} {{ formatMoney(row.lent, row.currency) }} · {{ t('admin.loanReports.collected') }} {{ formatMoney(row.collected, row.currency) }} ·
                {{ t('admin.loanReports.interest') }} {{ formatMoney(row.interest, row.currency) }}
                <span v-if="row.late > 0" class="text-danger-600"> · {{ t('admin.borrowings.late') }} {{ formatMoney(row.late, row.currency) }}</span>
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-2 font-medium">{{ t('admin.customerLoans.customer') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('admin.savings.currency') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.lent') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.collected') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.interest') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.owedNow') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.lateNow') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in loanRows" :key="`${row.customer_id}-${row.currency}`">
                  <td class="px-4 py-2">{{ row.name }} <span v-if="row.code" class="text-xs text-neutral-500">({{ row.code }})</span></td>
                  <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(row.lent, row.currency) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(row.collected, row.currency) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums text-green-700">{{ formatMoney(row.interest, row.currency) }}</td>
                  <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ formatMoney(row.owed, row.currency) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums" :class="row.late > 0 ? 'text-danger-600' : 'text-neutral-400'">{{ formatMoney(row.late, row.currency) }}</td>
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
        <div v-else class="grid gap-3 sm:grid-cols-2">
          <div v-for="row in savingsTotals" :key="row.currency" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ row.currency }} · {{ t('admin.savings.openAccounts', { n: row.open_accounts }) }}</p>
            <dl class="mt-2 grid grid-cols-3 gap-x-4 text-sm">
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.deposits') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ formatMoney(row.deposits, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.withdrawals') }}</dt><dd class="font-semibold tabular-nums text-amber-700">{{ formatMoney(row.withdrawals, row.currency) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.loanReports.balanceNow') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(row.balance, row.currency) }}</dd></div>
            </dl>
          </div>
        </div>

        <template v-if="savingsRows.length">
          <h3 class="mb-2 mt-5 text-sm font-semibold text-neutral-800">{{ t('admin.loanReports.byCustomer') }}</h3>
          <div class="space-y-2 sm:hidden print:hidden">
            <div v-for="row in savingsRows" :key="`${row.customer_id}-${row.currency}`" class="rounded-lg border border-neutral-200 bg-white p-3 text-sm">
              <div class="flex justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name }}</p>
                <p class="font-semibold tabular-nums">{{ formatMoney(row.balance, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.loanReports.deposits') }} {{ formatMoney(row.deposits, row.currency) }} · {{ t('admin.loanReports.withdrawals') }} {{ formatMoney(row.withdrawals, row.currency) }}
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-2 font-medium">{{ t('admin.savings.customer') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('admin.savings.currency') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.deposits') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.withdrawals') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.balanceNow') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in savingsRows" :key="`${row.customer_id}-${row.currency}`">
                  <td class="px-4 py-2">{{ row.name }} <span v-if="row.code" class="text-xs text-neutral-500">({{ row.code }})</span></td>
                  <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                  <td class="px-4 py-2 text-right tabular-nums text-green-700">{{ formatMoney(row.deposits, row.currency) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums text-amber-700">{{ formatMoney(row.withdrawals, row.currency) }}</td>
                  <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ formatMoney(row.balance, row.currency) }}</td>
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
        <div class="overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-2 font-medium">{{ t('admin.savings.customer') }}</th>
                <th class="px-4 py-2 font-medium">{{ t('admin.savings.currency') }}</th>
                <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.savingsKept') }}</th>
                <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.owedNow') }}</th>
                <th class="px-4 py-2 text-right font-medium">{{ t('admin.loanReports.net') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in report.positions" :key="`${row.customer_id}-${row.currency}`">
                <td class="px-4 py-2">{{ row.name }}</td>
                <td class="px-4 py-2 text-neutral-600">{{ row.currency }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ formatMoney(row.savings, row.currency) }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ formatMoney(row.owed, row.currency) }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums" :class="row.net > 0 ? 'text-danger-600' : 'text-green-700'">{{ formatMoney(row.net, row.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>
