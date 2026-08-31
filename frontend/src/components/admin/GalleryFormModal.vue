<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { galleryService, type GalleryPhoto } from '@/services/gallery'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when uploading a new photo. */
  photo?: GalleryPhoto | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.photo != null)

const form = reactive({
  caption: '',
  sort_order: 0,
  status: 'active' as 'active' | 'inactive',
})

const imageFile = ref<File | null>(null)
const imagePreview = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.gallery.statusActive') },
  { value: 'inactive', label: t('admin.gallery.statusInactive') },
])

// Re-seed the form whenever a different photo is opened for editing, or the
// modal is opened fresh for a new one — see HomeSlideFormModal.vue for why
// this watches `modelValue`, not just `photo`.
watch(
  () => [props.modelValue, props.photo] as const,
  ([open]) => {
    if (!open) return

    form.caption = props.photo?.caption ?? ''
    form.sort_order = props.photo?.sort_order ?? 0
    form.status = props.photo?.status ?? 'active'
    imageFile.value = null
    imagePreview.value = props.photo?.image_url ?? null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function onFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const payload = { ...form, image: imageFile.value ?? undefined }

  try {
    if (isEditing.value) {
      await galleryService.update(props.photo!.id, payload)
    } else {
      if (!imageFile.value) {
        errors.value = { image: [t('admin.gallery.imageRequired')] }
        return
      }
      await galleryService.create(payload)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.gallery.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.gallery.editTitle') : t('admin.gallery.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.gallery.image') }}
          <span v-if="!isEditing" class="text-danger-600">*</span>
        </label>

        <div
          v-if="imagePreview"
          class="mb-3 aspect-square w-32 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
        >
          <img :src="imagePreview" alt="" class="h-full w-full object-cover" />
        </div>

        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onFileChange"
        />
        <p v-if="errors.image?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.image[0] }}</p>
        <p v-else class="mt-1.5 text-sm text-neutral-500">{{ t('admin.gallery.imageHint') }}</p>
      </div>

      <BaseInput v-model="form.caption" :label="t('admin.gallery.caption')" :error="errors.caption?.[0]" />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="String(form.sort_order)"
          type="number"
          :label="t('admin.gallery.sortOrder')"
          :error="errors.sort_order?.[0]"
          @update:model-value="form.sort_order = Number($event) || 0"
        />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.gallery.status')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
