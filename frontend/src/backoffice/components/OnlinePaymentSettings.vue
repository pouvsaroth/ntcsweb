<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

import { billingApi, type BillingSettingsRow } from '../api'
import { useSession } from '../session'

/**
 * Billing → Settings → Online payment (docs/backoffice/stage-8-signup.md, 8c):
 * NewTech's KHQR and the Bakong token that website sign-ups are paid and
 * checked with. The token is write-only: the page only says whether one is
 * set and when it stops working.
 */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.billing.manage'))

const settings = ref<BillingSettingsRow | null>(null)
const khqr = ref('')
const token = ref('')
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)
const testing = ref(false)
const testResult = ref<{ ok: boolean; message: string } | null>(null)

onMounted(async () => {
  settings.value = await billingApi.settings()
  khqr.value = settings.value.khqr_template ?? ''
})

/** Days until the token stops working; null when it does not say. */
const daysLeft = computed(() => {
  const at = settings.value?.bakong_token_expires_at
  return at ? Math.ceil((Date.parse(at) - Date.now()) / 86_400_000) : null
})
const ready = computed(() => !!settings.value?.khqr_template && !!settings.value?.bakong_token_set && (daysLeft.value === null || daysLeft.value > 0))

async function save(clearToken = false): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    settings.value = await billingApi.savePayment({ khqr_template: khqr.value.trim() || null, bakong_token: token.value.trim() || null, clear_token: clearToken })
    khqr.value = settings.value.khqr_template ?? ''
    token.value = ''
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

async function test(): Promise<void> {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await billingApi.testBakong()
  } catch (e) {
    testResult.value = { ok: false, message: e instanceof ApiRequestError ? e.message : t('bo.common.failed') }
  } finally {
    testing.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 font-mono text-xs text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50'
</script>

<template>
  <section v-if="settings" class="max-w-3xl rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-1 flex flex-wrap items-center gap-2">
      <h2 class="font-semibold text-neutral-900">{{ t('bo.billing.online.title') }}</h2>
      <BaseBadge :variant="ready ? 'success' : 'warning'">{{ ready ? t('bo.billing.online.ready') : t('bo.billing.online.notReady') }}</BaseBadge>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.billing.online.hint') }}</p>

    <BaseAlert v-if="saved" variant="success" class="mb-4">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseAlert v-if="error && !error.errors" variant="danger" class="mb-4">{{ error.message }}</BaseAlert>

    <form class="space-y-4" @submit.prevent="save()">
      <label class="block">
        <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.online.khqr') }}</span>
        <textarea v-model="khqr" rows="3" :class="textarea" :disabled="!canManage" spellcheck="false" />
        <span class="mt-1 block text-xs text-neutral-500">{{ t('bo.billing.online.khqrHint') }}</span>
        <span v-if="error?.fieldError('khqr_template')" class="mt-1 block text-xs text-red-600">{{ error.fieldError('khqr_template') }}</span>
      </label>

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.online.token') }}</p>
        <p class="mb-2 text-sm">
          <template v-if="settings.bakong_token_set">
            <span class="text-green-700">✓ {{ t('bo.billing.online.tokenSet') }}</span>
            <template v-if="settings.bakong_token_expires_at">
              <span v-if="daysLeft !== null && daysLeft <= 0" class="ml-2 font-medium text-red-600">{{ t('bo.billing.online.tokenExpired', { date: formatDate(settings.bakong_token_expires_at) }) }}</span>
              <span v-else-if="daysLeft !== null && daysLeft <= 14" class="ml-2 font-medium text-amber-700">
                {{ t('bo.billing.online.tokenSoon', { n: daysLeft, date: formatDate(settings.bakong_token_expires_at) }) }}
              </span>
              <span v-else class="ml-2 text-neutral-500">{{ t('bo.billing.online.tokenUntil', { date: formatDate(settings.bakong_token_expires_at) }) }}</span>
            </template>
          </template>
          <span v-else class="text-amber-700">{{ t('bo.billing.online.tokenNone') }}</span>
        </p>
        <BaseInput
          v-if="canManage"
          v-model="token"
          type="password"
          autocomplete="off"
          :placeholder="settings.bakong_token_set ? t('bo.billing.online.tokenReplace') : t('bo.billing.online.tokenPaste')"
          :hint="t('bo.billing.online.tokenHint')"
          :error="error?.fieldError('bakong_token')"
        />
      </div>

      <div v-if="canManage" class="flex flex-wrap items-center gap-2">
        <BaseButton type="submit" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
        <BaseButton v-if="settings.bakong_token_set" variant="outline" :loading="testing" @click="test">{{ t('bo.billing.online.test') }}</BaseButton>
        <BaseButton v-if="settings.bakong_token_set" variant="ghost" :disabled="saving" @click="save(true)">{{ t('bo.billing.online.tokenRemove') }}</BaseButton>
      </div>
      <p v-if="testResult" class="text-sm" :class="testResult.ok ? 'text-green-700' : 'text-red-600'">{{ testResult.message }}</p>
    </form>
  </section>
</template>
