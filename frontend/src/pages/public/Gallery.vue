<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type GalleryImage } from '@/services/publicContent'

const { t } = useI18n()
const images = ref<GalleryImage[]>([])
const loading = ref(true)
const activeImage = ref<GalleryImage | null>(null)

onMounted(async () => {
  const result = await publicContentService.getGallery()
  images.value = result.data
  loading.value = false
})

function open(image: GalleryImage) {
  activeImage.value = image
}

function close() {
  activeImage.value = null
}

/**
 * Not `image.url` (the raw storage URL) — that's a different origin from the
 * frontend dev server (see vite.config.ts's proxy) and browsers only honor
 * `download` for a same-origin link, so it would just navigate instead of
 * saving. `/api/...` *is* proxied in dev and is naturally same-origin in
 * production; the backend also sets `Content-Disposition: attachment` there
 * (see PublicGalleryController::download()), which is what actually forces
 * the save regardless of origin.
 */
function downloadUrl(image: GalleryImage): string {
  return `/api/v1/public/gallery/${image.id}/download`
}
</script>

<template>
  <div>
    <SectionContainer>
      <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <div v-for="i in 8" :key="i" class="aspect-square animate-pulse rounded-2xl bg-neutral-100" />
      </div>
      <EmptyState v-else-if="images.length === 0" :title="t('gallery.emptyTitle')" :message="t('gallery.emptyMessage')" />
      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <button
          v-for="image in images"
          :key="image.id"
          type="button"
          class="group overflow-hidden rounded-2xl border-2 border-success-600 bg-neutral-100 text-left"
          @click="open(image)"
        >
          <div class="aspect-square overflow-hidden">
            <img :src="image.url" :alt="image.caption ?? ''" class="h-full w-full object-cover transition-transform group-hover:scale-105" loading="lazy" />
          </div>
          <p v-if="image.caption" class="truncate border-t-2 border-success-600 px-2 py-1.5 text-sm font-medium text-neutral-700">
            {{ image.caption }}
          </p>
        </button>
      </div>
    </SectionContainer>

    <!-- Lightbox — click a photo to view it full-size and download it. -->
    <div
      v-if="activeImage"
      class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/90 p-4"
      @click.self="close"
    >
      <button
        type="button"
        class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20"
        :aria-label="t('common.close')"
        @click="close"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>

      <div class="flex max-h-full max-w-4xl flex-col items-center">
        <img :src="activeImage.url" :alt="activeImage.caption ?? ''" class="max-h-[75vh] max-w-full rounded-lg object-contain" @click.stop />

        <div class="mt-4 flex items-center gap-4">
          <p v-if="activeImage.caption" class="text-sm text-white/90">{{ activeImage.caption }}</p>
          <a
            :href="downloadUrl(activeImage)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-neutral-800 hover:bg-neutral-100"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
            </svg>
            {{ t('gallery.download') }}
          </a>
        </div>
      </div>
    </div>
  </div>
</template>
