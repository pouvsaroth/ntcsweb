<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useImagePreviewStore } from '@/stores/imagePreview'

/**
 * The exported image, shown before it's saved anywhere — see
 * stores/imagePreview.ts. "Share" hands the PNG itself to the device's own
 * share sheet (Web Share API), which is where Telegram, Facebook, Messenger,
 * etc. appear; their plain share-link URLs can only carry a link, never an
 * image file. Where the browser can't share files (most desktop browsers
 * other than Chrome/Edge on Windows/ChromeOS, and Safari on macOS), "Copy"
 * puts the image on the clipboard to paste into Telegram Desktop or a
 * Facebook post instead.
 */
const store = useImagePreviewStore()
const { t } = useI18n()

const open = computed({
  get: () => store.current !== null,
  set: (value) => {
    if (!value) store.close()
  },
})

const file = computed(() => (store.current ? new File([store.current.blob], store.current.fileName, { type: 'image/png' }) : null))

const canShare = computed(() => {
  if (!file.value || typeof navigator.canShare !== 'function') return false
  try {
    return navigator.canShare({ files: [file.value] })
  } catch {
    return false
  }
})

const canCopy = typeof ClipboardItem !== 'undefined' && typeof navigator.clipboard?.write === 'function'

const sharing = ref(false)
const copied = ref(false)
const error = ref<string | null>(null)

watch(
  () => store.current,
  () => {
    copied.value = false
    error.value = null
  },
)

async function share() {
  if (!file.value || !store.current) return
  sharing.value = true
  error.value = null
  try {
    await navigator.share({ files: [file.value], title: store.current.title })
  } catch (err) {
    // AbortError = the person just closed the share sheet — not a failure.
    if (!(err instanceof DOMException && err.name === 'AbortError')) error.value = t('common.imagePreview.shareFailed')
  } finally {
    sharing.value = false
  }
}

function download() {
  if (!store.current) return
  const link = document.createElement('a')
  link.href = store.current.url
  link.download = store.current.fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
}

async function copy() {
  if (!store.current) return
  error.value = null
  try {
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': store.current.blob })])
    copied.value = true
  } catch {
    error.value = t('common.imagePreview.copyFailed')
  }
}
</script>

<template>
  <BaseModal v-model="open" :title="store.current?.title" size="lg">
    <img
      v-if="store.current"
      :src="store.current.url"
      :alt="store.current.title"
      class="mx-auto h-auto max-w-full rounded-lg border border-neutral-200"
    />
    <p v-if="!canShare" class="mt-3 text-xs text-neutral-500">{{ t('common.imagePreview.noShareHint') }}</p>
    <p v-if="error" class="mt-3 text-sm text-danger-600">{{ error }}</p>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton v-if="canCopy" variant="outline" @click="copy">
          {{ copied ? t('common.imagePreview.copied') : t('common.imagePreview.copy') }}
        </BaseButton>
        <BaseButton variant="outline" @click="download">{{ t('common.imagePreview.download') }}</BaseButton>
        <BaseButton v-if="canShare" :loading="sharing" @click="share">{{ t('common.imagePreview.share') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
