<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { borrowingsService, type BorrowingCurrency, type BorrowingGroupRow, type BorrowingSummary, type CurrencyAmount } from '@/services/borrowings'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Accounting > Borrowings > Reports — still owed, late and due soon; owed by
 * lender and by Borrowed for; principal and interest paid in a period. USD
 * and KHR are always shown apart, never added together.
 */
const { t } = useI18n()

const money = (value: number, currency: BorrowingCurrency) => formatMoney(value, currency)
const amounts = (list: CurrencyAmount[]) => (list.length ? list.map((a) => money(a.amount, a.currency)).join(' · ') : '—')

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), 0, 1)))
const to = ref(iso(now))

const summary = ref<BorrowingSummary | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    summary.value = await borrowingsService.summary({ from: from.value || undefined, to: to.value || undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.loadFailed')
  } finally {
    loading.value = false
  }
}
watch([from, to], load)
onMounted(load)

const groups = (s: BorrowingSummary) => [
  { key: 'lender', title: t('admin.borrowings.byLender'), rows: s.by_lender as BorrowingGroupRow[] },
  { key: 'for', title: t('admin.borrowings.byBorrowedFor'), rows: s.by_borrowed_for as BorrowingGroupRow[] },
]
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !summary" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-if="summary">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.borrowings.owedNow') }}</p>
          <p class="mt-1 text-2xl font-bold text-neutral-900">{{ amounts(summary.owed) }}</p>
        </BaseCard>
        <BaseCard>
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.borrowings.lateNow') }}</p>
            <BaseBadge v-if="summary.late_loans" variant="danger">{{ t('admin.borrowings.lateCount', { count: summary.late_loans }) }}</BaseBadge>
          </div>
          <p class="mt-1 text-2xl font-bold" :class="summary.late.length ? 'text-danger-700' : 'text-neutral-900'">{{ amounts(summary.late) }}</p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.borrowings.dueIn30') }}</p>
          <p class="mt-1 text-2xl font-bold text-neutral-900">{{ amounts(summary.due_soon) }}</p>
          <p v-if="summary.next_payment" class="mt-1 text-xs text-neutral-500">
            {{ t('admin.borrowings.nextDue', { date: formatDate(summary.next_payment.due_on) }) }} · {{ summary.next_payment.lender }}
          </p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.borrowings.activeLoans') }}</p>
          <p class="mt-1 text-2xl font-bold text-neutral-900">{{ summary.active_loans }}</p>
        </BaseCard>
      </div>

      <section v-for="group in groups(summary)" :key="group.key" class="mt-8">
        <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ group.title }}</h2>
        <p v-if="group.rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.borrowings.noData') }}</p>
        <template v-else>
          <!-- Cards on small screens (below sm) instead of the table. -->
          <div class="space-y-2 sm:hidden">
            <div v-for="row in group.rows" :key="`${row.id}-${row.currency}`" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
              <div class="flex items-start justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name ?? '—' }}</p>
                <p class="font-semibold text-neutral-900">{{ money(row.outstanding, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.borrowings.borrowed') }} {{ money(row.borrowed, row.currency) }} · {{ t('admin.borrowings.paidBack') }} {{ money(row.paid_principal, row.currency) }} ·
                {{ t('admin.borrowings.interest') }} {{ money(row.paid_interest, row.currency) }}
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-3 font-medium">{{ group.key === 'lender' ? t('admin.borrowings.lender') : t('admin.borrowings.borrowedFor') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.loansCol') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.borrowed') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.paidBack') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.interestPaid') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.outstanding') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in group.rows" :key="`${row.id}-${row.currency}`">
                  <td class="px-4 py-3 font-medium text-neutral-900">{{ row.name ?? '—' }} <span class="text-xs font-normal text-neutral-400">{{ row.currency }}</span></td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ row.loans }}</td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ money(row.borrowed, row.currency) }}</td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ money(row.paid_principal, row.currency) }}</td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ money(row.paid_interest, row.currency) }}</td>
                  <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.outstanding, row.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <section class="mt-8">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('admin.borrowings.interestPaidTitle') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('admin.borrowings.interestPaidHint') }}</p>
        <div class="mb-4 grid max-w-md grid-cols-2 gap-3">
          <BaseInput v-model="from" type="date" :label="t('admin.borrowings.from')" />
          <BaseInput v-model="to" type="date" :label="t('admin.borrowings.to')" />
        </div>
        <p v-if="summary.paid.rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.borrowings.noData') }}</p>
        <template v-else>
          <div class="space-y-2 sm:hidden">
            <div v-for="row in summary.paid.rows" :key="`${row.id}-${row.currency}`" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
              <div class="flex items-start justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name ?? '—' }}</p>
                <p class="font-semibold text-neutral-900">{{ money(row.principal + row.interest, row.currency) }}</p>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('admin.borrowings.principal') }} {{ money(row.principal, row.currency) }} · {{ t('admin.borrowings.interest') }} {{ money(row.interest, row.currency) }} ·
                {{ t('admin.borrowings.paymentsCount') }} {{ row.payments }}
              </p>
            </div>
          </div>
          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.borrowedFor') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.paymentsCount') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.principal') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.payTotal') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in summary.paid.rows" :key="`${row.id}-${row.currency}`">
                  <td class="px-4 py-3 font-medium text-neutral-900">{{ row.name ?? '—' }} <span class="text-xs font-normal text-neutral-400">{{ row.currency }}</span></td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ row.payments }}</td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ money(row.principal, row.currency) }}</td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ money(row.interest, row.currency) }}</td>
                  <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.principal + row.interest, row.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>
