<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { billingService, type BillingSummary, type PaymentsByMethodRow } from '@/services/billing'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const summary = ref<BillingSummary | null>(null)
const paymentsByMethod = ref<PaymentsByMethodRow[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

function toPascalCase(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const stats = computed(() => {
  if (!summary.value) return []
  return [
    { label: t('admin.billing.statTodaysSales'), value: `$${summary.value.todays_sales.toFixed(2)}` },
    { label: t('admin.billing.statTodaysPayments'), value: `$${summary.value.todays_payments.toFixed(2)}` },
    { label: t('admin.billing.statOutstanding'), value: `$${summary.value.outstanding.toFixed(2)}` },
    { label: t('admin.billing.statOverdue'), value: `$${summary.value.overdue.toFixed(2)}` },
  ]
})

const invoiceCounts = computed(() => {
  if (!summary.value) return []
  const counts = summary.value.invoice_counts
  return [
    { label: t('admin.billing.countTotal'), value: counts.total },
    { label: t('admin.billing.countPaid'), value: counts.paid },
    { label: t('admin.billing.countPartial'), value: counts.partial },
    { label: t('admin.billing.countUnpaid'), value: counts.unpaid },
    { label: t('admin.billing.countOverdue'), value: counts.overdue },
    { label: t('admin.billing.countCancelledOrVoid'), value: counts.cancelled_or_void },
  ]
})

const paymentsByMethodTotal = computed(() => paymentsByMethod.value.reduce((sum, row) => sum + row.total, 0))

onMounted(async () => {
  loading.value = true
  loadError.value = null

  try {
    ;[summary.value, paymentsByMethod.value] = await Promise.all([billingService.summary(), billingService.paymentsByMethod()])
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.billing.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.billing.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.billing.pageSubtitle') }}</p>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="summary">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard v-for="stat in stats" :key="stat.label">
          <p class="text-sm font-medium text-neutral-500">{{ stat.label }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ stat.value }}</p>
        </BaseCard>
      </div>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.billing.invoiceCountsTitle') }}</h2>
          <dl class="space-y-2 text-sm">
            <div v-for="row in invoiceCounts" :key="row.label" class="flex justify-between">
              <dt class="text-neutral-500">{{ row.label }}</dt>
              <dd class="font-medium text-neutral-900">{{ row.value }}</dd>
            </div>
          </dl>
        </BaseCard>

        <BaseCard>
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.billing.paymentsByMethodTitle') }}</h2>
          <p v-if="paymentsByMethod.length === 0" class="text-sm text-neutral-400">{{ t('admin.billing.noData') }}</p>
          <table v-else class="w-full text-left text-sm">
            <thead class="text-neutral-500">
              <tr>
                <th class="pb-2 font-medium">{{ t('admin.billing.columnMethod') }}</th>
                <th class="pb-2 text-right font-medium">{{ t('admin.billing.columnCount') }}</th>
                <th class="pb-2 text-right font-medium">{{ t('admin.billing.columnTotal') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in paymentsByMethod" :key="row.payment_method">
                <td class="py-2 text-neutral-700">{{ t(`admin.payments.method${toPascalCase(row.payment_method)}`) }}</td>
                <td class="py-2 text-right text-neutral-700">{{ row.count }}</td>
                <td class="py-2 text-right text-neutral-700">${{ row.total.toFixed(2) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t border-neutral-200 font-semibold text-neutral-900">
                <td class="pt-2">{{ t('admin.billing.total') }}</td>
                <td class="pt-2 text-right">{{ paymentsByMethod.reduce((sum, r) => sum + r.count, 0) }}</td>
                <td class="pt-2 text-right">${{ paymentsByMethodTotal.toFixed(2) }}</td>
              </tr>
            </tfoot>
          </table>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
