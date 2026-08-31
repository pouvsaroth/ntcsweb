<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { invoicesService } from '@/services/invoices'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
import { ApiRequestError } from '@/types/api'

/** yyyy-MM-dd in the viewer's local time — matches EnrollmentForm's own helper. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const props = defineProps<{
  modelValue: boolean
  invoiceId: number
  /** Pre-fills the amount field — the common case is a full payment. */
  balance: number
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; recorded: [] }>()

const { t } = useI18n()

const form = reactive({
  amount: '',
  payment_method: 'CASH' as PaymentMethodValue,
  payment_date: today(),
  reference_number: '',
  notes: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

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
    errors.value = {}
    generalError.value = null
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await invoicesService.recordPayment(props.invoiceId, form)
    emit('recorded')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.recordPaymentFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('admin.invoices.recordPaymentTitle')"
    size="sm"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput
        v-model="form.amount"
        type="number"
        required
        :label="t('admin.invoices.amount')"
        :hint="t('admin.invoices.amountHint', { balance: balance.toFixed(2) })"
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
      <BaseButton :loading="submitting" @click="submit">{{ t('admin.invoices.recordPaymentAction') }}</BaseButton>
    </template>
  </BaseModal>
</template>
