<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseCard from '@/components/ui/BaseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type Program } from '@/services/publicContent'

const { t } = useI18n()
const programs = ref<Program[]>([])
const loading = ref(true)

onMounted(async () => {
  const result = await publicContentService.getPrograms()
  programs.value = result.data
  loading.value = false
})
</script>

<template>
  <div>
    <PageHero :title="t('programs.title')" :subtitle="t('programs.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-32 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="programs.length === 0" :title="t('programs.emptyTitle')" :message="t('programs.emptyMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <BaseCard v-for="program in programs" :key="program.id" hoverable>
          <h3 class="font-semibold text-neutral-900">{{ program.name }}</h3>
          <p class="mt-2 text-sm text-neutral-500">{{ program.description }}</p>
        </BaseCard>
      </div>
    </SectionContainer>
  </div>
</template>
