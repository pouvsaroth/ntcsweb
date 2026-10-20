<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { billingApi, paymentsApi, type Plan } from '../../api'
import OnlinePaymentSettings from '../../components/OnlinePaymentSettings.vue'
import { useSession } from '../../session'

/** Billing settings: trial days and plan, reminder and grace days, the receipt header. */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.billing.manage'))

const loaded = ref(false)
const plans = ref<Plan[]>([])
const form = ref({
  trial_days: '15',
  trial_plan_id: '',
  warning_days: '14',
  grace_days: '7',
  company_name: '',
  company_address: '',
  company_phone: '',
  company_email: '',
  company_tax_number: '',
})
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)

const planOptions = computed(() => [
  { value: '', label: t('bo.billing.settings.legacyDefault') },
  ...plans.value.filter((p) => p.status === 'active' && !p.is_legacy).map((p) => ({ value: String(p.id), label: p.name })),
])

const logo = ref<string | null>(null)
const logoBusy = ref(false)
const logoError = ref<string | null>(null)

async function onLogo(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  logoBusy.value = true
  logoError.value = null
  try {
    logo.value = (await paymentsApi.uploadLogo(file)).company_logo_data
  } catch (e) {
    logoError.value = e instanceof ApiRequestError ? (e.fieldError('logo') ?? e.message) : t('bo.common.failed')
  } finally {
    logoBusy.value = false
  }
}

async function removeLogo(): Promise<void> {
  logoBusy.value = true
  logoError.value = null
  try {
    logo.value = (await paymentsApi.removeLogo()).company_logo_data
  } catch (e) {
    logoError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    logoBusy.value = false
  }
}

async function load(): Promise<void> {
  const [settings, planData] = await Promise.all([billingApi.settings(), billingApi.plans()])
  plans.value = planData.plans
  logo.value = settings.company_logo_data
  form.value = {
    trial_days: String(settings.trial_days),
    trial_plan_id: settings.trial_plan_id === null ? '' : String(settings.trial_plan_id),
    warning_days: String(settings.warning_days),
    grace_days: String(settings.grace_days),
    company_name: settings.company_name ?? '',
    company_address: settings.company_address ?? '',
    company_phone: settings.company_phone ?? '',
    company_email: settings.company_email ?? '',
    company_tax_number: settings.company_tax_number ?? '',
  }
  loaded.value = true
}

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  const f = form.value
  try {
    await billingApi.saveSettings({
      trial_days: Number(f.trial_days),
      trial_plan_id: f.trial_plan_id === '' ? null : Number(f.trial_plan_id),
      warning_days: Number(f.warning_days),
      grace_days: Number(f.grace_days),
      company_name: f.company_name.trim() || null,
      company_address: f.company_address.trim() || null,
      company_phone: f.company_phone.trim() || null,
      company_email: f.company_email.trim() || null,
      company_tax_number: f.company_tax_number.trim() || null,
    })
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-if="!loaded" class="flex justify-center py-10"><BaseSpinner /></div>
  <template v-else>
  <form class="max-w-3xl space-y-6" @submit.prevent="save">
    <BaseAlert v-if="saved" variant="success">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

    <fieldset :disabled="!canManage" class="space-y-6">
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="mb-4 font-semibold text-neutral-900">{{ t('bo.billing.settings.days') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-model="form.trial_days"
            type="number"
            :label="t('bo.billing.settings.trialDays')"
            :hint="t('bo.billing.settings.trialDaysHint')"
            :error="error?.fieldError('trial_days')"
            required
          />
          <BaseSelect
            v-model="form.trial_plan_id"
            :options="planOptions"
            :label="t('bo.billing.settings.trialPlan')"
            :hint="t('bo.billing.settings.trialPlanHint')"
            :error="error?.fieldError('trial_plan_id')"
          />
          <BaseInput
            v-model="form.warning_days"
            type="number"
            :label="t('bo.billing.settings.warningDays')"
            :hint="t('bo.billing.settings.warningHint')"
            :error="error?.fieldError('warning_days')"
            required
          />
          <BaseInput
            v-model="form.grace_days"
            type="number"
            :label="t('bo.billing.settings.graceDays')"
            :hint="t('bo.billing.settings.graceHint')"
            :error="error?.fieldError('grace_days')"
            required
          />
        </div>
      </section>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.billing.settings.receipt') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('bo.billing.settings.receiptHint') }}</p>
        <div class="mb-4 flex flex-wrap items-center gap-4">
          <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50">
            <img v-if="logo" :src="logo" alt="" class="max-h-full max-w-full object-contain" />
            <span v-else class="text-xs text-neutral-400">{{ t('bo.billing.settings.logo') }}</span>
          </div>
          <div v-if="canManage" class="space-y-1">
            <div class="flex flex-wrap gap-2">
              <label
                class="inline-flex cursor-pointer items-center rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                :class="{ 'pointer-events-none opacity-50': logoBusy }"
              >
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="onLogo" />
                {{ t('bo.billing.settings.logoUpload') }}
              </label>
              <BaseButton v-if="logo" type="button" size="sm" variant="ghost" :loading="logoBusy" @click="removeLogo">
                {{ t('bo.billing.settings.logoRemove') }}
              </BaseButton>
            </div>
            <p class="text-xs text-neutral-500">{{ t('bo.billing.settings.logoHint') }}</p>
            <p v-if="logoError" class="text-xs text-red-600">{{ logoError }}</p>
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.company_name" :label="t('bo.billing.settings.companyName')" :error="error?.fieldError('company_name')" />
          <BaseInput v-model="form.company_tax_number" :label="t('bo.billing.settings.companyTax')" :error="error?.fieldError('company_tax_number')" />
          <div class="sm:col-span-2">
            <BaseInput v-model="form.company_address" :label="t('bo.billing.settings.companyAddress')" :error="error?.fieldError('company_address')" />
          </div>
          <BaseInput v-model="form.company_phone" :label="t('bo.billing.settings.companyPhone')" :error="error?.fieldError('company_phone')" />
          <BaseInput v-model="form.company_email" type="email" :label="t('bo.billing.settings.companyEmail')" :error="error?.fieldError('company_email')" />
        </div>
      </section>
    </fieldset>

    <BaseButton v-if="canManage" type="submit" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
  </form>

  <!-- Its own Save: the token is write-only (stage 8c). -->
  <OnlinePaymentSettings class="mt-8" />
  </template>
</template>
