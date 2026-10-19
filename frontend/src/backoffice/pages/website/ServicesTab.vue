<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { websiteServicesApi } from '../../api'
import { useSession } from '../../session'

/**
 * Website → Services page: when on, newtechkh.com opens on two cards —
 * Computer School (the school's own pages, from /computer-school) and
 * School Management System (its own product page). Every text in Khmer
 * and English; the website shows the visitor's language.
 */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.website.manage'))

const langs = ['km', 'en'] as const
type Lang = (typeof langs)[number]

const blankPair = () => ({ km: '', en: '' })
const form = reactive({
  enabled: false,
  computer_school: { title: blankPair(), text: blankPair() },
  sms: { title: blankPair(), text: blankPair(), heading: blankPair(), description: blankPair(), features: blankPair(), sign_in_url: '' },
})
const pictures = reactive<{ computer_school: File | null; sms: File | null }>({ computer_school: null, sms: null })
const previews = reactive<{ computer_school: string | null; sms: string | null }>({ computer_school: null, sms: null })

const loading = ref(true)
const loadError = ref<string | null>(null)
const busy = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)
const fieldError = (f: string) => error.value?.fieldError(f)

function fill(data: Awaited<ReturnType<typeof websiteServicesApi.get>>): void {
  form.enabled = data.enabled
  for (const f of ['title', 'text'] as const) {
    for (const l of langs) {
      form.computer_school[f][l] = data.computer_school[`${f}_${l}`]
      form.sms[f][l] = data.sms[`${f}_${l}`]
    }
  }
  for (const f of ['heading', 'description', 'features'] as const) {
    for (const l of langs) form.sms[f][l] = data.sms[`${f}_${l}`]
  }
  form.sms.sign_in_url = data.sms.sign_in_url
  previews.computer_school = data.computer_school.image_url
  previews.sms = data.sms.image_url
}

onMounted(async () => {
  try {
    fill(await websiteServicesApi.get())
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
})

function onPicture(card: 'computer_school' | 'sms', event: Event): void {
  const picked = (event.target as HTMLInputElement).files?.[0]
  if (!picked) return
  pictures[card] = picked
  previews[card] = URL.createObjectURL(picked)
}

/** { title: { km, en } } → { title_km, title_en } */
function flat<K extends string>(fields: Record<K, Record<Lang, string>>): Record<`${K}_${Lang}`, string> {
  const out = {} as Record<string, string>
  for (const [k, pair] of Object.entries(fields) as [string, Record<Lang, string>][]) {
    for (const l of langs) out[`${k}_${l}`] = pair[l]
  }
  return out as Record<`${K}_${Lang}`, string>
}

async function save(): Promise<void> {
  busy.value = true
  saved.value = false
  error.value = null
  const { sign_in_url, ...smsText } = form.sms
  try {
    const data = await websiteServicesApi.save({
      enabled: form.enabled,
      computer_school: flat(form.computer_school),
      sms: { ...flat(smsText), sign_in_url },
      computer_school_image: pictures.computer_school ?? undefined,
      sms_image: pictures.sms ?? undefined,
    })
    pictures.computer_school = null
    pictures.sms = null
    fill(data)
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    busy.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50'
</script>

<template>
  <div class="max-w-4xl">
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="space-y-6" @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error.message }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('bo.website.services.saved') }}</BaseAlert>

      <!-- The switch -->
      <section class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <div class="min-w-0 flex-1 basis-64">
          <p id="bo-services-on" class="font-semibold text-neutral-900">{{ t('bo.website.services.enabled') }}</p>
          <p class="mt-0.5 text-sm text-neutral-500">{{ form.enabled ? t('bo.website.services.enabledOn') : t('bo.website.services.enabledOff') }}</p>
        </div>
        <button
          type="button"
          role="switch"
          aria-labelledby="bo-services-on"
          :aria-checked="form.enabled"
          :disabled="!canManage"
          class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
          :class="form.enabled ? 'bg-primary-500' : 'bg-neutral-300'"
          @click="form.enabled = !form.enabled"
        >
          <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="form.enabled ? 'translate-x-5' : 'translate-x-0.5'" />
        </button>
        <p class="w-full text-xs text-neutral-500">{{ t('bo.website.services.saveToApply') }}</p>
      </section>

      <!-- The two cards -->
      <section
        v-for="card in (['computer_school', 'sms'] as const)"
        :key="card"
        class="space-y-4 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]"
      >
        <div>
          <h2 class="font-semibold text-neutral-900">{{ t(`bo.website.services.${card}.title`) }}</h2>
          <p class="text-sm text-neutral-500">{{ t(`bo.website.services.${card}.hint`) }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-for="l in langs"
            :key="`title-${l}`"
            v-model="form[card].title[l]"
            :label="`${t('bo.website.services.cardTitle')} · ${t(`bo.website.services.lang.${l}`)}`"
            :error="fieldError(`${card}.title_${l}`)"
            :disabled="!canManage"
          />
          <label v-for="l in langs" :key="`text-${l}`" class="block">
            <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.cardText') }} · {{ t(`bo.website.services.lang.${l}`) }}</span>
            <textarea v-model="form[card].text[l]" rows="3" :class="textarea" :disabled="!canManage" />
          </label>
        </div>

        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.website.picture') }}</p>
          <div v-if="previews[card]" class="mb-2 aspect-video w-full max-w-sm overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
            <img :src="previews[card]!" alt="" class="h-full w-full object-cover" />
          </div>
          <input
            v-if="canManage"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onPicture(card, $event)"
          />
          <p v-if="fieldError(`${card}_image`)" class="mt-1 text-sm text-red-600">{{ fieldError(`${card}_image`) }}</p>
        </div>

        <!-- The School Management System's own product page -->
        <div v-if="card === 'sms'" class="space-y-4 border-t border-neutral-100 pt-4">
          <div>
            <h3 class="text-sm font-semibold text-neutral-900">{{ t('bo.website.services.productPage') }}</h3>
            <p class="text-sm text-neutral-500">{{ t('bo.website.services.productPageHint') }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput
              v-for="l in langs"
              :key="`heading-${l}`"
              v-model="form.sms.heading[l]"
              :label="`${t('bo.website.services.heading')} · ${t(`bo.website.services.lang.${l}`)}`"
              :disabled="!canManage"
            />
            <label v-for="l in langs" :key="`desc-${l}`" class="block">
              <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.description') }} · {{ t(`bo.website.services.lang.${l}`) }}</span>
              <textarea v-model="form.sms.description[l]" rows="4" :class="textarea" :disabled="!canManage" />
            </label>
            <label v-for="l in langs" :key="`features-${l}`" class="block">
              <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.features') }} · {{ t(`bo.website.services.lang.${l}`) }}</span>
              <textarea v-model="form.sms.features[l]" rows="6" :class="textarea" :placeholder="t('bo.website.services.featuresPlaceholder')" :disabled="!canManage" />
            </label>
          </div>
          <BaseInput
            v-model="form.sms.sign_in_url"
            :label="t('bo.website.services.signInUrl')"
            :hint="t('bo.website.services.signInUrlHint')"
            :error="fieldError('sms.sign_in_url')"
            :disabled="!canManage"
          />
        </div>
      </section>

      <BaseButton v-if="canManage" type="submit" :loading="busy">{{ t('bo.common.save') }}</BaseButton>
    </form>
  </div>
</template>
