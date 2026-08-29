<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import SectionContainer from '@/components/public/SectionContainer.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()

const about = computed(() => site.info.about)
</script>

<template>
  <div>
    <!-- Until the school saves About content, show the simple static intro
         rather than an empty rich layout. -->
    <SectionContainer v-if="!about">
      <div class="prose prose-neutral mx-auto max-w-3xl">
        <p class="text-neutral-600">{{ t('about.body', { name: site.info.name }) }}</p>
      </div>
    </SectionContainer>

    <template v-else>
      <SectionContainer>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="stat in about.stats"
            :key="stat.label"
            class="rounded-[--radius-card] border border-neutral-200 bg-white p-6 text-center shadow-[--shadow-card]"
          >
            <p class="text-3xl font-bold text-secondary-700">{{ stat.value }}</p>
            <p class="mt-1 text-sm text-neutral-500">{{ stat.label }}</p>
          </div>
        </div>

        <div class="mt-16 grid items-center gap-10 lg:grid-cols-2">
          <div>
            <span class="mb-3 block h-1 w-10 rounded-full bg-primary-500" aria-hidden="true" />
            <h2 class="text-2xl font-bold text-neutral-900 sm:text-3xl">{{ about.history_title }}</h2>
            <p class="mt-4 text-neutral-600">{{ about.history_paragraph_1 }}</p>
            <p v-if="about.history_paragraph_2" class="mt-4 text-neutral-600">{{ about.history_paragraph_2 }}</p>
          </div>
          <div v-if="about.history_image_url" class="aspect-video overflow-hidden rounded-[--radius-card] bg-neutral-100">
            <img :src="about.history_image_url" alt="" class="h-full w-full object-cover" />
          </div>
        </div>

        <div class="mt-16 grid gap-6 sm:grid-cols-3">
          <div
            v-for="pillar in about.pillars"
            :key="pillar.title"
            class="rounded-[--radius-card] border border-neutral-200 bg-white p-6 shadow-[--shadow-card]"
          >
            <span class="text-3xl" aria-hidden="true">{{ pillar.icon }}</span>
            <h3 class="mt-3 font-semibold text-neutral-900">{{ pillar.title }}</h3>
            <p class="mt-2 text-sm text-neutral-500">{{ pillar.description }}</p>
          </div>
        </div>
      </SectionContainer>

      <div class="bg-gradient-to-br from-secondary-700 to-secondary-900 py-14 text-white sm:py-16">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div class="mb-10 text-center">
            <span class="mx-auto mb-3 block h-1 w-10 rounded-full bg-primary-500" aria-hidden="true" />
            <h2 class="text-2xl font-bold sm:text-3xl">{{ about.achievements_title }}</h2>
          </div>
          <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="achievement in about.achievements" :key="achievement.label" class="text-center">
              <span class="text-3xl" aria-hidden="true">{{ achievement.icon }}</span>
              <p class="mt-2 text-2xl font-bold text-primary-300">{{ achievement.value }}</p>
              <p class="mt-1 text-sm text-secondary-100">{{ achievement.label }}</p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
