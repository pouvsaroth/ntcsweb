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

/**
 * Phones/tablets pick by direction (Front/Back) — their device list also
 * holds ultra-wide/telephoto lenses with cryptic labels, so a raw device
 * picker would be confusing there. Computers have no "facing", so they get
 * a dropdown of the actual webcams instead (built-in + any USB camera).
 */
const isTouchDevice = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches
const facing = ref<'user' | 'environment'>('user')
const cameras = ref<MediaDeviceInfo[]>([])
const selectedDeviceId = ref('')

/**
 * `facingMode: 'user'` is a hint most laptop webcams and phone front
 * cameras satisfy, but some external/virtual desktop webcams reject it
 * outright with OverconstrainedError rather than just ignoring it — falling
 * back to a bare, unconstrained request here is what makes "it should
 * connect to the computer's camera too" actually true for those devices.
 */
async function requestCameraStream(): Promise<MediaStream> {
  const video: MediaTrackConstraints =
    !isTouchDevice && selectedDeviceId.value
      ? { deviceId: { exact: selectedDeviceId.value } }
      : { facingMode: facing.value }

  try {
    return await navigator.mediaDevices.getUserMedia({ video })
  } catch (err) {
    if (err instanceof DOMException && err.name === 'OverconstrainedError') {
      return await navigator.mediaDevices.getUserMedia({ video: true })
    }
    throw err
  }
}

/** Device labels are only filled in once permission is granted, so this runs after the first stream starts. */
async function loadCameras() {
  try {
    const devices = await navigator.mediaDevices.enumerateDevices()
    cameras.value = devices.filter((device) => device.kind === 'videoinput')
    const activeId = stream?.getVideoTracks()[0]?.getSettings().deviceId
    if (activeId) selectedDeviceId.value = activeId
  } catch {
    cameras.value = []
  }
}

function switchFacing(next: 'user' | 'environment') {
  if (facing.value === next) return
  facing.value = next
  stopCamera()
  void startCamera()
}

function switchDevice() {
  stopCamera()
  void startCamera()
}

/**
 * One generic "check your permissions" message was actively misleading for
 * every cause but the one it named — a missing camera, one already locked
 * by another app (Zoom/Teams), and an OS-level privacy block all produce
 * different DOMException names and need different instructions to resolve.
 */
function describeCameraError(err: unknown): string {
  // eslint-disable-next-line no-console
  console.error('Failed to access the camera', err)

  if (!(err instanceof DOMException)) return t('common.webcam.accessDenied')

  switch (err.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return t('common.webcam.accessDenied')
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return t('common.webcam.noCameraFound')
    case 'NotReadableError':
    case 'TrackStartError':
      return t('common.webcam.cameraInUse')
    default:
      return t('common.webcam.accessDenied')
  }
}

async function startCamera() {
  error.value = null
  capturedPreview.value = null
  starting.value = true

  if (!navigator.mediaDevices?.getUserMedia) {
    // No HTTPS (or localhost/127.0.0.1) — the browser simply doesn't expose
    // the camera API outside a secure context, regardless of permissions.
    error.value = t('common.webcam.notSupported')
    starting.value = false
    return
  }

  try {
    stream = await requestCameraStream()
    if (videoEl.value) {
      videoEl.value.srcObject = stream
      await videoEl.value.play()
    }
    await loadCameras()
  } catch (err) {
    error.value = describeCameraError(err)
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

      <!-- Only offered when there's actually a second camera to switch to,
           and hidden on the captured preview (Retake restarts the camera). -->
      <template v-if="cameras.length > 1 && !capturedPreview && !error">
        <div v-if="isTouchDevice" class="flex justify-center">
          <div class="inline-flex rounded-lg bg-neutral-100 p-1">
            <button
              v-for="option in (['user', 'environment'] as const)"
              :key="option"
              type="button"
              class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
              :class="facing === option ? 'bg-white text-primary-800 shadow' : 'text-neutral-600 hover:text-neutral-900'"
              :disabled="starting"
              @click="switchFacing(option)"
            >
              {{ t(option === 'user' ? 'common.webcam.frontCamera' : 'common.webcam.backCamera') }}
            </button>
          </div>
        </div>
        <label v-else class="flex items-center gap-2 text-sm text-neutral-700">
          <span class="shrink-0 font-medium">{{ t('common.webcam.chooseCamera') }}</span>
          <select
            v-model="selectedDeviceId"
            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            :disabled="starting"
            @change="switchDevice"
          >
            <option v-for="(camera, index) in cameras" :key="camera.deviceId" :value="camera.deviceId">
              {{ camera.label || `${t('common.webcam.chooseCamera')} ${index + 1}` }}
            </option>
          </select>
        </label>
      </template>

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
