<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import CourseCard from '@/components/public/CourseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { publicContentService, type PublicCourse } from '@/services/publicContent'

const { t } = useI18n()
const courses = ref<PublicCourse[]>([])
const loading = ref(true)

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

    <div v-if="loading" class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
      <div v-for="i in 4" :key="i" class="h-40 animate-pulse rounded-[2rem] bg-neutral-100" />
    </div>
    <EmptyState v-else-if="courses.length === 0" :title="t('home.noProgramsTitle')" :message="t('home.noProgramsMessage')" />
    <div v-else class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
      <CourseCard v-for="course in courses" :key="course.id" :course="course" />
    </div>
  </section>
</template>
