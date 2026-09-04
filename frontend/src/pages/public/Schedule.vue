<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type ClassScheduleSlot, type ScheduledClass } from '@/services/publicContent'

const { t } = useI18n()
const classes = ref<ScheduledClass[]>([])
const loading = ref(true)

const dayLabel: Record<number, string> = {
  1: 'schedule.monday',
  2: 'schedule.tuesday',
  3: 'schedule.wednesday',
  4: 'schedule.thursday',
  5: 'schedule.friday',
  6: 'schedule.saturday',
  7: 'schedule.sunday',
}

onMounted(async () => {
  const result = await publicContentService.getSchedules()
  classes.value = result.data
  loading.value = false
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
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-[2rem] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="classes.length === 0" :title="t('schedule.emptyTitle')" :message="t('schedule.emptyMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="schoolClass in classes"
          :key="schoolClass.id"
          class="rounded-[2rem] border border-primary-400 bg-white p-6 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        >
          <h3 class="font-semibold text-neutral-900">{{ schoolClass.name }}</h3>
          <p v-if="schoolClass.teacher_name" class="mb-3 text-sm text-neutral-500">
            {{ t('schedule.teacher', { name: schoolClass.teacher_name }) }}
          </p>

          <div class="space-y-4">
            <div v-for="(group, index) in dayGroups(schoolClass.schedules)" :key="index">
              <p class="text-center text-sm font-bold text-primary-700">{{ group.dayLabel }}</p>
              <p v-for="(time, i) in group.times" :key="i" class="mt-1 text-sm font-medium text-primary-600">{{ time }}</p>
            </div>
          </div>
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
