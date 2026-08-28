<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseCard from '@/components/ui/BaseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import HeroSlider from '@/components/public/HeroSlider.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type EventItem, type NewsItem } from '@/services/publicContent'

const { t } = useI18n()
const news = ref<NewsItem[]>([])
const events = ref<EventItem[]>([])
const loading = ref(true)

onMounted(async () => {
  const [newsResult, eventsResult] = await Promise.all([
    publicContentService.getNews(1, 3),
    publicContentService.getEvents(1, 3),
  ])
  news.value = newsResult.data
  events.value = eventsResult.data
  loading.value = false
})
</script>

<template>
  <div>
    <HeroSlider />

    <SectionContainer :title="t('home.latestNews')" :subtitle="t('home.latestNewsSubtitle')">
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 3" :key="i" class="h-48 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="news.length === 0" :title="t('home.noNewsTitle')" :message="t('home.noNewsMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <BaseCard v-for="item in news" :key="item.id" hoverable :padded="false" class="overflow-hidden">
          <div class="aspect-video bg-neutral-100">
            <img v-if="item.cover_image" :src="item.cover_image" :alt="item.title" class="h-full w-full object-cover" />
          </div>
          <div class="p-5">
            <p class="text-xs font-medium text-neutral-400">{{ item.published_at }}</p>
            <h3 class="mt-1 font-semibold text-neutral-900">{{ item.title }}</h3>
            <p class="mt-2 line-clamp-2 text-sm text-neutral-500">{{ item.excerpt }}</p>
            <RouterLink :to="`/news/${item.slug}`" class="mt-3 inline-block text-sm font-medium text-primary-600 hover:text-primary-700">
              {{ t('common.readMore') }}
            </RouterLink>
          </div>
        </BaseCard>
      </div>
    </SectionContainer>

    <SectionContainer :title="t('home.upcomingEvents')" :subtitle="t('home.upcomingEventsSubtitle')" class="bg-neutral-50">
      <EmptyState v-if="!loading && events.length === 0" :title="t('home.noEventsTitle')" :message="t('home.noEventsMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <BaseCard v-for="item in events" :key="item.id" hoverable>
          <p class="text-xs font-medium text-secondary-600">{{ item.starts_at }}</p>
          <h3 class="mt-1 font-semibold text-neutral-900">{{ item.title }}</h3>
          <p v-if="item.location" class="mt-2 text-sm text-neutral-500">📍 {{ item.location }}</p>
        </BaseCard>
      </div>
    </SectionContainer>
  </div>
</template>
