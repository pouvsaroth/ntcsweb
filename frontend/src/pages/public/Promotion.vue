<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type Promotion } from '@/services/publicContent'

const { t } = useI18n()
const promotions = ref<Promotion[]>([])
const loading = ref(true)

onMounted(async () => {
  const result = await publicContentService.getPromotions()
  promotions.value = result.data
  loading.value = false
})
</script>

<template>
  <div>
    <PageHero :title="t('promotion.title')" :subtitle="t('promotion.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="aspect-video animate-pulse rounded-2xl bg-neutral-100" />
      </div>
      <EmptyState
        v-else-if="promotions.length === 0"
        :title="t('promotion.emptyTitle')"
        :message="t('promotion.emptyMessage')"
      />
      <div v-else class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="promotion in promotions" :key="promotion.id" class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
          <div class="aspect-video overflow-hidden bg-neutral-100">
            <img :src="promotion.image_url" :alt="promotion.title ?? ''" class="h-full w-full object-cover" loading="lazy" />
          </div>
          <p v-if="promotion.title" class="px-4 py-3 text-sm font-medium text-neutral-800">{{ promotion.title }}</p>
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
