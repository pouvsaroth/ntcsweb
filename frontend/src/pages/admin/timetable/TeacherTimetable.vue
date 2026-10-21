<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ScheduleChangeModal from '@/components/admin/ScheduleChangeModal.vue'
import TimetableGrid from '@/components/admin/TimetableGrid.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { timetableService, type TeacherWeek, type TimetableOptions, type TimetableSlot } from '@/services/timetable'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Timetable > Teacher: a teacher's own week — the times they teach, and the
 * substitutions of that week (covering another class, or away and covered).
 * A teacher opens on their own timetable.
 */
const { t } = useI18n()
const auth = useAuthStore()
const canRequest = computed(() => auth.can('timetable.request') && !auth.can('timetable.manage'))

const options = ref<TimetableOptions>({ classes: [], teachers: [], rooms: [], subjects: [], me: null })
const staffId = ref('')
const week = ref<TeacherWeek | null>(null)
const weekStart = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

const teacherOptions = computed(() => options.value.teachers.map((x) => ({ value: String(x.id), label: x.name })))
const teacherName = computed(() => options.value.teachers.find((x) => String(x.id) === staffId.value)?.name ?? '')

async function load() {
  if (!staffId.value) return
  loading.value = true
  error.value = null
  try {
    week.value = await timetableService.forTeacher(Number(staffId.value), weekStart.value || undefined)
    weekStart.value = week.value.week_start
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
function onTeacher(value: string) {
  staffId.value = value
  void load()
}
function shift(weeks: number) {
  const d = new Date(`${weekStart.value}T00:00:00`)
  d.setDate(d.getDate() + weeks * 7)
  weekStart.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  void load()
}
const weekEnd = computed(() => {
  if (!weekStart.value) return ''
  const d = new Date(`${weekStart.value}T00:00:00`)
  d.setDate(d.getDate() + 6)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})

/** The week's substitutions, shown on their day. */
const extras = computed(() => {
  const out: Record<number, { key: string; text: string; tone: 'covering' | 'away' }[]> = {}
  for (const s of week.value?.substitutions ?? []) {
    const time = s.start_time ? `${s.start_time}–${s.end_time}` : t('timetable.allDay')
    const text = s.role === 'covering' ? t('timetable.covering', { time, class: s.class ?? '', who: s.other ?? '' }) : t('timetable.away', { time, class: s.class ?? '', who: s.other ?? '' })
    ;(out[s.day_of_week] ??= []).push({ key: `s${s.id}`, text, tone: s.role })
  }
  return out
})

const askOpen = ref(false)
const asking = ref<TimetableSlot | null>(null)
function onSlot(slot: TimetableSlot) {
  if (!canRequest.value) return
  asking.value = slot
  askOpen.value = true
}

const printPage = () => window.print()

onMounted(async () => {
  try {
    options.value = await timetableService.options()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  }
  const mine = options.value.me && options.value.teachers.some((x) => x.id === options.value.me) ? options.value.me : options.value.teachers[0]?.id
  if (mine) {
    staffId.value = String(mine)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseSelect class="w-64 max-w-full" :model-value="staffId" :options="teacherOptions" :placeholder="t('timetable.chooseTeacher')" @update:model-value="onTeacher" />
      <div v-if="weekStart" class="flex items-center gap-1">
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('timetable.previousWeek')" @click="shift(-1)">‹</button>
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('timetable.nextWeek')" @click="shift(1)">›</button>
        <span class="ml-1 text-sm text-neutral-700">{{ formatDate(weekStart) }} – {{ formatDate(weekEnd) }}</span>
      </div>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="staffId" class="ml-auto" variant="outline" @click="printPage">{{ t('timetable.print') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4 print:hidden">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4 print:hidden">{{ notice }}</BaseAlert>
    <BaseAlert v-if="!error && options.teachers.length === 0" variant="info" class="mb-4">{{ t('timetable.noTeachers') }}</BaseAlert>
    <p v-if="canRequest && week?.slots.length" class="mb-3 text-xs text-neutral-500 print:hidden">{{ t('timetable.clickToAsk') }}</p>

    <div v-if="week" class="print-area">
      <div class="mb-3 hidden print:block">
        <p class="text-lg font-semibold">{{ t('timetable.teacherTitle', { teacher: teacherName }) }}</p>
        <p class="text-sm">{{ formatDate(weekStart) }} – {{ formatDate(weekEnd) }} · {{ t('timetable.hoursWeek', { hours: week.hours_per_week }) }}</p>
      </div>
      <p class="mb-2 text-sm text-neutral-500 print:hidden">{{ t('timetable.hoursWeek', { hours: week.hours_per_week }) }}</p>
      <TimetableGrid :slots="week.slots" :show="['class', 'subject', 'room']" :extras="extras" :clickable="canRequest" @open="onSlot" />
    </div>

    <ScheduleChangeModal v-model="askOpen" :slot="asking" :options="options" @saved="notice = t('timetable.change.sent')" />
  </div>
</template>
