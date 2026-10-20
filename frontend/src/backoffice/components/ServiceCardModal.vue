<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { websiteServicesApi, type ServiceCard, type ServiceCardInput, type ServiceCardKind, type WebsiteStatus } from '../api'

/**
 * Add or change one card of the website's services page: its title, text
 * and picture, and what it opens — its own page (address, heading,
 * description, features, a button), the Computer School's pages, or a web
 * address. Khmer and English side by side.
 */
const props = defineProps<{
  modelValue: boolean
  card: ServiceCard | null
  /** Kinds another card already has that only one card may have (school, downloads). */
  takenKinds: ServiceCardKind[]
  nextOrder: number
  /** Systems a plans card can open, and the ones another card already opens. */
  products?: { code: string; name: string; name_km: string | null }[]
  takenProducts?: string[]
}>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [] }>()

const { t, locale } = useI18n()
const langs = ['km', 'en'] as const

const blank = (): ServiceCardInput => ({
  kind: 'page',
  slug: '',
  link_url: '',
  product_code: 'school',
  status: 'active',
  sort_order: 0,
  title_km: '',
  title_en: '',
  text_km: '',
  text_en: '',
  heading_km: '',
  heading_en: '',
  description_km: '',
  description_en: '',
  features_km: '',
  features_en: '',
  button_label_km: '',
  button_label_en: '',
  button_url: '',
})
const form = reactive<ServiceCardInput>(blank())
const order = ref('0')
const picture = ref<File | null>(null)
const preview = ref<string | null>(null)
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)
const fieldError = (f: string) => error.value?.fieldError(f)

const productOptions = computed(() =>
  (props.products ?? [])
    .filter((p) => !(props.takenProducts ?? []).includes(p.code) || props.card?.product_code === p.code)
    .map((p) => ({ value: p.code, label: locale.value === 'km' && p.name_km ? p.name_km : p.name })),
)

const kindOptions = computed(() =>
  (['page', 'school', 'plans', 'downloads', 'link'] as const)
    .filter((k) => !props.takenKinds.includes(k) || props.card?.kind === k)
    .map((k) => ({ value: k, label: t(`bo.website.services.kinds.${k}`) })),
)
const statusOptions = computed(() => [
  { value: 'active', label: t('bo.website.shown') },
  { value: 'inactive', label: t('bo.website.hidden') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const { id: _id, image_url, ...fields } = props.card ?? { ...blank(), id: '', image_url: null }
    Object.assign(form, blank(), fields)
    order.value = String(props.card ? props.card.sort_order : props.nextOrder)
    picture.value = null
    preview.value = image_url
    error.value = null
  },
  { immediate: true },
)

function onPicture(event: Event): void {
  const picked = (event.target as HTMLInputElement).files?.[0]
  if (!picked) return
  picture.value = picked
  preview.value = URL.createObjectURL(picked)
}

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  const body: ServiceCardInput = { ...form, sort_order: Number(order.value) || 0, image: picture.value ?? undefined }
  try {
    if (props.card) await websiteServicesApi.updateCard(props.card.id, body)
    else await websiteServicesApi.addCard(body)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    busy.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none'
const langLabel = (l: 'km' | 'en') => t(`bo.website.services.lang.${l}`)
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="card ? t('bo.website.services.editCard') : t('bo.website.services.addCard')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="bo-service-card" class="space-y-5" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error.message }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect
          v-model="form.kind"
          :label="t('bo.website.services.opens')"
          :options="kindOptions"
          :error="fieldError('kind')"
          class="sm:col-span-3"
          @update:model-value="(v: string) => (form.kind = v as ServiceCardKind)"
        />
        <BaseInput v-model="order" type="number" :label="t('bo.website.order')" :error="fieldError('sort_order')" />
        <BaseSelect
          v-model="form.status"
          :label="t('bo.website.status')"
          :options="statusOptions"
          class="sm:col-span-2"
          @update:model-value="(v: string) => (form.status = v as WebsiteStatus)"
        />
      </div>

      <!-- The card itself -->
      <fieldset class="space-y-4">
        <legend class="mb-1 text-sm font-semibold text-neutral-900">{{ t('bo.website.services.theCard') }}</legend>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-for="l in langs"
            :key="`title-${l}`"
            v-model="form[`title_${l}`]"
            :label="`${t('bo.website.services.cardTitle')} · ${langLabel(l)}`"
            :error="fieldError(`title_${l}`)"
          />
          <label v-for="l in langs" :key="`text-${l}`" class="block">
            <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.cardText') }} · {{ langLabel(l) }}</span>
            <textarea v-model="form[`text_${l}`]" rows="2" :class="textarea" />
          </label>
        </div>
        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.website.picture') }}</p>
          <div v-if="preview" class="mb-2 aspect-video w-full max-w-xs overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
            <img :src="preview" alt="" class="h-full w-full object-cover" />
          </div>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onPicture"
          />
          <p v-if="fieldError('image')" class="mt-1 text-sm text-red-600">{{ fieldError('image') }}</p>
        </div>
      </fieldset>

      <!-- Which system's plans page -->
      <BaseSelect
        v-if="form.kind === 'plans'"
        :model-value="form.product_code || 'school'"
        :options="productOptions"
        :label="t('bo.website.services.system')"
        :error="fieldError('product_code')"
        @update:model-value="(v: string) => (form.product_code = v)"
      />

      <!-- A web address -->
      <BaseInput
        v-if="form.kind === 'link'"
        v-model="form.link_url"
        :label="t('bo.website.services.linkUrl')"
        :hint="t('bo.website.services.linkUrlHint')"
        :error="fieldError('link_url')"
        required
      />

      <!-- Its own page -->
      <fieldset v-if="form.kind === 'page'" class="space-y-4 border-t border-neutral-100 pt-4">
        <legend class="mb-1 text-sm font-semibold text-neutral-900">{{ t('bo.website.services.itsPage') }}</legend>
        <BaseInput
          v-model="form.slug"
          :label="t('bo.website.services.slug')"
          :hint="t('bo.website.services.slugHint', { slug: form.slug || '…' })"
          :error="fieldError('slug')"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-for="l in langs" :key="`heading-${l}`" v-model="form[`heading_${l}`]" :label="`${t('bo.website.services.heading')} · ${langLabel(l)}`" />
          <label v-for="l in langs" :key="`desc-${l}`" class="block">
            <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.description') }} · {{ langLabel(l) }}</span>
            <textarea v-model="form[`description_${l}`]" rows="4" :class="textarea" />
          </label>
          <label v-for="l in langs" :key="`features-${l}`" class="block">
            <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.services.features') }} · {{ langLabel(l) }}</span>
            <textarea v-model="form[`features_${l}`]" rows="5" :class="textarea" :placeholder="t('bo.website.services.featuresPlaceholder')" />
          </label>
          <BaseInput
            v-for="l in langs"
            :key="`button-${l}`"
            v-model="form[`button_label_${l}`]"
            :label="`${t('bo.website.services.buttonLabel')} · ${langLabel(l)}`"
            :placeholder="t('bo.website.services.buttonLabelPlaceholder')"
          />
        </div>
        <BaseInput
          v-model="form.button_url"
          :label="t('bo.website.services.buttonUrl')"
          :hint="t('bo.website.services.buttonUrlHint')"
          :error="fieldError('button_url')"
        />
      </fieldset>
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-service-card" :loading="busy">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
