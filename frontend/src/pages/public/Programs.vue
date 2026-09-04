<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CourseCard from '@/components/public/CourseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type PublicCourse } from '@/services/publicContent'

const { t } = useI18n()
const courses = ref<PublicCourse[]>([])
const loading = ref(true)

interface ProgramGroup {
  id: number
  name: string
  sortOrder: number
  courses: PublicCourse[]
}

// Courses without a program (shouldn't normally happen — a package always
// has one) are grouped under a synthetic bucket so nothing silently
// disappears from the page.
const programGroups = computed<ProgramGroup[]>(() => {
  const groups = new Map<number, ProgramGroup>()

  for (const course of courses.value) {
    const program = course.academic_program
    const key = program?.id ?? 0
    if (!groups.has(key)) {
      groups.set(key, { id: key, name: program?.name ?? t('programs.otherCourses'), sortOrder: program?.sort_order ?? Number.MAX_SAFE_INTEGER, courses: [] })
    }
    groups.get(key)!.courses.push(course)
  }

  return [...groups.values()].sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
})

onMounted(async () => {
  const result = await publicContentService.getCourses()
  courses.value = result.data
  loading.value = false
})
</script>

<template>
  <div>
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
        <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-[2rem] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="courses.length === 0" :title="t('programs.emptyTitle')" :message="t('programs.emptyMessage')" />
      <div v-else class="space-y-12">
        <div v-for="group in programGroups" :key="group.id">
          <h2 class="mb-5 text-xl font-semibold text-neutral-900">{{ group.name }}</h2>
          <div class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
            <CourseCard v-for="course in group.courses" :key="course.id" :course="course" />
          </div>
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
