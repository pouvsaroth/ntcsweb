<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseCard from '@/components/ui/BaseCard.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type NewsItem } from '@/services/publicContent'
import type { LengthAwarePaginationMeta } from '@/types/api'

const { t } = useI18n()
const news = ref<NewsItem[]>([])
const meta = ref<LengthAwarePaginationMeta | null>(null)
const loading = ref(true)

async function load(page = 1) {
  loading.value = true
  const result = await publicContentService.getNews(page)
  news.value = result.data
  meta.value = result.pagination.type === 'length_aware' ? result.pagination : null
  loading.value = false
}

onMounted(() => load())
</script>

<template>
  <div>
    <PageHero :title="t('news.title')" :subtitle="t('news.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-48 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="news.length === 0" :title="t('news.emptyTitle')" />
      <template v-else>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <BaseCard v-for="item in news" :key="item.id" hoverable :padded="false" class="overflow-hidden">
            <div class="aspect-video bg-neutral-100">
              <img v-if="item.cover_image" :src="item.cover_image" :alt="item.title" class="h-full w-full object-cover" />
            </div>
            <div class="p-5">
              <p class="text-xs font-medium text-neutral-400">{{ item.published_at }}</p>
              <h3 class="mt-1 font-semibold text-neutral-900">{{ item.title }}</h3>
              <p class="mt-2 line-clamp-2 text-sm text-neutral-500">{{ item.excerpt }}</p>
              <RouterLink :to="`/news/${item.slug}`" class="mt-3 inline-block text-sm font-medium text-secondary-600 hover:text-secondary-700">
                {{ t('common.readMore') }}
              </RouterLink>
            </div>
          </BaseCard>
        </div>
        <BasePagination v-if="meta" :meta="meta" class="mt-8" @update:page="load" />
      </template>
    </SectionContainer>
  </div>
</template>
