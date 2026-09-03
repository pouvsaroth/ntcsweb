<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { publicContentService, type PublicCourse } from '@/services/publicContent'

const { t } = useI18n()
const courses = ref<PublicCourse[]>([])
const loading = ref(true)

const currencySymbol: Record<PublicCourse['currency'], string> = {
  USD: '$',
  KHR: '៛',
}

/** The homepage teaser shows one headline fee, not the full breakdown — that's what the full /programs page is for. */
function headlineFee(course: PublicCourse): { labelKey: string; amount: number } | null {
  if (course.fee_monthly !== null) return { labelKey: 'programs.feeMonthly', amount: course.fee_monthly }
  if (course.fee_term !== null) return { labelKey: 'programs.feeTerm', amount: course.fee_term }
  if (course.fee_video !== null) return { labelKey: 'programs.feeVideo', amount: course.fee_video }
  if (course.fee_monthly_online !== null) return { labelKey: 'programs.feeMonthlyOnline', amount: course.fee_monthly_online }
  if (course.fee_term_online !== null) return { labelKey: 'programs.feeTermOnline', amount: course.fee_term_online }
  return null
}

onMounted(async () => {
  const result = await publicContentService.getCourses({ featured: true })
  courses.value = result.data
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
      <div v-for="i in 4" :key="i" class="h-56 animate-pulse rounded-[--radius-card] bg-neutral-100" />
    </div>
    <EmptyState v-else-if="courses.length === 0" :title="t('home.noProgramsTitle')" :message="t('home.noProgramsMessage')" />
    <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="course in courses"
        :key="course.id"
        class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
      >
        <BaseBadge v-if="course.academic_program" variant="primary" class="self-start">{{ course.academic_program.name }}</BaseBadge>
        <h3 class="mt-3 font-semibold text-neutral-900">{{ course.name }}</h3>
        <p v-if="course.description" class="mt-1 line-clamp-2 text-sm text-neutral-500">{{ course.description }}</p>

        <div class="mt-3 flex items-center gap-2">
          <span v-if="headlineFee(course)" class="text-sm font-semibold text-primary-700">
            {{ currencySymbol[course.currency] }}{{ headlineFee(course)!.amount.toFixed(2) }}
            <span class="font-normal text-neutral-400">/ {{ t(headlineFee(course)!.labelKey).toLowerCase() }}</span>
          </span>
          <span v-if="course.duration" class="ml-auto inline-flex items-center gap-1 text-xs text-neutral-500">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {{ course.duration }}
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
  </section>
</template>
