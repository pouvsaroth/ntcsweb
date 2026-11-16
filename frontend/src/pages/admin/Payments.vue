<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  paymentMethods,
  paymentsService,
  paymentStatuses,
  type Payment,
  type PaymentStatusValue,
} from '@/services/payments'
import { ApiRequestError } from '@/types/api'
import { moneyOrDash } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const { t } = useI18n()
const auth = useAuthStore()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Payment>((query) =>
  paymentsService.list(query),
)

const selectedStatus = ref('')
const selectedMethod = ref('')

function toPascalCase(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<PaymentStatusValue, 'success' | 'warning' | 'danger' | 'neutral'> = {
  COMPLETED: 'success',
  CANCELLED: 'neutral',
  REFUNDED: 'warning',
}

const statusFilterOptions = computed(() => paymentStatuses.map((status) => ({ value: status, label: t(`admin.payments.status${toPascalCase(status)}`) })))
const methodFilterOptions = computed(() => paymentMethods.map((method) => ({ value: method, label: t(`admin.payments.method${toPascalCase(method)}`) })))

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

function onMethodFilterChange(value: string) {
  selectedMethod.value = value
  setFilter('payment_method', value || undefined)
}

const columns = [
  { key: 'payment_number', label: t('admin.payments.columnNumber'), sortable: true },
  { key: 'invoice_number', label: t('admin.payments.columnInvoice') },
  // USD and KHR, at the rate on the payment date; Currency is what was paid in.
  { key: 'amount', label: `${t('admin.payments.columnAmount')} (USD)`, sortable: true, align: 'text-right' },
  { key: 'amount_khr', label: `${t('admin.payments.columnAmount')} (KHR)`, align: 'text-right' },
  { key: 'currency', label: t('admin.payments.columnCurrency') },
  { key: 'payment_method', label: t('admin.payments.columnMethod') },
  { key: 'status', label: t('admin.payments.columnStatus') },
  { key: 'payment_date', label: t('admin.payments.columnDate'), sortable: true },
  { key: 'actions', label: t('admin.payments.columnActions'), align: 'text-right' },
]

// --- Cancel / Refund -----------------------------------------------------

const reasonModalOpen = ref(false)
const reasonModalMode = ref<'cancel' | 'refund'>('cancel')
const reasonModalPayment = ref<Payment | null>(null)
const reasonSubmitting = ref(false)
const reasonError = ref<string | null>(null)

function openCancel(payment: Payment) {
  reasonModalMode.value = 'cancel'
  reasonModalPayment.value = payment
  reasonError.value = null
  reasonModalOpen.value = true
}

function openRefund(payment: Payment) {
  reasonModalMode.value = 'refund'
  reasonModalPayment.value = payment
  reasonError.value = null
  reasonModalOpen.value = true
}

async function confirmReason(reason: string) {
  if (!reasonModalPayment.value) return

  reasonSubmitting.value = true
  reasonError.value = null

  try {
    if (reasonModalMode.value === 'cancel') {
      await paymentsService.cancel(reasonModalPayment.value.id, reason)
    } else {
      await paymentsService.refund(reasonModalPayment.value.id, reason)
    }
    reasonModalOpen.value = false
    await fetch()
  } catch (error) {
    reasonError.value =
      error instanceof ApiRequestError ? error.message : t(reasonModalMode.value === 'cancel' ? 'admin.payments.cancelFailed' : 'admin.payments.refundFailed')
  } finally {
    reasonSubmitting.value = false
  }
}

async function downloadReceipt(payment: Payment) {
  await paymentsService.downloadReceipt(payment.id, payment.payment_number)
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.payments.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.payments.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.payments.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.payments.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
      <BaseSelect
        :model-value="selectedMethod"
        :options="methodFilterOptions"
        :placeholder="t('admin.payments.filterAllMethods')"
        @update:model-value="onMethodFilterChange"
      />
    </div>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.payments.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.payment_number }}</p>
              <p class="text-xs text-neutral-500">
                {{ formatDate(row.payment_date) }} · {{ t(`admin.payments.method${toPascalCase(row.payment_method)}`) }} ·
                <RouterLink :to="`/admin/invoices/${row.invoice_id}`" class="text-primary-700">{{ row.invoice_number ?? row.invoice_id }}</RouterLink>
              </p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.payments.status${toPascalCase(row.status)}`) }}</BaseBadge>
          </div>
          <div class="mt-2 flex justify-between text-sm font-semibold text-neutral-900">
            <span>{{ moneyOrDash(row.amount_usd, 'USD') }}</span>
            <span>{{ moneyOrDash(row.amount_khr, 'KHR') }}</span>
          </div>
          <div class="mt-2 flex flex-wrap gap-3">
            <button type="button" class="text-sm font-medium text-primary-700 hover:text-primary-800" @click="downloadReceipt(row)">
              {{ t('admin.payments.downloadReceipt') }}
            </button>
            <template v-if="row.status === 'COMPLETED'">
              <button v-if="auth.can('payments.cancel')" type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="openRefund(row)">
                {{ t('admin.payments.refundAction') }}
              </button>
              <button v-if="auth.can('payments.cancel')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="openCancel(row)">
                {{ t('admin.payments.cancelAction') }}
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>

    <DataTable
      class="hidden sm:block"
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.payments.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-invoice_number="{ row }">
        <RouterLink :to="`/admin/invoices/${row.invoice_id}`" class="text-primary-700 hover:text-primary-800">
          {{ row.invoice_number ?? row.invoice_id }}
        </RouterLink>
      </template>
      <template #cell-amount="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.amount_usd, 'USD') }}</span></template>
      <template #cell-amount_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.amount_khr, 'KHR') }}</span></template>
      <template #cell-payment_method="{ row }">{{ t(`admin.payments.method${toPascalCase(row.payment_method)}`) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.payments.status${toPascalCase(row.status)}`) }}</BaseBadge>
      </template>
      <template #cell-payment_date="{ row }">{{ formatDate(row.payment_date) }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3">
          <button type="button" class="text-sm font-medium text-primary-700 hover:text-primary-800" @click="downloadReceipt(row)">
            {{ t('admin.payments.downloadReceipt') }}
          </button>
          <template v-if="row.status === 'COMPLETED'">
            <button v-if="auth.can('payments.cancel')" type="button" class="text-sm font-medium text-amber-700 hover:text-amber-800" @click="openRefund(row)">
              {{ t('admin.payments.refundAction') }}
            </button>
            <button v-if="auth.can('payments.cancel')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="openCancel(row)">
              {{ t('admin.payments.cancelAction') }}
            </button>
          </template>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ConfirmReasonModal
      v-model="reasonModalOpen"
      :title="reasonModalMode === 'cancel' ? t('admin.payments.cancelAction') : t('admin.payments.refundAction')"
      :label="reasonModalMode === 'cancel' ? t('admin.payments.cancelReasonLabel') : t('admin.payments.refundReasonLabel')"
      :confirm-label="reasonModalMode === 'cancel' ? t('admin.payments.cancelAction') : t('admin.payments.refundAction')"
      danger
      :submitting="reasonSubmitting"
      :error="reasonError"
      @confirm="confirmReason"
    />
  </div>
</template>
