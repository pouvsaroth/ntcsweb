<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

/**
 * A reusable "take a photo with the camera" modal — used anywhere a form
 * currently only accepts a file upload (Student photo first, more to
 * follow). Emits a real `File` on `captured`, exactly like a file input's
 * `change` event would, so the parent form needs no separate code path for
 * a webcam capture vs. an uploaded file.
 */
const props = defineProps<{ modelValue: boolean }>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; captured: [file: File] }>()

const { t } = useI18n()

const videoEl = ref<HTMLVideoElement | null>(null)
const canvasEl = ref<HTMLCanvasElement | null>(null)
let stream: MediaStream | null = null

const capturedPreview = ref<string | null>(null)
const error = ref<string | null>(null)
const starting = ref(false)

async function startCamera() {
  error.value = null
  capturedPreview.value = null
  starting.value = true

  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    if (videoEl.value) {
      videoEl.value.srcObject = stream
      await videoEl.value.play()
    }
  } catch {
    error.value = t('common.webcam.accessDenied')
  } finally {
    starting.value = false
  }
}

function stopCamera() {
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function capture() {
  const video = videoEl.value
  const canvas = canvasEl.value
  if (!video || !canvas || video.videoWidth === 0) return

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  const context = canvas.getContext('2d')
  if (!context) return

  context.drawImage(video, 0, 0, canvas.width, canvas.height)
  capturedPreview.value = canvas.toDataURL('image/jpeg', 0.92)
  stopCamera()
}

function retake() {
  void startCamera()
}

function confirmCapture() {
  const canvas = canvasEl.value
  if (!canvas) return

  canvas.toBlob(
    (blob) => {
      if (!blob) return
      emit('captured', new File([blob], `webcam-${Date.now()}.jpg`, { type: 'image/jpeg' }))
      close()
    },
    'image/jpeg',
    0.92,
  )
}

function close() {
  stopCamera()
  emit('update:modelValue', false)
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void startCamera()
    else stopCamera()
  },
)
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('common.webcam.title')" @update:model-value="close">
    <div class="space-y-3">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="flex aspect-video items-center justify-center overflow-hidden rounded-lg bg-neutral-900">
        <video v-show="!capturedPreview && !error" ref="videoEl" class="h-full w-full object-cover" autoplay playsinline muted />
        <img v-if="capturedPreview" :src="capturedPreview" alt="" class="h-full w-full object-cover" />
      </div>
      <canvas ref="canvasEl" class="hidden" />
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="close">{{ t('common.close') }}</BaseButton>
      <template v-if="capturedPreview">
        <BaseButton variant="outline" @click="retake">{{ t('common.webcam.retake') }}</BaseButton>
        <BaseButton @click="confirmCapture">{{ t('common.webcam.usePhoto') }}</BaseButton>
      </template>
      <BaseButton v-else :disabled="starting || !!error" @click="capture">{{ t('common.webcam.capture') }}</BaseButton>
    </template>
  </BaseModal>
</template>
