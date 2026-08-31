<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import RecordPaymentModal from '@/components/admin/RecordPaymentModal.vue'
import SendInvoiceModal from '@/components/admin/SendInvoiceModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  invoicesService,
  isInvoiceClosed,
  type Invoice,
  type InvoiceStatusValue,
  type NotificationLog,
} from '@/services/invoices'
import { paymentsService, type Payment, type PaymentStatusValue } from '@/services/payments'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const invoiceId = computed(() => Number(route.params.id))

const invoice = ref<Invoice | null>(null)
const notifications = ref<NotificationLog[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)

function statusKey(status: InvoiceStatusValue): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

function toPascalCase(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<InvoiceStatusValue, 'success' | 'warning' | 'danger' | 'neutral' | 'primary'> = {
  DRAFT: 'neutral',
  ISSUED: 'primary',
  PARTIALLY_PAID: 'warning',
  PAID: 'success',
  OVERDUE: 'danger',
  CANCELLED: 'neutral',
  VOID: 'neutral',
}

const paymentStatusVariant: Record<PaymentStatusValue, 'success' | 'warning' | 'danger' | 'neutral'> = {
  COMPLETED: 'success',
  CANCELLED: 'neutral',
  REFUNDED: 'warning',
}

const notificationStatusVariant: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
  PENDING: 'warning',
  SENT: 'success',
  FAILED: 'danger',
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—'
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    invoice.value = await invoicesService.get(invoiceId.value)
    notifications.value = await invoicesService.notifications(invoiceId.value)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- Cancel / Void -------------------------------------------------------

const reasonModalOpen = ref(false)
const reasonModalMode = ref<'cancel' | 'void'>('cancel')
const reasonSubmitting = ref(false)
const reasonError = ref<string | null>(null)

function openCancel() {
  reasonModalMode.value = 'cancel'
  reasonError.value = null
  reasonModalOpen.value = true
}

function openVoid() {
  reasonModalMode.value = 'void'
  reasonError.value = null
  reasonModalOpen.value = true
}

async function confirmReason(reason: string) {
  reasonSubmitting.value = true
  reasonError.value = null

  try {
    if (reasonModalMode.value === 'cancel') {
      invoice.value = await invoicesService.cancel(invoiceId.value, reason)
    } else {
      invoice.value = await invoicesService.void(invoiceId.value, reason)
    }
    reasonModalOpen.value = false
  } catch (error) {
    reasonError.value =
      error instanceof ApiRequestError ? error.message : t(reasonModalMode.value === 'cancel' ? 'admin.invoices.cancelFailed' : 'admin.invoices.voidFailed')
  } finally {
    reasonSubmitting.value = false
  }
}

// --- Send / Download / Record payment ------------------------------------

const sendModalOpen = ref(false)
const paymentModalOpen = ref(false)

async function downloadPdf() {
  if (!invoice.value) return
  actionError.value = null
  try {
    await invoicesService.downloadPdf(invoice.value.id, invoice.value.invoice_number)
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.downloadFailed')
  }
}

async function downloadReceipt(payment: Payment) {
  actionError.value = null
  try {
    await paymentsService.downloadReceipt(payment.id, payment.payment_number)
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.downloadFailed')
  }
}

async function cancelPayment(payment: Payment) {
  const reason = window.prompt(t('admin.payments.cancelReasonLabel'))
  if (!reason || !reason.trim()) return

  actionError.value = null
  try {
    await paymentsService.cancel(payment.id, reason.trim())
    await load()
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.payments.cancelFailed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="invoice">
      <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold text-neutral-900">{{ invoice.invoice_number }}</h1>
            <BaseBadge :variant="statusVariant[invoice.status]">{{ t(`admin.invoices.status${statusKey(invoice.status)}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-500">
            {{ invoice.student?.name }} <span v-if="invoice.student" class="text-neutral-400">({{ invoice.student.student_code }})</span>
          </p>
        </div>
        <BaseButton variant="outline" size="sm" @click="router.push('/admin/invoices')">{{ t('admin.invoices.backToList') }}</BaseButton>
      </div>

      <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

      <div class="mb-6 flex flex-wrap gap-2">
        <BaseButton size="sm" variant="outline" @click="downloadPdf">{{ t('admin.invoices.downloadPdf') }}</BaseButton>
        <BaseButton size="sm" variant="outline" @click="sendModalOpen = true">{{ t('admin.invoices.sendAction') }}</BaseButton>
        <BaseButton
          v-if="!isInvoiceClosed(invoice.status) && invoice.balance > 0"
          size="sm"
          @click="paymentModalOpen = true"
        >
          {{ t('admin.invoices.recordPaymentAction') }}
        </BaseButton>
        <BaseButton v-if="!isInvoiceClosed(invoice.status)" size="sm" variant="outline" @click="openVoid">
          {{ t('admin.invoices.voidAction') }}
        </BaseButton>
        <BaseButton v-if="!isInvoiceClosed(invoice.status)" size="sm" variant="danger" @click="openCancel">
          {{ t('admin.invoices.cancelAction') }}
        </BaseButton>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.invoices.itemsSection') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-neutral-200">
              <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-neutral-600">
                  <tr>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.product') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.invoices.quantity') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.invoices.unitPrice') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.invoices.itemDiscount') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.invoices.columnTotal') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="item in invoice.items ?? []" :key="item.id">
                    <td class="px-3 py-2">
                      <p class="text-neutral-800">{{ item.description ?? item.product_name }}</p>
                      <p v-if="item.variant_name" class="text-xs text-neutral-500">{{ item.variant_name }}</p>
                    </td>
                    <td class="px-3 py-2 text-right text-neutral-700">{{ item.quantity }}</td>
                    <td class="px-3 py-2 text-right text-neutral-700">${{ item.unit_price.toFixed(2) }}</td>
                    <td class="px-3 py-2 text-right text-neutral-700">${{ item.discount.toFixed(2) }}</td>
                    <td class="px-3 py-2 text-right font-medium text-neutral-900">${{ item.total.toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="invoice.notes" class="mt-3 rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700">{{ invoice.notes }}</p>

            <div v-if="invoice.cancellation_reason" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">
              <p class="font-medium">{{ t('admin.invoices.cancelledReasonLabel') }}</p>
              <p>{{ invoice.cancellation_reason }}</p>
              <p class="mt-1 text-xs text-red-500">{{ invoice.cancelled_by }} — {{ formatDateTime(invoice.cancelled_at) }}</p>
            </div>
          </section>

          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.invoices.paymentsSection') }}</h2>
            <p v-if="!invoice.payments || invoice.payments.length === 0" class="text-sm text-neutral-400">
              {{ t('admin.invoices.noPayments') }}
            </p>
            <div v-else class="overflow-x-auto rounded-lg border border-neutral-200">
              <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-neutral-600">
                  <tr>
                    <th class="px-3 py-2 font-medium">{{ t('admin.payments.columnNumber') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.payments.columnAmount') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.payments.columnMethod') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.payments.columnStatus') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.payments.columnDate') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.payments.columnActions') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="payment in invoice.payments" :key="payment.id">
                    <td class="px-3 py-2 text-neutral-800">{{ payment.payment_number }}</td>
                    <td class="px-3 py-2 text-right text-neutral-700">${{ payment.amount.toFixed(2) }}</td>
                    <td class="px-3 py-2 text-neutral-700">{{ t(`admin.payments.method${toPascalCase(payment.payment_method)}`) }}</td>
                    <td class="px-3 py-2">
                      <BaseBadge :variant="paymentStatusVariant[payment.status]">{{ t(`admin.payments.status${toPascalCase(payment.status)}`) }}</BaseBadge>
                    </td>
                    <td class="px-3 py-2 text-neutral-700">{{ formatDate(payment.payment_date) }}</td>
                    <td class="px-3 py-2 text-right">
                      <div class="flex justify-end gap-3">
                        <button type="button" class="font-medium text-primary-700 hover:text-primary-800" @click="downloadReceipt(payment)">
                          {{ t('admin.payments.downloadReceipt') }}
                        </button>
                        <button
                          v-if="payment.status === 'COMPLETED'"
                          type="button"
                          class="font-medium text-danger-600 hover:text-red-700"
                          @click="cancelPayment(payment)"
                        >
                          {{ t('admin.payments.cancelAction') }}
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.invoices.notificationsSection') }}</h2>
            <p v-if="notifications.length === 0" class="text-sm text-neutral-400">{{ t('admin.invoices.noNotifications') }}</p>
            <div v-else class="overflow-x-auto rounded-lg border border-neutral-200">
              <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-neutral-600">
                  <tr>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.columnChannel') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.columnRecipient') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.columnNotifStatus') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.columnSentAt') }}</th>
                    <th class="px-3 py-2 font-medium">{{ t('admin.invoices.columnError') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="log in notifications" :key="log.id">
                    <td class="px-3 py-2 text-neutral-700">{{ log.channel }}</td>
                    <td class="px-3 py-2 text-neutral-700">{{ log.recipient }}</td>
                    <td class="px-3 py-2">
                      <BaseBadge :variant="notificationStatusVariant[log.status] ?? 'neutral'">{{ log.status }}</BaseBadge>
                    </td>
                    <td class="px-3 py-2 text-neutral-700">{{ formatDateTime(log.sent_at) }}</td>
                    <td class="px-3 py-2 text-neutral-500">{{ log.error_message ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="space-y-6">
          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.invoices.summaryTitle') }}</h2>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.summarySubtotal') }}</dt><dd>${{ invoice.subtotal.toFixed(2) }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.summaryDiscount') }}</dt><dd>-${{ invoice.discount.toFixed(2) }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.summaryTax') }}</dt><dd>${{ invoice.tax.toFixed(2) }}</dd></div>
              <div class="flex justify-between border-t border-neutral-100 pt-2 font-semibold text-neutral-900"><dt>{{ t('admin.invoices.summaryTotal') }}</dt><dd>${{ invoice.total.toFixed(2) }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.summaryPaid') }}</dt><dd>${{ invoice.paid_amount.toFixed(2) }}</dd></div>
              <div class="flex justify-between font-semibold" :class="invoice.balance > 0 ? 'text-danger-600' : 'text-secondary-700'">
                <dt>{{ t('admin.invoices.summaryBalance') }}</dt><dd>${{ invoice.balance.toFixed(2) }}</dd>
              </div>
            </dl>
          </section>

          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.invoices.detailsTitle') }}</h2>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.invoiceDate') }}</dt><dd>{{ formatDate(invoice.invoice_date) }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.dueDate') }}</dt><dd>{{ formatDate(invoice.due_date) }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.createdBy') }}</dt><dd>{{ invoice.created_by ?? '—' }}</dd></div>
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.columnCreatedAt') }}</dt><dd>{{ formatDateTime(invoice.created_at) }}</dd></div>
            </dl>
          </section>
        </div>
      </div>

      <ConfirmReasonModal
        v-model="reasonModalOpen"
        :title="reasonModalMode === 'cancel' ? t('admin.invoices.cancelAction') : t('admin.invoices.voidAction')"
        :label="reasonModalMode === 'cancel' ? t('admin.invoices.cancelReasonLabel') : t('admin.invoices.voidReasonLabel')"
        :confirm-label="reasonModalMode === 'cancel' ? t('admin.invoices.cancelAction') : t('admin.invoices.voidAction')"
        danger
        :submitting="reasonSubmitting"
        :error="reasonError"
        @confirm="confirmReason"
      />

      <SendInvoiceModal v-model="sendModalOpen" :invoice-id="invoice.id" />

      <RecordPaymentModal v-model="paymentModalOpen" :invoice-id="invoice.id" :balance="invoice.balance" @recorded="load" />
    </template>
  </div>
</template>
