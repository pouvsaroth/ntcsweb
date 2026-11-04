<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { classesService, type SchoolClass } from '@/services/classes'
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
  discount: 0,
  discount_reason: '',
  notes: '',
  received_amount: 0,
  payment_method: 'CASH' as PaymentMethodValue,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

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

const total = computed(() => Math.max(0, (form.amount || 0) - (form.discount || 0)))
const paid = computed(() => Math.min(form.received_amount || 0, total.value))
const balance = computed(() => Math.max(0, total.value - paid.value))

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
      enrollmentsService.listAll({ class_id: form.class_id }),
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
  () => form.class_id !== null && !openInvoice.value && students.value.length > 0 && form.amount > 0 && form.period_from !== '' && form.period_to !== '',
)

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
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
      discount: form.discount || 0,
      discount_reason: form.discount_reason || null,
      notes: form.notes || null,
      received_amount: canTakePayment.value ? form.received_amount || 0 : 0,
      payment_method: canTakePayment.value && form.received_amount > 0 ? form.payment_method : null,
    })
    await router.push(`/admin/invoices/${invoice.id}`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.companyInvoiceSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
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

    <form v-else class="grid gap-8 lg:grid-cols-2" @submit.prevent="submit">
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
          <BaseInput :model-value="String(form.discount)"
            @update:model-value="form.discount = Number($event) || 0" type="number" min="0" step="any" :label="`${t('admin.enrollments.discountPrice')} (${form.currency})`" :error="errors.discount?.[0]" />
          <BaseInput v-model="form.discount_reason" :label="t('admin.enrollments.discountReason')" :error="errors.discount_reason?.[0]" />
        </div>

        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.invoices.notes') }}</label>
          <textarea
            v-model="form.notes"
            rows="2"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
        </div>

        <div v-if="canTakePayment" class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            :model-value="String(form.received_amount)"
            @update:model-value="form.received_amount = Number($event) || 0"
            type="number"
            min="0"
            step="any"
            :label="`${t('admin.enrollments.receivedMoney')} (${form.currency})`"
            :hint="t('admin.invoices.companyInvoiceReceivedHint')"
            :error="errors.received_amount?.[0]"
          />
          <BaseSelect
            v-if="form.received_amount > 0"
            v-model="form.payment_method"
            :options="paymentMethodOptions"
            :label="t('admin.invoices.paymentMethod')"
            :error="errors.payment_method?.[0]"
          />
        </div>

        <dl class="space-y-1 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.columnTotal') }}</dt><dd class="font-semibold text-neutral-900">{{ formatMoney(total, form.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.companyInvoicePaid') }}</dt><dd>{{ formatMoney(paid, form.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.invoices.columnBalance') }}</dt><dd>{{ formatMoney(balance, form.currency) }}</dd></div>
        </dl>

        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

        <div class="flex justify-end gap-3">
          <BaseButton variant="outline" to="/admin/invoices">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" :loading="submitting" :disabled="!canSubmit">{{ t('admin.invoices.companyInvoiceSave') }}</BaseButton>
        </div>
      </div>
    </form>
  </div>
</template>
