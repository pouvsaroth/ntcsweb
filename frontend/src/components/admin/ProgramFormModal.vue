<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { programsService, type Program, type ProgramLevel } from '@/services/programs'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new program. */
  program?: Program | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.program != null)

const form = reactive({
  title: '',
  subtitle: '',
  category: '',
  level: 'beginner' as ProgramLevel,
  duration_label: '',
  fee: '',
  description: '',
  is_featured: false,
  sort_order: 0,
  status: 'active' as 'active' | 'inactive',
})

const imageFile = ref<File | null>(null)
const imagePreview = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const levelOptions = computed(() => [
  { value: 'beginner', label: t('admin.programs.levelBeginner') },
  { value: 'intermediate', label: t('admin.programs.levelIntermediate') },
  { value: 'advanced', label: t('admin.programs.levelAdvanced') },
])

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.programs.statusActive') },
  { value: 'inactive', label: t('admin.programs.statusInactive') },
])

watch(
  () => [props.modelValue, props.program] as const,
  ([open]) => {
    if (!open) return

    form.title = props.program?.title ?? ''
    form.subtitle = props.program?.subtitle ?? ''
    form.category = props.program?.category ?? ''
    form.level = props.program?.level ?? 'beginner'
    form.duration_label = props.program?.duration_label ?? ''
    form.fee = props.program?.fee != null ? String(props.program.fee) : ''
    form.description = props.program?.description ?? ''
    form.is_featured = props.program?.is_featured ?? false
    form.sort_order = props.program?.sort_order ?? 0
    form.status = props.program?.status ?? 'active'
    imageFile.value = null
    imagePreview.value = props.program?.image_url ?? null
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
      await programsService.update(props.program!.id, payload)
    } else {
      await programsService.create(payload)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('auth.login.genericError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.programs.editTitle') : t('admin.programs.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.programs.image') }}</label>

        <div
          v-if="imagePreview"
          class="mb-3 aspect-video w-full overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
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
        <p v-else class="mt-1.5 text-sm text-neutral-500">{{ t('admin.programs.imageHint') }}</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.title" required :label="t('admin.programs.programTitle')" :error="errors.title?.[0]" />
        <BaseInput v-model="form.subtitle" :label="t('admin.programs.subtitle')" :error="errors.subtitle?.[0]" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.category" required :label="t('admin.programs.category')" :error="errors.category?.[0]" />
        <BaseInput v-model="form.duration_label" :label="t('admin.programs.durationLabel')" :hint="t('admin.programs.durationLabelHint')" :error="errors.duration_label?.[0]" />
      </div>

      <BaseInput
        v-model="form.fee"
        type="number"
        :label="t('admin.programs.fee')"
        :hint="t('admin.programs.feeHint')"
        :error="errors.fee?.[0]"
        class="max-w-xs"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.programs.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.description?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.description[0] }}</p>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <BaseSelect v-model="form.level" :options="levelOptions" :label="t('admin.programs.level')" />
        <BaseInput
          :model-value="String(form.sort_order)"
          type="number"
          :label="t('admin.programs.sortOrder')"
          :error="errors.sort_order?.[0]"
          @update:model-value="form.sort_order = Number($event) || 0"
        />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.programs.status')" />
      </div>

      <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
        <input v-model="form.is_featured" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-200" />
        {{ t('admin.programs.isFeatured') }}
      </label>
      <p class="-mt-2 text-sm text-neutral-500">{{ t('admin.programs.isFeaturedHint') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
