<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { publicContentService, type Program } from '@/services/publicContent'

const { t } = useI18n()
const programs = ref<Program[]>([])
const loading = ref(true)

const levelBadgeVariant: Record<Program['level'], 'success' | 'warning' | 'danger'> = {
  beginner: 'success',
  intermediate: 'warning',
  advanced: 'danger',
}

const levelLabel: Record<Program['level'], string> = {
  beginner: 'program.levelBeginner',
  intermediate: 'program.levelIntermediate',
  advanced: 'program.levelAdvanced',
}

onMounted(async () => {
  const result = await publicContentService.getPrograms({ featured: true })
  programs.value = result.data
  loading.value = false
})
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
    <div class="mb-8 flex items-end justify-between gap-4">
      <div>
        <span class="mb-3 block h-1 w-10 rounded-full bg-primary-500" aria-hidden="true" />
        <h2 class="text-2xl font-bold text-neutral-900 sm:text-3xl">{{ t('home.popularPrograms') }}</h2>
      </div>
      <RouterLink to="/programs" class="shrink-0 text-sm font-medium text-secondary-600 hover:text-secondary-700">
        {{ t('home.viewAllPrograms') }} &rarr;
      </RouterLink>
    </div>

    <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="i in 4" :key="i" class="h-72 animate-pulse rounded-[--radius-card] bg-neutral-100" />
    </div>
    <EmptyState v-else-if="programs.length === 0" :title="t('home.noProgramsTitle')" :message="t('home.noProgramsMessage')" />
    <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="program in programs"
        :key="program.id"
        class="overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
      >
        <div class="relative aspect-video bg-neutral-100">
          <img v-if="program.image_url" :src="program.image_url" :alt="program.title" class="h-full w-full object-cover" />
          <span class="absolute left-3 top-3 rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-neutral-800 backdrop-blur">
            {{ program.category }}
          </span>
        </div>

        <div class="p-5">
          <h3 class="font-semibold text-neutral-900">{{ program.title }}</h3>
          <p v-if="program.subtitle" class="mt-1 text-sm text-neutral-500">{{ program.subtitle }}</p>

          <div class="mt-3 flex items-center gap-2">
            <BaseBadge :variant="levelBadgeVariant[program.level]">{{ t(levelLabel[program.level]) }}</BaseBadge>
            <span v-if="program.duration_label" class="inline-flex items-center gap-1 text-xs text-neutral-500">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ program.duration_label }}
            </span>
          </div>

          <BaseButton
            to="/programs"
            variant="outline"
            block
            class="mt-4 !border-secondary-600 !text-secondary-600 hover:!bg-secondary-50"
          >
            {{ t('program.viewDetails') }}
          </BaseButton>
        </div>
      </div>
    </div>
  </section>
</template>
