<script setup lang="ts">
import QRCode from 'qrcode'
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myExamApplicationsService } from '@/services/myExamApplications'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/**
 * Pay the exam fee by QR (docs/academics/exam-fee-payment.md): the school's
 * KHQR with the fee amount. While open it asks every few seconds whether
 * Bakong saw the payment, like the plan sign-up page; "Pay later" just
 * closes it — the Pay button on the request row opens it again.
 */
const props = defineProps<{ applicationId: number | null }>()
const emit = defineEmits<{ close: []; paid: [] }>()

const { t } = useI18n()

const image = ref<string | null>(null)
const amount = ref<string | null>(null)
const expiresAt = ref<Date | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const paid = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

function stop() {
  if (timer !== null) clearInterval(timer)
  timer = null
}

async function poll() {
  if (props.applicationId === null || paid.value) return
  try {
    const result = await myExamApplicationsService.payment(props.applicationId)
    if (result.paid) {
      paid.value = true
      stop()
      emit('paid')
      return
    }
  } catch {
    // A failed check is just tried again on the next tick.
  }
  // The code expired: make a new one (the server keeps a valid one as is).
  if (expiresAt.value !== null && expiresAt.value.getTime() - Date.now() < 60_000) await load()
}

async function load() {
  if (props.applicationId === null) return
  loading.value = image.value === null
  error.value = null
  try {
    const qr = await myExamApplicationsService.qr(props.applicationId)
    image.value = await QRCode.toDataURL(qr.khqr, { width: 260, margin: 1 })
    amount.value = formatMoney(Number(qr.amount), (qr.currency as 'USD' | 'KHR') ?? 'USD')
    expiresAt.value = new Date(qr.expires_at)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('examFeePayment.loadFailed')
    stop()
  } finally {
    loading.value = false
  }
}

watch(
  () => props.applicationId,
  async (id) => {
    stop()
    image.value = null
    amount.value = null
    expiresAt.value = null
    paid.value = false
    if (id === null) return
    await load()
    if (error.value === null) timer = setInterval(poll, 5000)
  },
  { immediate: true },
)

onBeforeUnmount(stop)
</script>

<template>
  <BaseModal :model-value="applicationId !== null" :title="t('examFeePayment.title')" @update:model-value="emit('close')">
    <div class="flex flex-col items-center gap-3 text-center">
      <BaseSpinner v-if="loading" />
      <BaseAlert v-else-if="error" variant="danger" class="w-full">{{ error }}</BaseAlert>
      <BaseAlert v-else-if="paid" variant="success" class="w-full">{{ t('examFeePayment.paid') }}</BaseAlert>
      <template v-else-if="image">
        <p class="text-sm text-neutral-600">{{ t('examFeePayment.amount') }}: <span class="text-lg font-bold text-primary-800">{{ amount }}</span></p>
        <img :src="image" alt="KHQR" class="h-64 w-64" />
        <p class="text-sm text-neutral-500">{{ t('examFeePayment.hint') }}</p>
        <p class="flex items-center gap-2 text-xs text-neutral-500"><BaseSpinner size="sm" /> {{ t('examFeePayment.waiting') }}</p>
      </template>
    </div>

    <template #footer>
      <BaseButton v-if="paid || error" @click="emit('close')">{{ t('common.close') }}</BaseButton>
      <BaseButton v-else variant="outline" @click="emit('close')">{{ t('examFeePayment.payLater') }}</BaseButton>
    </template>
  </BaseModal>
</template>
