<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LessonPlanModal from '@/components/admin/LessonPlanModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { isoDate, lessonPlansService, type LessonOptions, type PlanRow } from '@/services/lessonPlans'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/** Lesson plans > Week: the plans of one week, day by day; "+" on a day plans a lesson for it. */
const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.can('lesson-plans.write'))

const options = ref<LessonOptions>({ me: null, sees_all: false, classes: [], subjects: [], teachers: [] })
const monday = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate() - ((d.getDay() + 6) % 7))
const start = ref(monday(new Date()))
const days = computed(() => Array.from({ length: 7 }, (_, i) => new Date(start.value.getFullYear(), start.value.getMonth(), start.value.getDate() + i)))
const filter = reactive({ staff_id: '', class_id: '' })
const rows = ref<PlanRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const teacherOptions = computed(() => [{ value: '', label: t('lessonPlans.allTeachers') }, ...options.value.teachers.map((x) => ({ value: String(x.id), label: x.name ?? '' }))])
const classOptions = computed(() => [{ value: '', label: t('lessonPlans.allClasses') }, ...options.value.classes.map((c) => ({ value: String(c.id), label: c.name }))])
const statusDot = (s: string) => (s === 'approved' ? 'bg-green-500' : s === 'submitted' ? 'bg-amber-500' : s === 'returned' ? 'bg-red-500' : 'bg-neutral-300')

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await lessonPlansService.plans({
      from: isoDate(days.value[0]!),
      to: isoDate(days.value[6]!),
      staff_id: filter.staff_id ? Number(filter.staff_id) : undefined,
      class_id: filter.class_id ? Number(filter.class_id) : undefined,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}
function shift(weeks: number) {
  const d = start.value
  start.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + weeks * 7)
  void load()
}
function set(key: keyof typeof filter, value: string) {
  filter[key] = value
  void load()
}
const onDay = (d: Date) => rows.value.filter((r) => r.planned_date === isoDate(d))
const todayIso = isoDate(new Date())

const open = ref(false)
const planId = ref<number | null>(null)
const forDate = ref<string | undefined>(undefined)
function openPlan(id: number | null, date?: string) {
  planId.value = id
  forDate.value = date
  open.value = true
}

onMounted(async () => {
  try {
    options.value = await lessonPlansService.options()
    if (!options.value.sees_all && options.value.me) filter.staff_id = String(options.value.me)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex items-center gap-1">
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('lessonPlans.previousWeek')" @click="shift(-1)">‹</button>
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('lessonPlans.nextWeek')" @click="shift(1)">›</button>
      </div>
      <p class="text-sm font-medium text-neutral-800">{{ formatDate(isoDate(days[0]!)) }} – {{ formatDate(isoDate(days[6]!)) }}</p>
      <BaseSelect v-if="options.sees_all" class="w-48" :model-value="filter.staff_id" :options="teacherOptions" @update:model-value="set('staff_id', $event)" />
      <BaseSelect class="w-44" :model-value="filter.class_id" :options="classOptions" @update:model-value="set('class_id', $event)" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="grid gap-2 sm:grid-cols-7">
      <div v-for="d in days" :key="d.toISOString()" class="min-w-0 rounded-[--radius-card] border bg-white p-2 shadow-[--shadow-card]" :class="isoDate(d) === todayIso ? 'border-primary-400' : 'border-neutral-200'">
        <div class="mb-1.5 flex items-center justify-between">
          <p class="text-xs font-semibold text-neutral-700">{{ t(`timetable.days.d${((d.getDay() + 6) % 7) + 1}`) }} <span class="font-normal text-neutral-400">{{ d.getDate() }}</span></p>
          <button v-if="canWrite" type="button" class="rounded px-1.5 text-sm text-secondary-700 hover:bg-secondary-50" :aria-label="t('lessonPlans.newPlan')" @click="openPlan(null, isoDate(d))">+</button>
        </div>
        <p v-if="onDay(d).length === 0" class="py-2 text-center text-xs text-neutral-300">—</p>
        <button v-for="p in onDay(d)" :key="p.id" type="button" class="mb-1 block w-full rounded-md border border-neutral-200 p-1.5 text-left text-xs hover:border-primary-300" @click="openPlan(p.id)">
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 shrink-0 rounded-full" :class="statusDot(p.status)" /><span class="truncate font-medium text-neutral-800">{{ p.title }}</span></span>
          <span class="block truncate text-neutral-500">{{ [p.class, p.subject].filter(Boolean).join(' · ') }}</span>
          <span v-if="options.sees_all" class="block truncate text-neutral-400">{{ p.teacher }}</span>
        </button>
      </div>
    </div>

    <LessonPlanModal v-model="open" :plan-id="planId" :options="options" :date="forDate" @changed="load" />
  </div>
</template>
