<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseCard from '@/components/ui/BaseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type Teacher } from '@/services/publicContent'

const { t } = useI18n()
const teachers = ref<Teacher[]>([])
const loading = ref(true)

onMounted(async () => {
  const result = await publicContentService.getTeachers()
  teachers.value = result.data
  loading.value = false
})
</script>

<template>
  <div>
    <PageHero :title="t('teachers.title')" :subtitle="t('teachers.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="i in 8" :key="i" class="h-56 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="teachers.length === 0" :title="t('teachers.emptyTitle')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard v-for="teacher in teachers" :key="teacher.id" class="text-center">
          <div class="mx-auto mb-3 h-24 w-24 overflow-hidden rounded-full bg-neutral-100">
            <img v-if="teacher.photo" :src="teacher.photo" :alt="teacher.name" class="h-full w-full object-cover" />
          </div>
          <h3 class="font-semibold text-neutral-900">{{ teacher.name }}</h3>
          <p v-if="teacher.title" class="text-sm text-neutral-500">{{ teacher.title }}</p>
        </BaseCard>
      </div>
    </SectionContainer>
  </div>
</template>
