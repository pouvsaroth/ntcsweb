<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { TimetableSlot } from '@/services/timetable'

/**
 * A week of timetable slots (docs/academics/timetable.md): one column per day
 * from `sm` up, a list per day on a phone. `show` picks what each slot says
 * besides its time (class / subject / teacher / room). Prints cleanly inside
 * a `.print-area` (see TimetableLayout.vue).
 */
const props = defineProps<{
  slots: TimetableSlot[]
  show: ('class' | 'subject' | 'teacher' | 'room')[]
  conflictIds?: number[]
  clickable?: boolean
  /** Extra items per day (e.g. substitutions of the week). */
  extras?: Record<number, { key: string; text: string; tone: 'covering' | 'away' }[]>
}>()
const emit = defineEmits<{ open: [slot: TimetableSlot] }>()

const { t } = useI18n()

/** Monday–Saturday always; Sunday only when something is on it. */
const days = computed(() => {
  const hasSunday = props.slots.some((s) => s.day_of_week === 7) || (props.extras?.[7]?.length ?? 0) > 0
  return hasSunday ? [1, 2, 3, 4, 5, 6, 7] : [1, 2, 3, 4, 5, 6]
})
const byDay = computed(() => {
  const out: Record<number, TimetableSlot[]> = {}
  for (const d of days.value) out[d] = props.slots.filter((s) => s.day_of_week === d).sort((a, b) => a.start_time.localeCompare(b.start_time))
  return out
})
const lines = (s: TimetableSlot) =>
  [
    props.show.includes('class') ? s.class : null,
    props.show.includes('subject') ? s.subject : null,
    props.show.includes('teacher') && s.teachers.length ? s.teachers.map((x) => x.name).join(', ') : null,
    props.show.includes('room') && s.room ? s.room : null,
  ].filter(Boolean) as string[]
const conflict = (s: TimetableSlot) => props.conflictIds?.includes(s.id) ?? false
</script>

<template>
  <!-- Columns (sm and up; always when printing) -->
  <div class="hidden gap-2 sm:grid print:grid" :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }">
    <div v-for="d in days" :key="d" class="min-w-0">
      <p class="mb-1.5 rounded-md bg-neutral-100 px-2 py-1 text-center text-xs font-semibold text-neutral-700">{{ t(`timetable.days.d${d}`) }}</p>
      <div class="space-y-1.5">
        <component
          :is="clickable ? 'button' : 'div'"
          v-for="s in byDay[d]"
          :key="s.id"
          :type="clickable ? 'button' : undefined"
          class="block w-full rounded-md border p-1.5 text-left text-xs"
          :class="[conflict(s) ? 'border-red-300 bg-red-50' : 'border-primary-200 bg-primary-50/60', clickable ? 'hover:border-primary-400' : '']"
          @click="clickable ? emit('open', s) : undefined"
        >
          <p class="font-semibold text-neutral-900">{{ s.start_time }}–{{ s.end_time }}</p>
          <p v-for="(l, i) in lines(s)" :key="i" class="truncate text-neutral-700">{{ l }}</p>
        </component>
        <p v-for="x in extras?.[d] ?? []" :key="x.key" class="rounded-md border border-dashed p-1.5 text-xs" :class="x.tone === 'covering' ? 'border-green-300 bg-green-50 text-green-800' : 'border-amber-300 bg-amber-50 text-amber-800'">
          {{ x.text }}
        </p>
        <p v-if="!byDay[d]?.length && !(extras?.[d]?.length)" class="py-2 text-center text-xs text-neutral-300">—</p>
      </div>
    </div>
  </div>

  <!-- A list per day on a phone -->
  <div class="space-y-3 sm:hidden print:hidden">
    <template v-for="d in days" :key="d">
      <div v-if="byDay[d]?.length || extras?.[d]?.length">
        <p class="mb-1 text-sm font-semibold text-neutral-700">{{ t(`timetable.days.d${d}`) }}</p>
        <div class="space-y-1.5">
          <component
            :is="clickable ? 'button' : 'div'"
            v-for="s in byDay[d]"
            :key="s.id"
            :type="clickable ? 'button' : undefined"
            class="flex w-full items-start gap-3 rounded-[--radius-card] border bg-white p-2.5 text-left shadow-[--shadow-card]"
            :class="conflict(s) ? 'border-red-300' : 'border-neutral-200'"
            @click="clickable ? emit('open', s) : undefined"
          >
            <span class="w-24 shrink-0 text-sm font-semibold text-neutral-900">{{ s.start_time }}–{{ s.end_time }}</span>
            <span class="min-w-0 flex-1 text-sm text-neutral-700">{{ lines(s).join(' · ') || '—' }}</span>
          </component>
          <p v-for="x in extras?.[d] ?? []" :key="x.key" class="rounded-[--radius-card] border border-dashed p-2.5 text-sm" :class="x.tone === 'covering' ? 'border-green-300 text-green-800' : 'border-amber-300 text-amber-800'">{{ x.text }}</p>
        </div>
      </div>
    </template>
    <p v-if="slots.length === 0 && !Object.values(extras ?? {}).some((x) => x.length)" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('timetable.empty') }}</p>
  </div>
</template>
