<script setup lang="ts">
/** Parent portal: the picked child's invoices, with the PDF. Paying stays at the school. */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { apiDownload, apiGet } from '@/services/http'
import type { Invoice } from '@/services/invoices'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const { t, locale } = useI18n()
const invoices = ref<Invoice[]>([])
const loading = ref(true)
const failed = ref(false)
const downloading = ref<number | null>(null)

onMounted(async () => {
  try {
    invoices.value = await apiGet<Invoice[]>('/my-invoices', { params: { per_page: 100 } })
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})

const variant = (s: string) => (s === 'PAID' ? 'success' : s === 'OVERDUE' ? 'danger' : s === 'PARTIALLY_PAID' ? 'warning' : s === 'CANCELLED' || s === 'VOID' ? 'neutral' : 'primary')

async function pdf(inv: Invoice): Promise<void> {
  downloading.value = inv.id
  try {
    await apiDownload(`/my-invoices/${inv.id}/pdf?locale=${locale.value === 'km' ? 'km' : 'en'}`, `${inv.invoice_number}.pdf`)
  } finally {
    downloading.value = null
  }
}
</script>

<template>
  <div>
    <h1 class="mb-4 text-xl font-bold text-neutral-900">{{ t('parentPortal.invoices') }}</h1>
    <div v-if="loading" class="flex justify-center py-12"><BaseSpinner /></div>
    <p v-else-if="failed" class="text-sm text-red-600">{{ t('parentPortal.failed') }}</p>
    <p v-else-if="!invoices.length" class="rounded-[--radius-card] border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500">{{ t('parentPortal.noInvoices') }}</p>
    <div v-else class="space-y-3">
      <div v-for="inv in invoices" :key="inv.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-semibold text-neutral-900">{{ inv.invoice_number }}</p>
            <p class="text-xs text-neutral-500">{{ formatDate(inv.invoice_date) }}<template v-if="inv.due_date"> · {{ t('parentPortal.due') }} {{ formatDate(inv.due_date) }}</template></p>
          </div>
          <BaseBadge :variant="variant(inv.status)">{{ t(`parentPortal.status.${inv.status}`) }}</BaseBadge>
        </div>
        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
          <div><dt class="text-xs text-neutral-500">{{ t('parentPortal.total') }}</dt><dd class="font-medium">{{ formatMoney(inv.total, inv.currency) }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('parentPortal.paid') }}</dt><dd class="font-medium text-green-700">{{ formatMoney(inv.paid_amount, inv.currency) }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('parentPortal.balance') }}</dt><dd class="font-semibold" :class="inv.balance > 0 ? 'text-red-600' : 'text-neutral-700'">{{ formatMoney(inv.balance, inv.currency) }}</dd></div>
        </dl>
        <div class="mt-3 flex justify-end">
          <BaseButton size="sm" variant="outline" :loading="downloading === inv.id" @click="pdf(inv)">{{ t('parentPortal.pdf') }}</BaseButton>
        </div>
      </div>
      <p class="text-xs text-neutral-500">{{ t('parentPortal.payAtSchool') }}</p>
    </div>
  </div>
</template>
