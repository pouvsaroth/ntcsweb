<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type NewsItem } from '@/services/publicContent'

const route = useRoute()
const { t } = useI18n()
const article = ref<NewsItem | null>(null)
const loading = ref(true)

onMounted(async () => {
  article.value = await publicContentService.getNewsBySlug(String(route.params.slug))
  loading.value = false
})
</script>

<template>
  <SectionContainer>
    <div v-if="loading" class="mx-auto max-w-3xl">
      <div class="h-8 w-2/3 animate-pulse rounded bg-neutral-100" />
      <div class="mt-4 h-64 animate-pulse rounded-[--radius-card] bg-neutral-100" />
    </div>
    <EmptyState v-else-if="!article" :title="t('news.notFoundTitle')" :message="t('news.notFoundMessage')" />
    <article v-else class="prose prose-neutral mx-auto max-w-3xl">
      <p class="text-sm font-medium text-neutral-400">{{ article.published_at }}</p>
      <h1 class="mt-1 text-3xl font-bold text-neutral-900">{{ article.title }}</h1>
      <img v-if="article.cover_image" :src="article.cover_image" :alt="article.title" class="mt-6 w-full rounded-[--radius-card]" />
      <p class="mt-6 text-neutral-600">{{ article.excerpt }}</p>
    </article>
  </SectionContainer>
</template>
