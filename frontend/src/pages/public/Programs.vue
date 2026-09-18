<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CourseCard from '@/components/public/CourseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type ClassScheduleSlot, type PublicCourse, type ScheduledClass } from '@/services/publicContent'

const { t } = useI18n()

/**
 * One page, two blocks — Day and Time Study on top, the course catalog
 * below (see PublicHeader.vue's `mainNavItems`: "Program" is a plain link
 * here now instead of a dropdown with separate destinations, so both used
 * to be their own pages under that menu).
 */
const classes = ref<ScheduledClass[]>([])
const scheduleLoading = ref(true)

const courses = ref<PublicCourse[]>([])
const coursesLoading = ref(true)
const pricingInfoOpen = ref(false)
const pricingNoteKeys = ['programs.noteMonthly', 'programs.noteTerm', 'programs.noteVideo']

const dayLabel: Record<number, string> = {
  1: 'schedule.monday',
  2: 'schedule.tuesday',
  3: 'schedule.wednesday',
  4: 'schedule.thursday',
  5: 'schedule.friday',
  6: 'schedule.saturday',
  7: 'schedule.sunday',
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
  const [schedules, coursesResult] = await Promise.all([publicContentService.getSchedules(), publicContentService.getCourses()])
  classes.value = schedules.data
  scheduleLoading.value = false
  courses.value = coursesResult.data
  coursesLoading.value = false
})

/** "18:00:00" -> "18:00" — the API sends a raw SQL TIME string. */
function formatTime(value: string): string {
  return value.slice(0, 5)
}

interface DayGroup {
  dayLabel: string
  times: string[]
}

/**
 * Days that share the exact same set of times collapse into one heading —
 * "Monday to Friday" with its times listed once, instead of repeating the
 * same time row under five separate day headings.
 */
function dayGroups(schedules: ClassScheduleSlot[]): DayGroup[] {
  const byDay = new Map<number, ClassScheduleSlot[]>()
  for (const slot of schedules) {
    if (!byDay.has(slot.day_of_week)) byDay.set(slot.day_of_week, [])
    byDay.get(slot.day_of_week)!.push(slot)
  }

  const sortedTimes = (slots: ClassScheduleSlot[]) => [...slots].sort((a, b) => a.start_time.localeCompare(b.start_time))
  const signature = (slots: ClassScheduleSlot[]) => sortedTimes(slots).map((s) => `${s.start_time}-${s.end_time}`).join('|')

  const bySignature = new Map<string, { days: number[]; slots: ClassScheduleSlot[] }>()
  for (const [day, slots] of byDay) {
    const sig = signature(slots)
    if (!bySignature.has(sig)) bySignature.set(sig, { days: [], slots: sortedTimes(slots) })
    bySignature.get(sig)!.days.push(day)
  }

  return [...bySignature.values()]
    .map((group) => ({ ...group, days: group.days.sort((a, b) => a - b) }))
    .sort((a, b) => a.days[0] - b.days[0])
    .map((group) => ({
      dayLabel: dayGroupLabel(group.days),
      times: group.slots.map((slot) => `${formatTime(slot.start_time)} – ${formatTime(slot.end_time)}`),
    }))
}

function dayGroupLabel(days: number[]): string {
  const names = days.map((d) => t(dayLabel[d]))
  const isContiguous = days.length > 1 && days.every((d, i) => i === 0 || d === days[i - 1] + 1)

  if (isContiguous) return t('schedule.dayRange', { from: names[0], to: names[names.length - 1] })
  return t('schedule.daySingle', { day: names.join(', ') })
}
</script>

<template>
  <div>
    <!-- Top block: Day and Time Study -->
    <SectionContainer :title="t('schedule.title')" :subtitle="t('schedule.subtitle')">
      <div v-if="scheduleLoading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 3" :key="i" class="h-40 animate-pulse rounded-[2rem] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="classes.length === 0" :title="t('schedule.emptyTitle')" :message="t('schedule.emptyMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="schoolClass in classes"
          :key="schoolClass.id"
          class="rounded-[2rem] border border-primary-400 bg-white p-6 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        >
          <h3 class="text-center font-semibold text-primary-700">{{ schoolClass.name }}</h3>
          <p v-if="schoolClass.teacher_name" class="mb-3 text-sm text-neutral-500">
            {{ t('schedule.teacher', { name: schoolClass.teacher_name }) }}
          </p>

          <div class="space-y-4">
            <div v-for="(group, index) in dayGroups(schoolClass.schedules)" :key="index">
              <p class="text-left text-sm font-bold text-neutral-900">{{ group.dayLabel }}</p>
              <p v-for="(time, i) in group.times" :key="i" class="mt-1 text-left text-sm font-medium text-neutral-900">{{ time }}</p>
            </div>
          </div>
        </div>
      </div>
    </SectionContainer>

    <!-- Bottom block: Course catalog -->
    <div class="border-t border-neutral-100 bg-neutral-50">
      <SectionContainer :title="t('programs.title')" :subtitle="t('programs.subtitle')">
        <div class="mb-8 rounded-xl border border-neutral-200 bg-white">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium text-neutral-700"
            @click="pricingInfoOpen = !pricingInfoOpen"
          >
            {{ t('programs.pricingInfoTitle') }}
            <svg
              class="h-4 w-4 shrink-0 transition-transform"
              :class="pricingInfoOpen ? 'rotate-180' : ''"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <ul v-if="pricingInfoOpen" class="list-disc space-y-1.5 px-4 pb-4 pl-8 text-xs leading-relaxed text-neutral-500">
            <li v-for="key in pricingNoteKeys" :key="key">{{ t(key) }}</li>
          </ul>
        </div>

        <div v-if="coursesLoading" class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
          <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-[2rem] bg-white" />
        </div>
        <EmptyState v-else-if="courses.length === 0" :title="t('programs.emptyTitle')" :message="t('programs.emptyMessage')" />
        <div v-else class="space-y-12">
          <div v-for="group in programGroups" :key="group.id">
            <h3 class="mb-5 text-xl font-semibold text-neutral-900">{{ group.name }}</h3>
            <div class="grid gap-6 sm:grid-cols-1 lg:grid-cols-2">
              <CourseCard v-for="course in group.courses" :key="course.id" :course="course" />
            </div>
          </div>
        </div>
      </SectionContainer>
    </div>
  </div>
</template>
