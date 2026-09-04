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

const form = reactive({ name: '', email: '', phone: '', address: '', locale: 'en' as InvoiceLocale, khqr_template: '' })
const logoFile = ref<File | null>(null)
const logoPreview = ref<string | null>(null)
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
    form.email = settings.email ?? ''
    form.phone = settings.phone ?? ''
    form.address = settings.address ?? ''
    form.locale = settings.locale
    form.khqr_template = settings.khqr_template ?? ''
    logoPreview.value = settings.logo_url
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

async function save() {
  saving.value = true
  saveError.value = null
  errors.value = {}
  saved.value = false

  try {
    const result = await schoolSettingsService.save({ ...form, logo: logoFile.value ?? undefined })
    form.name = result.name
    form.email = result.email ?? ''
    form.phone = result.phone ?? ''
    form.address = result.address ?? ''
    form.locale = result.locale
    form.khqr_template = result.khqr_template ?? ''
    logoPreview.value = result.logo_url
    logoFile.value = null
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

onMounted(load)
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.school.title') }}</h1>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <form v-else-if="!loading" class="space-y-6" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('admin.school.saveSuccess') }}</BaseAlert>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.school.logoSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.school.logoHint') }}</p>

        <div class="flex items-center gap-4">
          <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50">
            <img v-if="logoPreview" :src="logoPreview" alt="" class="h-full w-full object-contain" />
            <span v-else class="text-2xl font-bold text-neutral-300">{{ (form.name || '?').charAt(0) }}</span>
          </div>
          <label class="cursor-pointer">
            <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
              {{ t('admin.school.chooseLogo') }}
            </span>
            <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
          </label>
        </div>
        <p v-if="errors.logo?.[0]" class="mt-2 text-sm text-danger-600">{{ errors.logo[0] }}</p>
      </section>

      <section class="space-y-4 rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.school.infoSection') }}</h2>

        <BaseInput v-model="form.name" required :label="t('admin.school.nameLabel')" :error="errors.name?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.school.emailLabel')" :error="errors.email?.[0]" />
        <BaseInput v-model="form.phone" :label="t('admin.school.phoneLabel')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.address" :label="t('admin.school.addressLabel')" :error="errors.address?.[0]" />
        <BaseSelect
          v-model="form.locale"
          :options="localeOptions"
          :label="t('admin.school.invoiceLanguageLabel')"
          :hint="t('admin.school.invoiceLanguageHint')"
          :error="errors.locale?.[0]"
        />
      </section>

      <section class="space-y-2 rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.school.khqrSection') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ t('admin.school.khqrHint') }}</p>
        <textarea
          v-model="form.khqr_template"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 font-mono text-xs text-neutral-900 shadow-sm placeholder:font-sans placeholder:text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          :placeholder="t('admin.school.khqrPlaceholder')"
        />
        <p v-if="errors.khqr_template?.[0]" class="text-sm text-danger-600">{{ errors.khqr_template[0] }}</p>
      </section>

      <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
    </form>
  </div>
</template>
