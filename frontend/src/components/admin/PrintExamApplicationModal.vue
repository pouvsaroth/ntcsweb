<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
import { schoolSettingsService } from '@/services/schoolSettings'
import { ApiRequestError } from '@/types/api'
import { printExamApplicationForm } from '@/utils/printExamApplicationForm'

const props = defineProps<{ modelValue: boolean; application: ExamApplication | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

/** yyyy-MM-dd in the viewer's local time. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function methodKey(method: PaymentMethodValue): string {
  return method
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}
const paymentMethodOptions = computed(() => paymentMethods.map((method) => ({ value: method, label: t(`admin.payments.method${methodKey(method)}`) })))

const fee = ref('')
const currency = ref<'USD' | 'KHR'>('USD')
const paymentMethod = ref<PaymentMethodValue>('CASH')
const printDate = ref(today())

const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    generalError.value = null
    paymentMethod.value = 'CASH'
    printDate.value = today()
    fee.value = props.application?.fee_amount ?? ''
    currency.value = (props.application?.fee_currency as 'USD' | 'KHR' | null) ?? 'USD'

    if (!fee.value) {
      // Best-effort default from the school's configured exam fee — silently
      // skipped if the acting user can't view School Settings, since that's
      // a separate permission from exam-applications.update.
      try {
        const settings = await schoolSettingsService.get()
        if (settings.exam_fee_amount) fee.value = settings.exam_fee_amount
        currency.value = settings.default_currency
      } catch {
        // Leave the Fee field blank for manual entry.
      }
    }
  },
)

async function submit() {
  if (!props.application) return
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const updated = await examApplicationsService.recordFeeAndPrint(props.application.id, {
      fee: Number(fee.value),
      currency: currency.value,
      payment_method: paymentMethod.value,
      print_date: printDate.value,
    })

    printExamApplicationForm(updated, { fee: Number(fee.value), currency: currency.value, paymentMethod: paymentMethod.value, printDate: printDate.value })

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.exams.printFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.exams.print')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="application" class="space-y-4">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <p class="text-sm text-neutral-600">{{ application.student.name }} — {{ application.student.student_code }}</p>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <BaseInput v-model="fee" type="number" step="0.01" min="0.01" :label="t('admin.exams.fee')" :error="errors.fee?.[0]" />
        <BaseSelect
          :model-value="currency"
          :options="[{ value: 'USD', label: 'USD' }, { value: 'KHR', label: 'KHR' }]"
          :label="t('admin.exams.currency')"
          @update:model-value="currency = $event as 'USD' | 'KHR'"
        />
      </div>

      <BaseSelect
        v-model="paymentMethod"
        :options="paymentMethodOptions"
        :label="t('admin.invoices.paymentMethod')"
        :error="errors.payment_method?.[0]"
      />

      <BaseInput v-model="printDate" type="date" :label="t('admin.exams.printDate')" :error="errors.print_date?.[0]" />
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!fee" @click="submit">{{ t('admin.exams.printNow') }}</BaseButton>
    </template>
  </BaseModal>
</template>
