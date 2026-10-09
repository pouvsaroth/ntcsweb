import { ref } from 'vue'
import { defineStore } from 'pinia'

export interface ImagePreview {
  title: string
  fileName: string
  blob: Blob
  /** Object URL for the <img> — revoked again on close. */
  url: string
}

/**
 * A single, app-wide "here's your exported image" dialog — one
 * <ImagePreviewDialog> (mounted once in AdminLayout) shows whatever this
 * store holds, so every Export to Image button (see utils/tableImage.ts)
 * gets the same view / share / download / copy step instead of a silent
 * download. Same pattern as confirmDialog.ts.
 */
export const useImagePreviewStore = defineStore('imagePreview', () => {
  const current = ref<ImagePreview | null>(null)

  function show(image: { title: string; fileName: string; blob: Blob }): void {
    close()
    current.value = { ...image, url: URL.createObjectURL(image.blob) }
  }

  function close(): void {
    if (current.value) URL.revokeObjectURL(current.value.url)
    current.value = null
  }

  return { current, show, close }
})
