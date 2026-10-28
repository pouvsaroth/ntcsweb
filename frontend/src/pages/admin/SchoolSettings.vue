<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { schoolSettingsService, type InvoiceLocale } from '@/services/schoolSettings'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const site = useSiteStore()

const localeOptions: { value: InvoiceLocale; label: string }[] = [
  { value: 'en', label: 'English' },
  { value: 'km', label: 'ភាសាខ្មែរ (Khmer)' },
]

const currencyOptions: { value: 'USD' | 'KHR'; label: string }[] = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

const form = reactive({
  name: '',
  name_en: '',
  email: '',
  phone: '',
  address: '',
  locale: 'en' as InvoiceLocale,
  default_currency: 'USD' as 'USD' | 'KHR',
  khqr_template: '',
  monthly_payment_alert_days: '',
  student_inactive_after_days: '',
  term_study_alert_days: '',
  exam_fee_amount: '',
})
const logoFile = ref<File | null>(null)
const logoPreview = ref<string | null>(null)
const stampFile = ref<File | null>(null)
const stampPreview = ref<string | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const saved = ref(false)
const saving = ref(false)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const settings = await schoolSettingsService.get()
    form.name = settings.name
    form.name_en = settings.name_en ?? ''
    form.email = settings.email ?? ''
    form.phone = settings.phone ?? ''
    form.address = settings.address ?? ''
    form.locale = settings.locale
    form.default_currency = settings.default_currency
    form.khqr_template = settings.khqr_template ?? ''
    form.monthly_payment_alert_days = String(settings.monthly_payment_alert_days)
    form.student_inactive_after_days = String(settings.student_inactive_after_days)
    form.term_study_alert_days = String(settings.term_study_alert_days)
    form.exam_fee_amount = settings.exam_fee_amount ?? ''
    logoPreview.value = settings.logo_url
    stampPreview.value = settings.stamp_url
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.school.loadFailed')
  } finally {
    loading.value = false
  }
}

function onFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  logoFile.value = file
  logoPreview.value = URL.createObjectURL(file)
}

function onStampFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  stampFile.value = file
  stampPreview.value = URL.createObjectURL(file)
}

async function save() {
  saving.value = true
  saveError.value = null
  errors.value = {}
  saved.value = false

  try {
    const result = await schoolSettingsService.save({ ...form, logo: logoFile.value ?? undefined, stamp: stampFile.value ?? undefined })
    form.name = result.name
    form.name_en = result.name_en ?? ''
    form.email = result.email ?? ''
    form.phone = result.phone ?? ''
    form.address = result.address ?? ''
    form.locale = result.locale
    form.default_currency = result.default_currency
    form.khqr_template = result.khqr_template ?? ''
    form.monthly_payment_alert_days = String(result.monthly_payment_alert_days)
    form.student_inactive_after_days = String(result.student_inactive_after_days)
    form.term_study_alert_days = String(result.term_study_alert_days)
    form.exam_fee_amount = result.exam_fee_amount ?? ''
    logoPreview.value = result.logo_url
    logoFile.value = null
    stampPreview.value = result.stamp_url
    stampFile.value = null
    saved.value = true
    // The header/footer read from this store — refresh it so the change is
    // visible on the public site without a hard reload.
    site.loaded = false
    await site.load()
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      saveError.value = error instanceof ApiRequestError ? error.message : t('admin.school.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

// Each group: what it is on the left, its fields on the right (My account style).
const card = 'grid gap-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6 lg:grid-cols-3 lg:gap-8'

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.school.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.school.lede') }}</p>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <form v-else-if="!loading" class="space-y-4" @submit.prevent="save">
      <!-- Logo and stamp, side by side -->
      <section :class="card" aria-labelledby="school-brand">
        <div>
          <h2 id="school-brand" class="text-base font-semibold text-neutral-900">{{ t('admin.school.brandSection') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.school.brandLede') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
          <div class="flex gap-4 rounded-lg border border-neutral-200 p-4">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50">
              <img v-if="logoPreview" :src="logoPreview" alt="" class="h-full w-full object-contain" />
              <span v-else class="text-2xl font-bold text-neutral-300">{{ (form.name || '?').charAt(0) }}</span>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-medium text-neutral-800">{{ t('admin.school.logoSection') }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.school.logoHint') }}</p>
              <label class="mt-2 inline-block cursor-pointer">
                <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                  {{ t('admin.school.chooseLogo') }}
                </span>
                <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
              </label>
              <p v-if="errors.logo?.[0]" class="mt-1 text-sm text-danger-600">{{ errors.logo[0] }}</p>
            </div>
          </div>
          <div class="flex gap-4 rounded-lg border border-neutral-200 p-4">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50">
              <img v-if="stampPreview" :src="stampPreview" alt="" class="h-full w-full object-contain" />
              <span v-else class="text-2xl text-neutral-300">—</span>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-medium text-neutral-800">{{ t('admin.school.stampSection') }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.school.stampHint') }}</p>
              <label class="mt-2 inline-block cursor-pointer">
                <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                  {{ t('admin.school.chooseStamp') }}
                </span>
                <input type="file" accept="image/*" class="hidden" @change="onStampFileChange" />
              </label>
              <p v-if="errors.stamp?.[0]" class="mt-1 text-sm text-danger-600">{{ errors.stamp[0] }}</p>
            </div>
          </div>
        </div>
      </section>

      <!-- Name and contact -->
      <section :class="card" aria-labelledby="school-info">
        <div>
          <h2 id="school-info" class="text-base font-semibold text-neutral-900">{{ t('admin.school.infoSection') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.school.infoLede') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
          <BaseInput v-model="form.name" required :label="t('admin.school.nameLabel')" :error="errors.name?.[0]" />
          <BaseInput v-model="form.name_en" :label="t('admin.school.nameEnLabel')" :hint="t('admin.school.nameEnHint')" :error="errors.name_en?.[0]" />
          <BaseInput v-model="form.email" type="email" :label="t('admin.school.emailLabel')" :error="errors.email?.[0]" />
          <BaseInput v-model="form.phone" :label="t('admin.school.phoneLabel')" :error="errors.phone?.[0]" />
          <div class="sm:col-span-2">
            <BaseInput v-model="form.address" :label="t('admin.school.addressLabel')" :error="errors.address?.[0]" />
          </div>
        </div>
      </section>

      <!-- Invoices and money -->
      <section :class="card" aria-labelledby="school-billing">
        <div>
          <h2 id="school-billing" class="text-base font-semibold text-neutral-900">{{ t('admin.school.billingSection') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.school.billingLede') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
          <BaseSelect
            v-model="form.locale"
            :options="localeOptions"
            :label="t('admin.school.invoiceLanguageLabel')"
            :hint="t('admin.school.invoiceLanguageHint')"
            :error="errors.locale?.[0]"
          />
          <BaseSelect
            v-model="form.default_currency"
            :options="currencyOptions"
            :label="t('admin.school.defaultCurrencyLabel')"
            :hint="t('admin.school.defaultCurrencyHint')"
            :error="errors.default_currency?.[0]"
          />
          <BaseInput
            v-model="form.exam_fee_amount"
            type="number"
            min="0"
            step="1"
            :label="t('admin.school.examFeeLabel')"
            :hint="t('admin.school.examFeeHint')"
            :error="errors.exam_fee_amount?.[0]"
          />
          <div class="sm:col-span-2">
            <label for="school-khqr" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.school.khqrSection') }}</label>
            <textarea
              id="school-khqr"
              v-model="form.khqr_template"
              rows="3"
              class="block w-full rounded-lg border border-neutral-300 px-3 py-2 font-mono text-xs text-neutral-900 shadow-sm placeholder:font-sans placeholder:text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              :placeholder="t('admin.school.khqrPlaceholder')"
            />
            <p v-if="errors.khqr_template?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.khqr_template[0] }}</p>
            <p v-else class="mt-1.5 text-sm text-neutral-500">{{ t('admin.school.khqrHint') }}</p>
          </div>
        </div>
      </section>

      <!-- Alerts and student accounts -->
      <section :class="card" aria-labelledby="school-alerts">
        <div>
          <h2 id="school-alerts" class="text-base font-semibold text-neutral-900">{{ t('admin.school.alertsSection') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.school.alertsLede') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
          <BaseInput
            v-model="form.monthly_payment_alert_days"
            type="number"
            min="1"
            max="60"
            :label="t('admin.school.monthlyPaymentAlertDaysLabel')"
            :hint="t('admin.school.monthlyPaymentAlertDaysHint')"
            :error="errors.monthly_payment_alert_days?.[0]"
          />
          <BaseInput
            v-model="form.term_study_alert_days"
            type="number"
            min="1"
            max="365"
            :label="t('admin.school.termStudyAlertDaysLabel')"
            :hint="t('admin.school.termStudyAlertDaysHint')"
            :error="errors.term_study_alert_days?.[0]"
          />
          <BaseInput
            v-model="form.student_inactive_after_days"
            type="number"
            min="1"
            max="365"
            :label="t('admin.school.studentInactiveAfterDaysLabel')"
            :hint="t('admin.school.studentInactiveAfterDaysHint')"
            :error="errors.student_inactive_after_days?.[0]"
          />
        </div>
      </section>

      <!-- Save, always in reach at the bottom of the screen -->
      <div class="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-end gap-3 border-t border-neutral-200 bg-neutral-50/95 px-1 py-3 backdrop-blur">
        <BaseAlert v-if="saveError" variant="danger" class="mr-auto">{{ saveError }}</BaseAlert>
        <p v-else-if="saved" class="mr-auto text-sm text-success-600" role="status">{{ t('admin.school.saveSuccess') }}</p>
        <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
      </div>
    </form>
  </div>
</template>
