<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { websiteApi, type WebsiteImage, type WebsiteImageKind, type WebsiteStatus } from '../api'
import { imageFields } from '../website'

/** Add or change one home slide, gallery photo or promotion. */
const props = defineProps<{ modelValue: boolean; kind: WebsiteImageKind; item: WebsiteImage | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [] }>()

const { t } = useI18n()

const fields = computed(() => imageFields[props.kind])
const form = reactive({ title: '', subtitle: '', link_url: '', caption: '', sort_order: '0', status: 'active' as WebsiteStatus })
const file = ref<File | null>(null)
const preview = ref<string | null>(null)
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)

const statusOptions = computed(() => [
  { value: 'active', label: t('bo.website.shown') },
  { value: 'inactive', label: t('bo.website.hidden') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const item = props.item
    form.title = item?.title ?? ''
    form.subtitle = item?.subtitle ?? ''
    form.link_url = item?.link_url ?? ''
    form.caption = item?.caption ?? ''
    form.sort_order = String(item?.sort_order ?? 0)
    form.status = item?.status ?? 'active'
    file.value = null
    preview.value = item?.image_url ?? null
    error.value = null
  },
  { immediate: true },
)

function onFile(event: Event): void {
  const picked = (event.target as HTMLInputElement).files?.[0]
  if (!picked) return
  file.value = picked
  preview.value = URL.createObjectURL(picked)
}

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  const body: Record<string, unknown> = { sort_order: Number(form.sort_order) || 0, status: form.status }
  for (const f of fields.value) body[f] = form[f] || undefined
  if (file.value) body.image = file.value
  try {
    if (props.item) await websiteApi.updateImage(props.kind, props.item.id, body)
    else await websiteApi.createImage(props.kind, body)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="item ? t(`bo.website.${kind}.edit`) : t(`bo.website.${kind}.add`)"
    size="md"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="bo-website-image" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error && !error.fieldError('image')" variant="danger">{{ error.message }}</BaseAlert>

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.website.picture') }}<span v-if="!item" class="text-red-500"> *</span></p>
        <div v-if="preview" class="mb-2 aspect-video w-full overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
          <img :src="preview" alt="" class="h-full w-full object-cover" />
        </div>
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          :required="!item"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onFile"
        />
        <p class="mt-1 text-xs text-neutral-500">{{ t('bo.website.pictureHint') }}</p>
        <p v-if="error?.fieldError('image')" class="mt-1 text-sm text-red-600">{{ error.fieldError('image') }}</p>
      </div>

      <BaseInput v-for="f in fields" :key="f" v-model="form[f]" :label="t(`bo.website.fields.${f}`)" :error="error?.fieldError(f)" />

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.sort_order" type="number" :label="t('bo.website.order')" :error="error?.fieldError('sort_order')" />
        <BaseSelect v-model="form.status" :label="t('bo.website.status')" :options="statusOptions" />
      </div>
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-website-image" :loading="busy">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
