<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type GalleryImage } from '@/services/publicContent'

const { t } = useI18n()
const images = ref<GalleryImage[]>([])
const loading = ref(true)

onMounted(async () => {
  const result = await publicContentService.getGallery()
  images.value = result.data
  loading.value = false
})
</script>

<template>
  <div>
    <PageHero :title="t('gallery.title')" :subtitle="t('gallery.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <div v-for="i in 8" :key="i" class="aspect-square animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="images.length === 0" :title="t('gallery.emptyTitle')" :message="t('gallery.emptyMessage')" />
      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <div v-for="image in images" :key="image.id" class="aspect-square overflow-hidden rounded-[--radius-card] bg-neutral-100">
          <img :src="image.url" :alt="image.caption ?? ''" class="h-full w-full object-cover transition-transform hover:scale-105" loading="lazy" />
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
