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
/** New photos only: several can be picked, and each is saved as its own photo. */
const newImages = ref<{ file: File; preview: string }[]>([])
const progress = ref<{ done: number; total: number } | null>(null)
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
    newImages.value = []
    progress.value = null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const picked = Array.from(input.files ?? [])
  if (picked.length === 0) return

  if (isEditing.value) {
    imageFile.value = picked[0]
    imagePreview.value = URL.createObjectURL(picked[0])
  } else {
    newImages.value = [...newImages.value, ...picked.map((file) => ({ file, preview: URL.createObjectURL(file) }))]
    input.value = ''
  }
}

function removeNewImage(index: number) {
  newImages.value = newImages.value.filter((_, i) => i !== index)
}

/**
 * Saves each picked photo in turn, numbering them up from the chosen order so
 * they keep the order they were picked in. Photos that fail stay selected so
 * the admin can fix the problem and press Save again for just those.
 */
async function createAll() {
  const queue = newImages.value
  const failed: typeof queue = []
  let lastError: unknown = null
  progress.value = { done: 0, total: queue.length }

  for (const [i, item] of queue.entries()) {
    try {
      await galleryService.create({ ...form, sort_order: form.sort_order + i, image: item.file })
    } catch (error) {
      failed.push(item)
      lastError = error
    }
    progress.value = { done: i + 1, total: queue.length }
  }

  progress.value = null
  if (failed.length === queue.length && queue.length === 1) throw lastError
  if (failed.length > 0) {
    if (failed.length < queue.length) emit('saved')
    newImages.value = failed
    const detail = lastError instanceof ApiRequestError ? lastError.message : t('admin.gallery.saveFailed')
    generalError.value = `${t('admin.gallery.someFailed', { names: failed.map((f) => f.file.name).join(', ') })} (${detail})`
    return false
  }
  return true
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await galleryService.update(props.photo!.id, { ...form, image: imageFile.value ?? undefined })
    } else {
      if (newImages.value.length === 0) {
        errors.value = { image: [t('admin.gallery.imageRequired')] }
        return
      }
      if (!(await createAll())) return
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
          v-if="isEditing && imagePreview"
          class="mb-3 aspect-square w-32 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
        >
          <img :src="imagePreview" alt="" class="h-full w-full object-cover" />
        </div>

        <div v-if="!isEditing && newImages.length" class="mb-3">
          <p class="mb-2 text-sm text-neutral-600">{{ t('admin.gallery.photosSelected', { count: newImages.length }, newImages.length) }}</p>
          <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <div
              v-for="(item, index) in newImages"
              :key="item.preview"
              class="relative aspect-square overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
            >
              <img :src="item.preview" :alt="item.file.name" class="h-full w-full object-cover" />
              <button
                type="button"
                class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-neutral-900/70 text-sm leading-none text-white hover:bg-neutral-900"
                :aria-label="t('admin.gallery.delete')"
                :disabled="submitting"
                @click="removeNewImage(index)"
              >
                &times;
              </button>
            </div>
          </div>
        </div>

        <input
          type="file"
          :multiple="!isEditing"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onFileChange"
        />
        <p v-if="errors.image?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.image[0] }}</p>
        <p v-else class="mt-1.5 text-sm text-neutral-500">{{ isEditing ? t('admin.gallery.imageHint') : t('admin.gallery.imageHintMultiple') }}</p>
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
      <span v-if="progress" class="mr-auto text-sm text-neutral-500">{{ t('admin.gallery.savingProgress', progress) }}</span>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
