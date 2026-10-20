<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

import { paymentsApi, type PaymentMethod, type PaymentQuote, type SchoolPaymentRow } from '../api'

/** Record money a school already paid: periods, amount (defaults to price × periods), method, reference. */
const props = defineProps<{ modelValue: boolean; schoolId: number; productId?: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; recorded: [payment: SchoolPaymentRow] }>()

const { t } = useI18n()
const METHODS: PaymentMethod[] = ['cash', 'bank', 'khqr', 'cheque', 'other']

const today = () => new Date().toISOString().slice(0, 10)
const form = ref({ periods: '1', amount: '', method: 'cash' as PaymentMethod, reference: '', paid_on: today(), notes: '' })
const quote = ref<PaymentQuote | null>(null)
const amountTouched = ref(false)
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)

const methodOptions = computed(() => METHODS.map((m) => ({ value: m, label: t(`bo.billing.payments.methods.${m}`) })))

async function refreshQuote(): Promise<void> {
  const periods = Math.max(1, Number(form.value.periods) || 1)
  try {
    quote.value = await paymentsApi.quote(props.schoolId, periods, props.productId)
    if (!amountTouched.value) form.value.amount = quote.value.amount
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    error.value = null
    amountTouched.value = false
    form.value = { periods: '1', amount: '', method: 'cash', reference: '', paid_on: today(), notes: '' }
    void refreshQuote()
  },
)
watch(() => form.value.periods, () => props.modelValue && void refreshQuote())

function onAmount(value: string): void {
  amountTouched.value = true
  form.value.amount = value
}

async function save(): Promise<void> {
  busy.value = true
  error.value = null
  const f = form.value
  try {
    const payment = await paymentsApi.record(props.schoolId, {
      product_id: props.productId ?? null,
      periods: Number(f.periods),
      amount: f.amount === '' ? null : f.amount,
      method: f.method,
      reference: f.reference.trim() || null,
      paid_on: f.paid_on || null,
      notes: f.notes.trim() || null,
    })
    emit('recorded', payment)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.billing.payments.record')" size="md" @update:model-value="emit('update:modelValue', $event)">
    <form id="bo-payment" class="space-y-4" @submit.prevent="save">
      <p class="text-sm text-neutral-600">{{ t('bo.billing.payments.recordHint') }}</p>
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.fieldError('subscription') ?? error.message }}</BaseAlert>

      <div v-if="quote" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        <p class="font-medium">{{ quote.plan }}</p>
        <p v-if="quote.covered_from && quote.covered_to">
          {{ t('bo.billing.payments.covers', { from: formatDate(quote.covered_from), to: formatDate(quote.covered_to) }) }}
        </p>
        <p v-else class="text-neutral-500">{{ t('bo.billing.payments.noEnd') }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput
          v-model="form.periods"
          type="number"
          :label="t('bo.billing.payments.periods')"
          :hint="t('bo.billing.payments.periodsHint')"
          :error="error?.fieldError('periods')"
          required
        />
        <BaseInput
          :model-value="form.amount"
          type="number"
          :label="`${t('bo.billing.payments.amount')}${quote ? ` (${quote.currency})` : ''}`"
          :hint="t('bo.billing.payments.amountHint')"
          :error="error?.fieldError('amount')"
          @update:model-value="onAmount"
        />
        <BaseSelect v-model="form.method" :options="methodOptions" :label="t('bo.billing.payments.method')" :error="error?.fieldError('method')" />
        <BaseInput v-model="form.paid_on" type="date" :label="t('bo.billing.payments.paidOn')" :error="error?.fieldError('paid_on')" required />
      </div>
      <BaseInput
        v-model="form.reference"
        :label="t('bo.billing.payments.reference')"
        :hint="t('bo.billing.payments.referenceHint')"
        :error="error?.fieldError('reference')"
      />
      <BaseInput v-model="form.notes" :label="t('bo.billing.payments.notes')" :error="error?.fieldError('notes')" />
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-payment" :loading="busy">{{ t('bo.billing.payments.record') }}</BaseButton>
    </template>
  </BaseModal>
</template>
