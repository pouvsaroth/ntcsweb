<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { type Video, type VideoInput, videosService } from '@/services/videos'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  video?: Video | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.video != null)

const form = reactive({
  course_package_id: null as number | null,
  title: '',
  description: '',
  video_url: '',
  sort_order: 0,
  status: 'active' as 'active' | 'inactive',
})

const thumbnailFile = ref<File | null>(null)
const thumbnailPreview = ref<string | null>(null)
const packages = ref<CoursePackage[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const packageOptions = computed(() => packages.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.videos.statusActive') },
  { value: 'inactive', label: t('admin.videos.statusInactive') },
])

/** 11-char YouTube video id, parsed client-side purely to render a live thumbnail preview as the admin pastes a link — the backend does the real extraction (Video::youtubeId()). */
const youtubeId = computed(() => {
  const match = form.video_url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/)
  return match ? match[1] : null
})

const youtubePreviewUrl = computed(() => (youtubeId.value ? `https://img.youtube.com/vi/${youtubeId.value}/hqdefault.jpg` : null))

onMounted(async () => {
  packages.value = await coursePackagesService.listAll()
})

function onThumbnailChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  thumbnailFile.value = file
  thumbnailPreview.value = URL.createObjectURL(file)
}

watch(
  () => [props.modelValue, props.video] as const,
  ([open]) => {
    if (!open) return

    form.course_package_id = props.video?.course_package_id ?? null
    form.title = props.video?.title ?? ''
    form.description = props.video?.description ?? ''
    form.video_url = props.video?.video_url ?? ''
    form.sort_order = props.video?.sort_order ?? 0
    form.status = props.video?.status ?? 'active'
    thumbnailFile.value = null
    thumbnailPreview.value = props.video?.thumbnail_url ?? null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (form.course_package_id === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input: VideoInput = { ...form, course_package_id: form.course_package_id, thumbnail: thumbnailFile.value ?? undefined }

    if (isEditing.value) {
      await videosService.update(props.video!.id, input)
    } else {
      await videosService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.videos.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.videos.editTitle') : t('admin.videos.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        :model-value="form.course_package_id !== null ? String(form.course_package_id) : ''"
        :options="packageOptions"
        required
        :placeholder="t('admin.videos.selectCourse')"
        :label="t('admin.videos.course')"
        :error="errors.course_package_id?.[0]"
        @update:model-value="form.course_package_id = $event ? Number($event) : null"
      />

      <BaseInput v-model="form.title" required :label="t('admin.videos.videoTitle')" :error="errors.title?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.videos.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <BaseInput
        v-model="form.video_url"
        type="url"
        required
        :label="t('admin.videos.videoUrl')"
        :hint="t('admin.videos.videoUrlHint')"
        placeholder="https://www.youtube.com/watch?v=…"
        :error="errors.video_url?.[0]"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.videos.thumbnail') }}</label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.videos.thumbnailHint') }}</p>

        <div
          v-if="thumbnailPreview || youtubePreviewUrl"
          class="mb-3 aspect-video w-full max-w-xs overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
        >
          <img :src="thumbnailPreview ?? youtubePreviewUrl!" alt="" class="h-full w-full object-cover" />
        </div>

        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onThumbnailChange"
        />
        <p v-if="errors.thumbnail?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.thumbnail[0] }}</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="String(form.sort_order)"
          type="number"
          :label="t('admin.videos.sortOrder')"
          :error="errors.sort_order?.[0]"
          @update:model-value="form.sort_order = Number($event) || 0"
        />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.videos.status')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.course_package_id === null || !form.title || !form.video_url" @click="submit">
        {{ t('common.save') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
