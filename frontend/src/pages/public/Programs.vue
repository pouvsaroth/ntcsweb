<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type PublicCourse } from '@/services/publicContent'

const { t } = useI18n()
const courses = ref<PublicCourse[]>([])
const loading = ref(true)

interface FeeRow {
  labelKey: string
  amount: number
}

const currencySymbol: Record<PublicCourse['currency'], string> = {
  USD: '$',
  KHR: '៛',
}

function feeRows(course: PublicCourse): FeeRow[] {
  const rows: FeeRow[] = []
  if (course.fee_monthly !== null) rows.push({ labelKey: 'programs.feeMonthly', amount: course.fee_monthly })
  if (course.fee_term !== null) rows.push({ labelKey: 'programs.feeTerm', amount: course.fee_term })
  if (course.fee_video !== null) rows.push({ labelKey: 'programs.feeVideo', amount: course.fee_video })
  if (course.fee_monthly_online !== null) rows.push({ labelKey: 'programs.feeMonthlyOnline', amount: course.fee_monthly_online })
  if (course.fee_term_online !== null) rows.push({ labelKey: 'programs.feeTermOnline', amount: course.fee_term_online })
  return rows
}

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
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-56 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="courses.length === 0" :title="t('programs.emptyTitle')" :message="t('programs.emptyMessage')" />
      <div v-else class="space-y-12">
        <div v-for="group in programGroups" :key="group.id">
          <h2 class="mb-5 text-xl font-semibold text-neutral-900">{{ group.name }}</h2>
          <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="course in group.courses"
              :key="course.id"
              class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
            >
              <h3 class="font-semibold text-neutral-900">{{ course.name }}</h3>
              <p v-if="course.description" class="mt-2 line-clamp-3 text-sm text-neutral-500">{{ course.description }}</p>

              <dl v-if="feeRows(course).length > 0" class="mt-4 space-y-1.5 text-sm">
                <div v-for="row in feeRows(course)" :key="row.labelKey" class="flex items-center justify-between">
                  <dt class="text-neutral-500">{{ t(row.labelKey) }}</dt>
                  <dd class="font-medium text-neutral-800">{{ currencySymbol[course.currency] }}{{ row.amount.toFixed(2) }}</dd>
                </div>
              </dl>

              <p v-if="course.duration" class="mt-3 inline-flex items-center gap-1 text-xs text-neutral-500">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ course.duration }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
