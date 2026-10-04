<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import TaskProgressOverlay from '@/components/ui/TaskProgressOverlay.vue'
import { useInvoicePrint } from '@/composables/useInvoicePrint'
import { invoicesService, type Invoice } from '@/services/invoices'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/** yyyy-MM-dd in the viewer's local time — matches EnrollmentPackageForm's own helper. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const props = defineProps<{
  modelValue: boolean
  invoiceId: number
  /** The print dialog's title for "Save and Print". */
  invoiceNumber: string
  /** Pre-fills the amount field — the common case is a full payment. */
  balance: number
  currency: Invoice['currency']
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; recorded: []; printFailed: [message: string] }>()

const { t } = useI18n()

const form = reactive({
  amount: '',
  payment_method: 'CASH' as PaymentMethodValue,
  payment_date: today(),
  reference_number: '',
  notes: '',
  discount_reason: '',
  discount: '',
})

/** Same "Fee To Pay" line EnrollmentPackageForm shows under its discount fields. */
const balanceAfterDiscount = computed(() => Math.max(0, props.balance - (Number(form.discount) || 0)))

// Keeps the amount at "pay the rest in full" as the discount changes — the
// common case — without clobbering an amount the user typed themselves.
watch(
  () => form.discount,
  (_, previous) => {
    const previousBalance = Math.max(0, props.balance - (Number(previous) || 0))
    if (form.amount === '' || Number(form.amount) === Number(previousBalance.toFixed(2))) {
      // 0.00 when the discount covers everything (e.g. 100%) — the backend
      // then settles the invoice by the discount alone.
      form.amount = balanceAfterDiscount.value.toFixed(2)
    }
  },
)

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submittingAndPrinting = ref(false)
const { progress: printProgress, printInvoice } = useInvoicePrint()

const methodOptions = computed(() =>
  paymentMethods.map((method) => ({ value: method, label: t(`admin.payments.method${methodKey(method)}`) })),
)

function methodKey(method: PaymentMethodValue): string {
  return method
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.amount = props.balance > 0 ? props.balance.toFixed(2) : ''
    form.payment_method = 'CASH'
    form.payment_date = today()
    form.reference_number = ''
    form.notes = ''
    form.discount_reason = ''
    form.discount = ''
    errors.value = {}
    generalError.value = null
  },
)

/**
 * `print`: "Save and Print" — the progress bar shows from the click on; once
 * the payment is saved the popup closes and the bar carries on until print
 * preview opens with the updated invoice image (now showing this payment).
 * A failed save keeps the popup open with its errors, as Record Payment
 * does. A failed print happens after the popup closed, so it goes to the
 * page as `printFailed`; the payment is saved regardless, and the page's
 * Download Invoice retries the print.
 *
 * `recorded` (which makes the page reload the invoice) is only emitted once
 * printing is over, so nothing changes underneath the print preview.
 */
async function submit(print = false) {
  const busy = print ? submittingAndPrinting : submitting
  busy.value = true
  errors.value = {}
  generalError.value = null

  if (print) printProgress.creep(t('admin.invoices.progressSaving'), 25)

  try {
    await invoicesService.recordPayment(props.invoiceId, form)
    emit('update:modelValue', false)
    if (print) {
      try {
        await printInvoice(props.invoiceId, props.invoiceNumber)
      } catch (error) {
        emit('printFailed', error instanceof ApiRequestError ? error.message : t('admin.invoices.downloadFailed'))
      }
    }
    emit('recorded')
  } catch (error) {
    printProgress.reset()
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.recordPaymentFailed')
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('admin.invoices.recordPaymentTitle')"
    size="md"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit()">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <LookupSelect
        v-model="form.discount_reason"
        category="DISCOUNT_REASON"
        :label="t('admin.enrollments.discountReason')"
        :error="errors.discount_reason?.[0]"
      />
      <BaseInput
        v-model="form.discount"
        type="number"
        step="0.01"
        :label="`${t('admin.enrollments.discountPrice')} (${currency})`"
        :error="errors.discount?.[0]"
      />
      <div class="flex items-center justify-between border-t border-neutral-200 pt-3 text-sm">
        <span class="font-medium text-neutral-700">{{ t('admin.enrollments.feeToPay') }}</span>
        <span class="font-semibold text-neutral-900">{{ formatMoney(balanceAfterDiscount, currency) }}</span>
      </div>

      <BaseInput
        v-model="form.amount"
        type="number"
        required
        :label="t('admin.invoices.amount')"
        :hint="t('admin.invoices.amountHint', { balance: balanceAfterDiscount.toFixed(2) })"
        :error="errors.amount?.[0]"
      />
      <BaseSelect
        v-model="form.payment_method"
        :options="methodOptions"
        required
        :label="t('admin.invoices.paymentMethod')"
        :error="errors.payment_method?.[0]"
      />
      <BaseInput v-model="form.payment_date" type="date" :label="t('admin.invoices.paymentDate')" :error="errors.payment_date?.[0]" />
      <BaseInput v-model="form.reference_number" :label="t('admin.invoices.referenceNumber')" :error="errors.reference_number?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.invoices.paymentNotes') }}</label>
        <textarea
          v-model="form.notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.notes?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.notes[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton variant="outline" :loading="submittingAndPrinting" :disabled="submitting" @click="submit(true)">{{ t('admin.invoices.saveAndPrint') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="submittingAndPrinting" @click="submit()">{{ t('admin.invoices.recordPaymentAction') }}</BaseButton>
    </template>
  </BaseModal>

  <TaskProgressOverlay :percent="printProgress.percent.value" :label="printProgress.label.value" />
</template>
