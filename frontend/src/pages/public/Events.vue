<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseCard from '@/components/ui/BaseCard.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type EventItem } from '@/services/publicContent'
import type { LengthAwarePaginationMeta } from '@/types/api'

const { t } = useI18n()
const events = ref<EventItem[]>([])
const meta = ref<LengthAwarePaginationMeta | null>(null)
const loading = ref(true)

async function load(page = 1) {
  loading.value = true
  const result = await publicContentService.getEvents(page)
  events.value = result.data
  meta.value = result.pagination.type === 'length_aware' ? result.pagination : null
  loading.value = false
}

onMounted(() => load())
</script>

<template>
  <div>
    <PageHero :title="t('events.title')" :subtitle="t('events.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="events.length === 0" :title="t('events.emptyTitle')" />
      <template v-else>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <BaseCard v-for="item in events" :key="item.id" hoverable>
            <p class="text-xs font-medium text-secondary-600">{{ item.starts_at }}</p>
            <h3 class="mt-1 font-semibold text-neutral-900">{{ item.title }}</h3>
            <p v-if="item.location" class="mt-2 text-sm text-neutral-500">📍 {{ item.location }}</p>
          </BaseCard>
        </div>
        <BasePagination v-if="meta" :meta="meta" class="mt-8" @update:page="load" />
      </template>
    </SectionContainer>
  </div>
</template>
