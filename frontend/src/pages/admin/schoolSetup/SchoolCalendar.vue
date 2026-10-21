<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { CALENDAR_TYPES, schoolCalendarService, type CalendarData, type CalendarEvent, type CalendarType } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * School setup > School calendar: a month of the school's days — opening and
 * closing, exam days, events — with the Holidays list and the terms on it.
 * A month grid from `sm` up; the month's list on a phone.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('school-calendar.manage'))
const canManageHolidays = computed(() => auth.can('staff-attendance.manage'))

const pad = (n: number) => String(n).padStart(2, '0')
const iso = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`
const today = new Date()
const todayIso = iso(today.getFullYear(), today.getMonth(), today.getDate())

const year = ref(today.getFullYear())
const month = ref(today.getMonth()) // 0-based
const data = ref<CalendarData>({ events: [], holidays: [], terms: [] })
const loading = ref(false)
const error = ref<string | null>(null)

const monthTitle = computed(() => new Date(year.value, month.value, 1).toLocaleDateString(locale.value === 'km' ? 'km-KH' : 'en-GB', { month: 'long', year: 'numeric' }))
const daysInMonth = computed(() => new Date(year.value, month.value + 1, 0).getDate())
const monthFrom = computed(() => iso(year.value, month.value, 1))
const monthTo = computed(() => iso(year.value, month.value, daysInMonth.value))

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await schoolCalendarService.range(monthFrom.value, monthTo.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

function shift(by: number) {
  const d = new Date(year.value, month.value + by, 1)
  year.value = d.getFullYear()
  month.value = d.getMonth()
  void load()
}
function thisMonth() {
  year.value = today.getFullYear()
  month.value = today.getMonth()
  void load()
}

// Monday-first grid: leading blanks, then the days.
const weekdays = computed(() =>
  [1, 2, 3, 4, 5, 6, 0].map((wd) => new Date(2024, 0, wd === 0 ? 7 : wd).toLocaleDateString(locale.value === 'km' ? 'km-KH' : 'en-GB', { weekday: 'short' })),
)
const leading = computed(() => (new Date(year.value, month.value, 1).getDay() + 6) % 7)

interface DayItem {
  key: string
  title: string
  kind: 'holiday' | CalendarType
  event?: CalendarEvent
}
const covers = (start: string, end: string, day: string) => start <= day && end >= day
function itemsOn(day: string): DayItem[] {
  return [
    ...data.value.holidays.filter((h) => covers(h.start_date, h.end_date, day)).map((h) => ({ key: `h${h.id}`, title: h.title, kind: 'holiday' as const })),
    ...data.value.events.filter((e) => covers(e.start_date, e.end_date, day)).map((e) => ({ key: `e${e.id}`, title: e.title, kind: e.type, event: e })),
  ]
}
const termOn = (day: string) => data.value.terms.find((term) => covers(term.start_date, term.end_date, day)) ?? null

const days = computed(() =>
  Array.from({ length: daysInMonth.value }, (_, i) => {
    const day = iso(year.value, month.value, i + 1)
    const weekday = new Date(year.value, month.value, i + 1).getDay()
    return { day, n: i + 1, weekend: weekday === 0 || weekday === 6, items: itemsOn(day), term: termOn(day) }
  }),
)

const colours: Record<DayItem['kind'], string> = {
  holiday: 'bg-red-100 text-red-800',
  opening: 'bg-green-100 text-green-800',
  closing: 'bg-amber-100 text-amber-800',
  exam: 'bg-purple-100 text-purple-800',
  event: 'bg-blue-100 text-blue-800',
  meeting: 'bg-cyan-100 text-cyan-800',
  other: 'bg-neutral-100 text-neutral-700',
}
const kindLabel = (kind: DayItem['kind']) => (kind === 'holiday' ? t('schoolSetup.calendar.holiday') : t(`schoolSetup.calendar.types.${kind}`))

/** The month's list (phone, and under the grid): holidays and days, by start date. */
const agenda = computed(() =>
  [
    ...data.value.holidays.map((h) => ({ key: `h${h.id}`, title: h.title, kind: 'holiday' as DayItem['kind'], start: h.start_date, end: h.end_date, event: undefined as CalendarEvent | undefined })),
    ...data.value.events.map((e) => ({ key: `e${e.id}`, title: e.title, kind: e.type as DayItem['kind'], start: e.start_date, end: e.end_date, event: e })),
  ].sort((a, b) => a.start.localeCompare(b.start)),
)
const range = (start: string, end: string) => (start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`)

// --- Form ----------------------------------------------------------------------
const years = ref<AcademicYear[]>([])
const yearOptions = computed(() => [{ value: '', label: '—' }, ...years.value.map((y) => ({ value: String(y.id), label: y.name }))])
const typeOptions = computed(() => CALENDAR_TYPES.map((type) => ({ value: type, label: t(`schoolSetup.calendar.types.${type}`) })))
const formOpen = ref(false)
const editing = ref<CalendarEvent | null>(null)
const form = reactive({ title: '', type: 'event' as string, start_date: '', end_date: '', academic_year_id: '', description: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(event: CalendarEvent | null, day?: string) {
  if (!canManage.value) return
  editing.value = event
  const current = years.value.find((y) => y.is_current)
  form.title = event?.title ?? ''
  form.type = event?.type ?? 'event'
  form.start_date = event?.start_date ?? day ?? ''
  form.end_date = event && event.end_date !== event.start_date ? event.end_date : ''
  form.academic_year_id = event ? String(event.academic_year_id ?? '') : current ? String(current.id) : ''
  form.description = event?.description ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    title: form.title.trim(),
    type: form.type as CalendarType,
    start_date: form.start_date,
    end_date: form.end_date || null,
    academic_year_id: form.academic_year_id ? Number(form.academic_year_id) : null,
    description: form.description.trim() || null,
  }
  try {
    if (editing.value) await schoolCalendarService.update(editing.value.id, input)
    else await schoolCalendarService.create(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!editing.value) return
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.calendar.deleteConfirm', { name: editing.value.title }), danger: true }))) return
  try {
    await schoolCalendarService.remove(editing.value.id)
    formOpen.value = false
    await load()
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.deleteFailed')
  }
}

onMounted(async () => {
  void load()
  years.value = await academicYearsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex items-center gap-1">
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('schoolSetup.calendar.previous')" @click="shift(-1)">‹</button>
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('schoolSetup.calendar.next')" @click="shift(1)">›</button>
      </div>
      <h2 class="min-w-40 text-lg font-semibold text-neutral-900">{{ monthTitle }}</h2>
      <button type="button" class="text-sm text-secondary-700 hover:underline" @click="thisMonth">{{ t('schoolSetup.calendar.today') }}</button>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <RouterLink v-if="canManageHolidays" to="/admin/time-attendance/holidays" class="text-sm text-secondary-700 hover:underline">{{ t('schoolSetup.calendar.manageHolidays') }}</RouterLink>
        <BaseButton v-if="canManage" @click="open(null)">{{ t('schoolSetup.calendar.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Legend -->
    <div class="mb-3 flex flex-wrap gap-1.5">
      <span v-for="kind in (['holiday', ...CALENDAR_TYPES] as DayItem['kind'][])" :key="kind" class="rounded-full px-2 py-0.5 text-xs" :class="colours[kind]">{{ kindLabel(kind) }}</span>
    </div>

    <!-- Month grid (sm and up) -->
    <div class="hidden overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
      <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50">
        <div v-for="w in weekdays" :key="w" class="px-2 py-2 text-center text-xs font-medium text-neutral-500">{{ w }}</div>
      </div>
      <div class="grid grid-cols-7">
        <div v-for="n in leading" :key="`blank${n}`" class="min-h-24 border-r border-b border-neutral-100 bg-neutral-50/50" />
        <div
          v-for="d in days"
          :key="d.day"
          class="group min-h-24 border-r border-b border-neutral-100 p-1.5"
          :class="[d.weekend ? 'bg-neutral-50/70' : '', canManage ? 'cursor-pointer hover:bg-primary-50/40' : '']"
          @click="open(null, d.day)"
        >
          <div class="flex items-center justify-between">
            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs" :class="d.day === todayIso ? 'bg-primary-500 font-semibold text-secondary-900' : 'text-neutral-600'">{{ d.n }}</span>
            <span v-if="d.term && (d.n === 1 || d.day === d.term.start_date)" class="truncate text-[10px] text-neutral-400">{{ d.term.name }}</span>
          </div>
          <div class="mt-1 space-y-0.5">
            <button
              v-for="item in d.items"
              :key="item.key"
              type="button"
              class="block w-full truncate rounded px-1.5 py-0.5 text-left text-[11px]"
              :class="colours[item.kind]"
              :title="item.title"
              @click.stop="item.event ? open(item.event) : undefined"
            >
              {{ item.title }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- The month's list: on a phone it is the calendar; on a desktop, a summary under the grid -->
    <div class="mt-4">
      <h3 class="mb-2 hidden text-sm font-semibold text-neutral-700 sm:block">{{ t('schoolSetup.calendar.thisMonth') }}</h3>
      <p v-if="!loading && agenda.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
        {{ t('schoolSetup.calendar.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div
          v-for="item in agenda"
          :key="item.key"
          class="flex items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]"
          :class="{ 'cursor-pointer hover:bg-neutral-50': item.event && canManage }"
          @click="item.event ? open(item.event) : undefined"
        >
          <span class="shrink-0 rounded-full px-2 py-0.5 text-xs" :class="colours[item.kind]">{{ kindLabel(item.kind) }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-neutral-800">{{ item.title }}</p>
            <p class="text-xs text-neutral-500">{{ range(item.start, item.end) }}</p>
          </div>
        </div>
      </div>
      <p v-if="data.terms.length" class="mt-3 text-xs text-neutral-500">
        {{ t('schoolSetup.calendar.terms') }}:
        <span v-for="(term, i) in data.terms" :key="term.id">{{ i ? ' · ' : '' }}{{ term.name }} ({{ range(term.start_date, term.end_date) }})</span>
      </p>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('schoolSetup.calendar.edit') : t('schoolSetup.calendar.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.title" required :label="t('schoolSetup.calendar.title')" :error="errors.title?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.type" :options="typeOptions" required :label="t('schoolSetup.calendar.type')" :error="errors.type?.[0]" />
          <BaseSelect v-model="form.academic_year_id" :options="yearOptions" :label="t('schoolSetup.academicYear')" :error="errors.academic_year_id?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.start_date" type="date" required :label="t('schoolSetup.calendar.from')" :error="errors.start_date?.[0]" />
          <BaseInput v-model="form.end_date" type="date" :label="t('schoolSetup.calendar.to')" :hint="t('schoolSetup.calendar.toHint')" :error="errors.end_date?.[0]" />
        </div>
        <BaseInput v-model="form.description" :label="t('schoolSetup.description')" />
      </form>
      <template #footer>
        <BaseButton v-if="editing" variant="danger" class="mr-auto" @click="remove">{{ t('schoolSetup.delete') }}</BaseButton>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
