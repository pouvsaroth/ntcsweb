<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import TaskProgressOverlay from '@/components/ui/TaskProgressOverlay.vue'
import { useInvoicePrint } from '@/composables/useInvoicePrint'
import { classesService, type SchoolClass } from '@/services/classes'
import { discountCodesService, type AvailableDiscountCode, type DiscountCodeCheck } from '@/services/discountCodes'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { invoicesService, type Invoice } from '@/services/invoices'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
import type { Currency } from '@/services/schoolSettings'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/**
 * Billing > Invoices > Invoice by class: one invoice to the company that pays
 * for a company class, at one total for a period, with the class's student
 * list on it. See CompanyInvoiceService on the backend.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Mirrors CompanyInvoiceService::BILLED_STATUSES — who the invoice counts. */
const BILLED = ['not_started', 'active', 'exam_ready']

const classes = ref<SchoolClass[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const form = reactive({
  class_id: null as number | null,
  invoice_date: today(),
  due_date: '',
  period_from: '',
  period_to: '',
  currency: (auth.tenantDefaultCurrency ?? 'USD') as Currency,
  amount: 0,
  discount_code: '',
  notes: '',
  payment_method: 'CASH' as PaymentMethodValue,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const issuing = ref(false)
const paying = ref(false)
const anySubmitting = computed(() => issuing.value || paying.value)
const { progress: receiptProgress, printReceipt } = useInvoicePrint()

const canTakePayment = computed(() => auth.can('payments.create'))

const classOptions = computed(() =>
  classes.value.map((c) => ({ value: String(c.id), label: `${c.name} — ${c.company?.name ?? ''}` })),
)
const selectedClass = computed(() => classes.value.find((c) => c.id === form.class_id) ?? null)
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const paymentMethodOptions = computed(() =>
  paymentMethods.map((method) => ({
    value: method,
    label: t(`admin.payments.method${method.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')}`),
  })),
)

const students = ref<Enrollment[]>([])
const openInvoice = ref<Invoice | null>(null)
const loadingClass = ref(false)

// Discount: only through a code, picked by name. The amount comes from the
// code on the total and cannot be typed; it is checked again when the total
// or the currency changes.
const codes = ref<AvailableDiscountCode[]>([])
const appliedCode = ref<DiscountCodeCheck | null>(null)
const checkingCode = ref(false)
const codeError = ref<string | null>(null)
const codeOptions = computed(() => [
  { value: '', label: t('admin.discountCodes.noDiscount') },
  ...codes.value.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })),
])
const discount = computed(() => (appliedCode.value && form.discount_code ? appliedCode.value.amount : 0))

let checkRun = 0
async function checkCode() {
  const run = ++checkRun
  appliedCode.value = null
  codeError.value = null
  if (!form.discount_code || form.amount <= 0) return
  checkingCode.value = true
  try {
    const result = await discountCodesService.checkAmount(form.discount_code, form.amount, form.currency)
    if (run === checkRun) appliedCode.value = result
  } catch (error) {
    if (run !== checkRun) return
    form.discount_code = ''
    codeError.value = error instanceof ApiRequestError ? (error.fieldError('discount_code') ?? error.message) : t('admin.discountCodes.checkFailed')
  } finally {
    if (run === checkRun) checkingCode.value = false
  }
}

function pickCode(value: string) {
  form.discount_code = value
  void checkCode()
}

let recheckTimer: ReturnType<typeof setTimeout> | undefined
watch([() => form.amount, () => form.currency], () => {
  if (!form.discount_code) return
  appliedCode.value = null
  clearTimeout(recheckTimer)
  recheckTimer = setTimeout(() => void checkCode(), 400)
})

const total = computed(() => Math.max(0, (form.amount || 0) - discount.value))

// Payment, used only by "Pay and invoice". null = follow the total; the typed text once staff enter their own amount.
const payAmountText = ref<string | null>(null)
const payAmount = computed(() => (payAmountText.value !== null ? Number(payAmountText.value) || 0 : total.value))

async function onClassChange(value: string) {
  form.class_id = value ? Number(value) : null
  students.value = []
  openInvoice.value = null
  errors.value = {}
  generalError.value = null
  if (form.class_id === null) return

  // The class's own dates are the usual period; staff can change them.
  form.period_from = selectedClass.value?.start_date ?? form.period_from
  form.period_to = selectedClass.value?.end_date ?? form.period_to

  loadingClass.value = true
  try {
    const [enrollments, invoices] = await Promise.all([
      enrollmentsService.listAll({ class_id: form.class_id }).catch(() => []),
      invoicesService.list({ page: 1, per_page: 20, filter: { class_id: String(form.class_id) } }),
    ])
    const seen = new Set<number>()
    students.value = enrollments.filter((e) => {
      if (!BILLED.includes(e.status) || !e.student || seen.has(e.student.id)) return false
      seen.add(e.student.id)
      return true
    })
    openInvoice.value = invoices.data.find((inv) => inv.status !== 'CANCELLED' && inv.status !== 'VOID') ?? null
  } finally {
    loadingClass.value = false
  }
}

const canSubmit = computed(
  () => form.class_id !== null && !checkingCode.value && !openInvoice.value && students.value.length > 0 && form.amount > 0 && form.period_from !== '' && form.period_to !== '',
)

/**
 * `issue`: "Issue invoice" — saved unpaid. `pay`: "Pay and invoice" — saved
 * with the payment, then the receipt (A4, like the company invoice) opens in
 * print preview. A print failure still opens the saved invoice.
 */
async function submit(mode: 'issue' | 'pay' = 'issue') {
  if (!canSubmit.value) return
  if (mode === 'pay' && (!canTakePayment.value || payAmount.value <= 0)) return
  const busy = mode === 'pay' ? paying : issuing
  busy.value = true
  errors.value = {}
  generalError.value = null

  try {
    const invoice = await invoicesService.createForCompanyClass({
      class_id: form.class_id!,
      invoice_date: form.invoice_date,
      due_date: form.due_date || null,
      period_from: form.period_from,
      period_to: form.period_to,
      currency: form.currency,
      amount: form.amount,
      discount_code: form.discount_code || null,
      notes: form.notes || null,
      received_amount: mode === 'pay' ? payAmount.value : 0,
      payment_method: mode === 'pay' ? form.payment_method : null,
    })
    if (mode === 'pay') {
      const payment = [...(invoice.payments ?? [])].sort((a, b) => b.id - a.id)[0]
      if (payment) await printReceipt(payment.id, payment.payment_number, 'A4').catch(() => {})
    }
    await router.push(`/admin/invoices/${invoice.id}`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.companyInvoiceSaveFailed')
    }
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  discountCodesService
    .available()
    .then((list) => (codes.value = list))
    .catch(() => (codes.value = []))
  try {
    classes.value = (await classesService.listCompanyClasses()).filter((c) => c.company_id !== null)
    const preset = Number(route.query.class_id)
    if (preset && classes.value.some((c) => c.id === preset)) await onClassChange(String(preset))
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.companyInvoiceSaveFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.invoices.companyInvoiceTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.invoices.companyInvoiceSubtitle') }}</p>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>
    <BaseAlert v-else-if="classes.length === 0" variant="warning">{{ t('admin.invoices.companyInvoiceNoClasses') }}</BaseAlert>

    <form v-else class="grid gap-8 lg:grid-cols-2" @submit.prevent="submit()">
      <div class="space-y-5">
        <BaseSelect
          :model-value="form.class_id !== null ? String(form.class_id) : ''"
          :options="classOptions"
          :placeholder="t('admin.enrollments.selectClass')"
          :label="t('admin.enrollments.class')"
          required
          :error="errors.class_id?.[0]"
          @update:model-value="onClassChange"
        />

        <div v-if="selectedClass" class="rounded-[--radius-card] border border-neutral-200 bg-neutral-50 p-3 text-sm">
          <p class="font-medium text-neutral-800">{{ t('admin.invoices.companyInvoiceBillTo', { company: selectedClass.company?.name ?? '' }) }}</p>
          <BaseSpinner v-if="loadingClass" class="mt-2" />
          <template v-else>
            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.invoices.companyStudentsSection', { count: students.length }) }}</p>
            <ol v-if="students.length" class="mt-1 max-h-60 space-y-0.5 overflow-y-auto text-neutral-700">
              <li v-for="(e, index) in students" :key="e.id">{{ index + 1 }}. {{ e.student?.full_name }} <span class="text-neutral-400">({{ e.student?.student_code }})</span></li>
            </ol>
            <p v-else class="mt-1 text-danger-600">{{ t('admin.invoices.companyInvoiceNoStudents') }}</p>
          </template>
        </div>

        <BaseAlert v-if="openInvoice" variant="warning">
          {{ t('admin.invoices.companyInvoiceAlready', { number: openInvoice.invoice_number }) }}
          <RouterLink :to="`/admin/invoices/${openInvoice.id}`" class="font-medium underline">{{ t('admin.invoices.companyInvoiceOpen') }}</RouterLink>
        </BaseAlert>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.period_from" type="date" required :label="t('admin.invoices.periodFrom')" :error="errors.period_from?.[0]" />
          <BaseInput v-model="form.period_to" type="date" required :label="t('admin.invoices.periodTo')" :error="errors.period_to?.[0]" />
          <BaseInput v-model="form.invoice_date" type="date" required :label="t('admin.invoices.invoiceDate')" :error="errors.invoice_date?.[0]" />
          <BaseInput v-model="form.due_date" type="date" :label="t('admin.invoices.dueDate')" :error="errors.due_date?.[0]" />
        </div>
      </div>

      <div class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect
            :model-value="form.currency"
            :options="currencyOptions"
            :label="t('admin.invoices.companyInvoiceCurrency')"
            :error="errors.currency?.[0]"
            @update:model-value="form.currency = ($event || 'USD') as Currency"
          />
          <BaseInput
            :model-value="String(form.amount)"
            @update:model-value="form.amount = Number($event) || 0"
            type="number"
            min="0"
            step="any"
            required
            :label="`${t('admin.invoices.companyInvoiceAmount')} (${form.currency})`"
            :hint="t('admin.invoices.companyInvoiceAmountHint')"
            :error="errors.amount?.[0]"
          />
          <BaseSelect
            class="sm:col-span-2"
            :model-value="form.discount_code"
            :options="codeOptions"
            :disabled="checkingCode"
            :label="t('admin.discountCodes.useCode')"
            :error="codeError ?? errors.discount_code?.[0] ?? errors.discount?.[0]"
            @update:model-value="pickCode(String($event ?? ''))"
          />
        </div>

        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.invoices.notes') }}</label>
          <textarea
            v-model="form.notes"
            rows="2"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
        </div>

        <dl class="space-y-1 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-sm">
          <div v-if="discount > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.enrollments.discountPrice') }}</dt><dd class="font-semibold text-green-700">− {{ formatMoney(discount, form.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.columnTotal') }}</dt><dd class="font-semibold text-neutral-900">{{ formatMoney(total, form.currency) }}</dd></div>
        </dl>

        <div v-if="canTakePayment">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.invoices.paySection') }}</h2>
          <p class="mb-2 text-xs text-neutral-500">{{ t('admin.invoices.paySectionHint') }}</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput
              :model-value="payAmountText ?? String(total)"
              @update:model-value="payAmountText = $event === '' ? null : $event"
              type="number"
              min="0"
              step="any"
              :label="`${t('admin.invoices.payAmount')} (${form.currency})`"
              :hint="t('admin.invoices.payAmountHint')"
              :error="errors.received_amount?.[0]"
            />
            <BaseSelect
              v-model="form.payment_method"
              :options="paymentMethodOptions"
              :label="t('admin.invoices.paymentMethod')"
              :error="errors.payment_method?.[0]"
            />
          </div>
        </div>

        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

        <div class="flex justify-end gap-3">
          <BaseButton variant="outline" to="/admin/invoices">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" variant="outline" :loading="issuing" :disabled="!canSubmit || (anySubmitting && !issuing)">{{ t('admin.invoices.issueInvoice') }}</BaseButton>
          <BaseButton
            v-if="canTakePayment"
            type="button"
            :loading="paying"
            :disabled="!canSubmit || payAmount <= 0 || (anySubmitting && !paying)"
            @click="submit('pay')"
          >
            {{ t('admin.invoices.payAndInvoice') }}
          </BaseButton>
        </div>
      </div>
    </form>

    <TaskProgressOverlay :percent="receiptProgress.percent.value" :label="receiptProgress.label.value" />
  </div>
</template>
