<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import type { InvoiceInput, InvoiceItemInput, PaymentTypeValue } from '@/services/invoices'
import { invoicesService, paymentTypes } from '@/services/invoices'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
import { productsService, type Product } from '@/services/products'
import { studentsService, type Student } from '@/services/students'
import TaskProgressOverlay from '@/components/ui/TaskProgressOverlay.vue'
import { useInvoicePrint } from '@/composables/useInvoicePrint'
import { useTaskProgress } from '@/composables/useTaskProgress'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { useMoney } from '@/composables/useMoney'
import { convertCurrency } from '@/utils/currency'

/** yyyy-MM-dd in the viewer's local time — matches EnrollmentPackageForm's own helper. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const { t } = useI18n()
const { money, schoolCurrency } = useMoney()
const router = useRouter()
const auth = useAuthStore()

// --- Student picker (same search-then-select pattern as EnrollmentPackageForm) --

const studentSearch = ref('')
const studentResults = ref<Student[]>([])
const selectedStudent = ref<Student | null>(null)
let studentSearchDebounce: ReturnType<typeof setTimeout> | undefined

function onStudentSearchInput() {
  clearTimeout(studentSearchDebounce)
  if (!studentSearch.value.trim()) {
    studentResults.value = []
    return
  }
  studentSearchDebounce = setTimeout(async () => {
    const result = await studentsService.list({ search: studentSearch.value })
    studentResults.value = result.data
  }, 350)
}

function selectStudent(student: Student) {
  selectedStudent.value = student
  studentResults.value = []
  studentSearch.value = ''
}

// --- Products/variants -----------------------------------------------------

const products = ref<Product[]>([])
const loadingProducts = ref(true)

const productOptions = computed(() => products.value.map((p) => ({ value: String(p.id), label: `${p.name} — ${money(p.price, p.currency)}` })))

function variantOptions(productId: number | null) {
  const product = products.value.find((p) => p.id === productId)
  return (product?.variants ?? []).map((v) => ({
    value: String(v.id),
    label: v.price_override !== null ? `${v.name} — ${money(v.price_override, product?.currency)}` : v.name,
  }))
}

// --- Line items --------------------------------------------------------

function emptyItem(): InvoiceItemInput {
  return { product_id: null, product_variant_id: null, quantity: 1, unit_price: '', discount: '', description: '' }
}

const form = reactive<InvoiceInput>({
  student_id: null,
  invoice_date: today(),
  due_date: '',
  discount: '',
  tax: '',
  notes: '',
  payment_type: '',
  items: [emptyItem()],
})

function paymentTypeKey(type: PaymentTypeValue): string {
  return type
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const paymentTypeOptions = computed(() => paymentTypes.map((type) => ({ value: type, label: t(`admin.invoices.paymentType${paymentTypeKey(type)}`) })))

function addItem() {
  form.items.push(emptyItem())
}

function removeItem(index: number) {
  form.items.splice(index, 1)
}

function onProductChange(item: InvoiceItemInput, value: string) {
  item.product_id = value ? Number(value) : null
  item.product_variant_id = null
  item.unit_price = ''
}

function onVariantChange(item: InvoiceItemInput, value: string) {
  item.product_variant_id = value ? Number(value) : null
}

/** A per-item preview only — the authoritative total is always computed server-side (see InvoiceService::addItem()). */
function itemPreviewTotal(item: InvoiceItemInput): number {
  if (!item.product_id) return 0
  const product = products.value.find((p) => p.id === item.product_id)
  const variant = product?.variants?.find((v) => v.id === item.product_variant_id)
  // The catalog price is in the product's currency; the invoice is in the school's (the server converts the same way).
  const catalogPrice = convertCurrency(variant?.price_override ?? product?.price ?? 0, product?.currency ?? schoolCurrency(), schoolCurrency(), auth.khrPerUsdRate)
  const unitPrice = item.unit_price.trim() ? Number(item.unit_price) : catalogPrice
  const discount = item.discount.trim() ? Number(item.discount) : 0
  return Math.max(0, item.quantity * unitPrice - discount)
}

const subtotalPreview = computed(() => form.items.reduce((sum, item) => sum + itemPreviewTotal(item), 0))
const totalPreview = computed(() => {
  const discount = form.discount.trim() ? Number(form.discount) : 0
  const tax = form.tax.trim() ? Number(form.tax) : 0
  return Math.max(0, subtotalPreview.value - discount + tax)
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const issuing = ref(false)
const paying = ref(false)
const anySubmitting = computed(() => issuing.value || paying.value)
const saveProgress = useTaskProgress()
const { progress: receiptProgress, printReceipt } = useInvoicePrint()

const canSubmit = computed(() => selectedStudent.value !== null && form.items.every((item) => item.product_id !== null))

// --- Payment, used only by "Pay and invoice" ---------------------------------

const canTakePayment = computed(() => auth.can('payments.create'))
const payMethod = ref<PaymentMethodValue>('CASH')
/** null = follow the total as items change; the typed text once staff enter their own amount. */
const payAmountText = ref<string | null>(null)
const payAmount = computed(() => (payAmountText.value !== null ? Number(payAmountText.value) || 0 : totalPreview.value))
const paymentMethodOptions = computed(() =>
  paymentMethods.map((method) => ({
    value: method,
    label: t(`admin.payments.method${method.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')}`),
  })),
)

/**
 * `issue`: "Issue invoice" — saved unpaid, then opened.
 * `pay`: "Pay and invoice" — saved with the payment in the same request,
 * then the payment's receipt opens in print preview. A print failure still
 * opens the saved invoice, where the receipt can be downloaded again.
 */
async function submit(mode: 'issue' | 'pay' = 'issue') {
  if (!selectedStudent.value) return
  if (mode === 'pay' && (!canTakePayment.value || payAmount.value <= 0)) return

  const busy = mode === 'pay' ? paying : issuing
  busy.value = true
  errors.value = {}
  generalError.value = null
  if (mode === 'pay') saveProgress.creep(t('admin.invoices.progressSaving'), 25)

  try {
    const invoice = await invoicesService.create({
      ...form,
      student_id: selectedStudent.value.id,
      ...(mode === 'pay' ? { received_amount: payAmount.value, payment_method: payMethod.value } : {}),
    })
    if (mode === 'pay') {
      saveProgress.reset()
      const payment = [...(invoice.payments ?? [])].sort((a, b) => b.id - a.id)[0]
      if (payment) await printReceipt(payment.id, payment.payment_number).catch(() => {})
    }
    await router.push(`/admin/invoices/${invoice.id}`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.saveFailed')
    }
  } finally {
    saveProgress.reset()
    busy.value = false
  }
}

onMounted(async () => {
  loadingProducts.value = true
  try {
    // Full records (with variants) are needed for the line-item picker, so
    // each active product is fetched individually — a school's catalog is
    // small, same assumption as books/classes' own listAll().
    const active = await productsService.listAllActive()
    products.value = await Promise.all(active.map((p) => productsService.get(p.id)))
  } finally {
    loadingProducts.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.invoices.createTitle') }}</h1>
    </div>

    <form class="space-y-8" @submit.prevent="submit()">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <section>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.invoices.student') }} <span class="text-danger-600">*</span>
        </label>

        <div v-if="selectedStudent" class="flex items-center justify-between rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm">
          <span>{{ selectedStudent.full_name }} <span class="text-neutral-400">({{ selectedStudent.student_code }})</span></span>
          <button type="button" class="font-medium text-secondary-600 hover:text-secondary-700" @click="selectedStudent = null">
            {{ t('common.change') }}
          </button>
        </div>
        <template v-else>
          <input
            v-model="studentSearch"
            type="search"
            :placeholder="t('admin.invoices.searchStudentPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @input="onStudentSearchInput"
          />
          <ul v-if="studentResults.length > 0" class="mt-1 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li v-for="student in studentResults" :key="student.id">
              <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50" @click="selectStudent(student)">
                {{ student.full_name }} <span class="text-neutral-400">({{ student.student_code }})</span>
              </button>
            </li>
          </ul>
        </template>
        <p v-if="errors.student_id?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.student_id[0] }}</p>
      </section>

      <section class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.invoice_date" type="date" :label="t('admin.invoices.invoiceDate')" :error="errors.invoice_date?.[0]" />
        <BaseInput v-model="form.due_date" type="date" :label="t('admin.invoices.dueDate')" :error="errors.due_date?.[0]" />
      </section>

      <section class="grid grid-cols-2 gap-4">
        <BaseSelect
          :model-value="form.payment_type"
          :options="paymentTypeOptions"
          :placeholder="t('admin.invoices.paymentTypeNone')"
          :label="t('admin.invoices.paymentType')"
          :error="errors.payment_type?.[0]"
          @update:model-value="form.payment_type = $event as PaymentTypeValue | ''"
        />
      </section>

      <section>
        <div class="mb-1 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.invoices.itemsSection') }}</h2>
          <BaseButton type="button" variant="outline" size="sm" :disabled="loadingProducts" @click="addItem">
            {{ t('admin.invoices.addItem') }}
          </BaseButton>
        </div>

        <div v-for="(item, index) in form.items" :key="index" class="mb-3 rounded-lg border border-neutral-200 p-3">
          <div class="grid grid-cols-2 gap-3">
            <BaseSelect
              :model-value="item.product_id !== null ? String(item.product_id) : ''"
              :options="productOptions"
              :disabled="loadingProducts"
              :placeholder="t('admin.invoices.selectProduct')"
              :label="t('admin.invoices.product')"
              required
              :error="errors[`items.${index}.product_id`]?.[0]"
              @update:model-value="onProductChange(item, $event)"
            />
            <BaseSelect
              :model-value="item.product_variant_id !== null ? String(item.product_variant_id) : ''"
              :options="variantOptions(item.product_id)"
              :disabled="!item.product_id || variantOptions(item.product_id).length === 0"
              :placeholder="t('admin.invoices.noVariant')"
              :label="t('admin.invoices.variant')"
              @update:model-value="onVariantChange(item, $event)"
            />
          </div>

          <div class="mt-3 grid grid-cols-4 gap-3">
            <BaseInput
              :model-value="String(item.quantity)"
              type="number"
              :label="t('admin.invoices.quantity')"
              :error="errors[`items.${index}.quantity`]?.[0]"
              @update:model-value="item.quantity = Number($event) || 1"
            />
            <BaseInput
              v-model="item.unit_price"
              type="number"
              :label="t('admin.invoices.unitPrice')"
              :hint="t('admin.invoices.unitPriceHint')"
              :error="errors[`items.${index}.unit_price`]?.[0]"
            />
            <BaseInput v-model="item.discount" type="number" :label="t('admin.invoices.itemDiscount')" :error="errors[`items.${index}.discount`]?.[0]" />
            <BaseInput v-model="item.description" :label="t('admin.invoices.description')" :error="errors[`items.${index}.description`]?.[0]" />
          </div>

          <div class="mt-2 flex items-center justify-between">
            <span class="text-sm text-neutral-500">{{ t('admin.invoices.lineTotal') }}: {{ money(itemPreviewTotal(item)) }}</span>
            <button
              v-if="form.items.length > 1"
              type="button"
              class="text-sm font-medium text-danger-600 hover:text-red-700"
              @click="removeItem(index)"
            >
              {{ t('admin.invoices.removeItem') }}
            </button>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.discount" type="number" :label="t('admin.invoices.discount')" :error="errors.discount?.[0]" />
        <BaseInput v-model="form.tax" type="number" :label="t('admin.invoices.tax')" :error="errors.tax?.[0]" />
      </section>

      <section>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.invoices.notes') }}</label>
        <textarea
          v-model="form.notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </section>

      <div class="rounded-lg bg-neutral-50 p-4 text-sm">
        <div class="flex justify-between"><span class="text-neutral-500">{{ t('admin.invoices.summarySubtotal') }}</span><span>{{ money(subtotalPreview) }}</span></div>
        <div class="mt-1 flex justify-between font-semibold text-neutral-900"><span>{{ t('admin.invoices.summaryTotal') }}</span><span>{{ money(totalPreview) }}</span></div>
        <p class="mt-2 text-xs text-neutral-400">{{ t('admin.invoices.totalPreviewHint') }}</p>
      </div>

      <section v-if="canTakePayment">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.invoices.paySection') }}</h2>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.invoices.paySectionHint') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput
            :model-value="payAmountText ?? totalPreview.toFixed(2)"
            type="number"
            min="0"
            step="any"
            :label="t('admin.invoices.payAmount')"
            :hint="t('admin.invoices.payAmountHint')"
            :error="errors.received_amount?.[0]"
            @update:model-value="payAmountText = $event === '' ? null : $event"
          />
          <BaseSelect
            :model-value="payMethod"
            :options="paymentMethodOptions"
            :label="t('admin.invoices.paymentMethod')"
            :error="errors.payment_method?.[0]"
            @update:model-value="payMethod = ($event || 'CASH') as PaymentMethodValue"
          />
        </div>
      </section>

      <div class="flex flex-wrap gap-3">
        <BaseButton type="submit" variant="outline" :loading="issuing" :disabled="!canSubmit || (anySubmitting && !issuing)">
          {{ t('admin.invoices.issueInvoice') }}
        </BaseButton>
        <BaseButton
          v-if="canTakePayment"
          type="button"
          :loading="paying"
          :disabled="!canSubmit || payAmount <= 0 || (anySubmitting && !paying)"
          @click="submit('pay')"
        >
          {{ t('admin.invoices.payAndInvoice') }}
        </BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/invoices')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>

    <TaskProgressOverlay :percent="saveProgress.percent.value" :label="saveProgress.label.value" />
    <TaskProgressOverlay :percent="receiptProgress.percent.value" :label="receiptProgress.label.value" />
  </div>
</template>
