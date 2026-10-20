<script setup lang="ts">
import QRCode from 'qrcode'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { signupService, type SignupState } from '@/services/signup'
import { formatMoney } from '@/utils/currency'

/**
 * After the sign-up form (/signup/{token}, docs/backoffice/stage-8-signup.md):
 * the school being set up, then "ready — sign in". A paid plan waits here for
 * its payment (8c): the KHQR for the exact amount, a countdown, and a check
 * every few seconds — Bakong is asked by our server, never from here.
 */
const { t, te } = useI18n()
const route = useRoute()

const state = ref<SignupState | null>(null)
/** Not the school system: "organization" words (signupPage.org.*) where there are any. */
function w(key: string, values?: Record<string, unknown>): string {
  const orgKey = key.replace('signupPage.', 'signupPage.org.')
  const org = !!state.value?.system && state.value.system.code !== 'school'
  return org && te(orgKey) ? t(orgKey, values ?? {}) : t(key, values ?? {})
}
const missing = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

async function load(): Promise<void> {
  try {
    state.value = await signupService.show(String(route.params.token))
  } catch {
    missing.value = state.value === null
  }
  const s = state.value
  const waiting = s && (s.school_status === 'provisioning' || (s.status === 'paid' && !s.school_status) || (s.status === 'pending_payment' && s.khqr))
  if (waiting) timer = setTimeout(load, s.status === 'pending_payment' ? 5000 : 3000)
}

// The QR picture, and how long the code is still good for.
const qrImage = ref<string | null>(null)
watch(
  () => state.value?.khqr,
  async (code) => {
    qrImage.value = code ? await QRCode.toDataURL(code, { width: 280, margin: 1 }) : null
  },
)
const now = ref(Date.now())
const clock = setInterval(() => (now.value = Date.now()), 1000)
const secondsLeft = computed(() => (state.value?.expires_at ? Math.max(0, Math.floor((Date.parse(state.value.expires_at) - now.value) / 1000)) : 0))
const countdown = computed(() => `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`)

onMounted(load)
onBeforeUnmount(() => {
  clearTimeout(timer)
  clearInterval(clock)
})

const view = computed(() => {
  const s = state.value
  if (!s) return null
  if (s.school_status === 'active') return 'ready'
  if (s.school_status === 'failed' || s.status === 'failed') return 'failed'
  if (s.school_status === 'provisioning' || s.status === 'paid') return 'building'
  if (s.status === 'expired') return 'expired'
  return 'pay'
})
const amount = computed(() => (state.value ? formatMoney(Number(state.value.amount), state.value.currency).replace(/\.00$/, '') : ''))
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 pt-16 pb-12 sm:px-6 sm:py-14 lg:px-8">
    <div class="mx-auto max-w-xl">
      <div v-if="!state && !missing" class="flex justify-center py-16"><BaseSpinner /></div>

      <div v-else-if="missing" class="rounded-2xl border border-neutral-200 bg-white p-6 text-center shadow-sm">
        <p class="text-neutral-700">{{ w('signupPage.notFound') }}</p>
        <BaseButton to="/plans" class="mt-4">{{ w('signupPage.backToPlans') }}</BaseButton>
      </div>

      <div v-else-if="state" class="rounded-2xl border border-neutral-200 bg-white p-6 text-center shadow-sm sm:p-8" aria-live="polite">
        <template v-if="view === 'building'">
          <BaseSpinner class="mx-auto" />
          <h1 class="mt-4 text-xl font-bold text-neutral-900">{{ w('signupPage.building') }}</h1>
          <p class="mt-2 text-sm text-neutral-600">{{ w('signupPage.buildingText') }}</p>
        </template>

        <template v-else-if="view === 'ready'">
          <p class="text-5xl" aria-hidden="true">🎉</p>
          <h1 class="mt-3 text-xl font-bold text-neutral-900">{{ w('signupPage.ready', { name: state.school_name }) }}</h1>
          <p class="mt-2 text-sm text-neutral-600">{{ w('signupPage.readyText') }}</p>
          <dl class="mx-auto mt-4 max-w-xs space-y-1 rounded-xl bg-neutral-50 p-4 text-left text-sm">
            <div class="flex justify-between gap-3">
              <dt class="text-neutral-500">{{ w('signupPage.slug') }}</dt>
              <dd class="font-mono font-semibold text-neutral-900">{{ state.slug }}</dd>
            </div>
            <div class="flex justify-between gap-3">
              <dt class="text-neutral-500">{{ w('signupPage.email') }}</dt>
              <dd class="break-all text-neutral-900">{{ state.owner_email }}</dd>
            </div>
          </dl>
          <p v-if="state.receipt_number" class="mt-3 text-xs text-neutral-500">{{ w('signupPage.paidReceipt', { n: state.receipt_number }) }}</p>
          <BaseButton v-if="state.sign_in_url" :href="state.sign_in_url" class="mt-5">{{ w('signupPage.signIn') }}</BaseButton>
        </template>

        <template v-else-if="view === 'failed'">
          <h1 class="text-xl font-bold text-neutral-900">{{ w('signupPage.failedTitle') }}</h1>
          <p class="mt-2 text-sm text-neutral-600">{{ state.paid_at ? w('signupPage.failedPaidText') : w('signupPage.failedText') }}</p>
          <RouterLink to="/contact" class="mt-4 inline-block text-sm font-medium text-secondary-700 hover:underline">{{ t('servicesPage.contactUs') }}</RouterLink>
        </template>

        <template v-else-if="view === 'expired'">
          <h1 class="text-xl font-bold text-neutral-900">{{ w('signupPage.expired') }}</h1>
          <p class="mt-2 text-sm text-neutral-600">{{ w('signupPage.expiredText') }}</p>
          <BaseButton to="/plans" class="mt-4">{{ w('signupPage.backToPlans') }}</BaseButton>
        </template>

        <!-- Pay: the KHQR for this amount. -->
        <template v-else-if="state.khqr">
          <h1 class="text-xl font-bold text-neutral-900">{{ w('signupPage.payTitle') }}</h1>
          <p class="mt-1 text-sm text-neutral-600">{{ w('signupPage.payScan') }}</p>
          <div class="mx-auto mt-4 w-fit rounded-2xl border-2 border-red-600 bg-white p-3">
            <p class="mb-2 rounded bg-red-600 py-1 text-sm font-bold tracking-wide text-white">KHQR</p>
            <img v-if="qrImage" :src="qrImage" alt="KHQR" class="mx-auto h-64 w-64" :class="{ 'opacity-30': secondsLeft === 0 }" />
            <p class="mt-2 text-2xl font-bold text-neutral-900">{{ amount }}</p>
          </div>
          <p v-if="secondsLeft > 0" class="mt-3 text-sm text-neutral-600">{{ w('signupPage.validFor', { time: countdown }) }}</p>
          <p v-else class="mt-3 text-sm font-medium text-amber-700">{{ w('signupPage.codeExpired') }}</p>
          <p class="mt-4 flex items-center justify-center gap-2 text-sm text-neutral-500">
            <BaseSpinner class="h-4 w-4" />{{ w('signupPage.waitingPayment') }}
          </p>
          <p class="mt-4 text-xs text-neutral-400">{{ w('signupPage.payKeepOpen') }}</p>
        </template>

        <!-- Online payment not set up yet: we take it from here. -->
        <template v-else>
          <h1 class="text-xl font-bold text-neutral-900">{{ w('signupPage.payTitle') }}</h1>
          <p class="mt-2 text-3xl font-bold text-neutral-900">{{ amount }}</p>
          <p class="mt-2 text-sm text-neutral-600">{{ w('signupPage.payLater') }}</p>
          <RouterLink to="/contact" class="mt-4 inline-block text-sm font-medium text-secondary-700 hover:underline">{{ t('servicesPage.contactUs') }}</RouterLink>
        </template>
      </div>
    </div>
  </section>
</template>
