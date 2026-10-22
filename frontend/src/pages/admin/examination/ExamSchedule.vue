<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { booksService, type Book } from '@/services/books'
import { classesService, type SchoolClass } from '@/services/classes'
import { classroomsService, type Classroom } from '@/services/classrooms'
import { assessmentSchemesService, examSchedulesService, type AssessmentScheme, type ExamScheduleRow } from '@/services/gradebook'
import { academicTermsService, gradeLevelsService, type AcademicTerm, type GradeLevel } from '@/services/schoolSetup'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Examination > Exam Schedule (docs/academics/gradebook.md): each subject's
 * exam for a whole grade or one class. The server refuses two overlapping
 * exams that share students, a room or an invigilator.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('exam-schedules.manage'))

const years = ref<AcademicYear[]>([])
const terms = ref<AcademicTerm[]>([])
const grades = ref<GradeLevel[]>([])
const classes = ref<SchoolClass[]>([])
const books = ref<Book[]>([])
const rooms = ref<Classroom[]>([])
const staff = ref<Staff[]>([])
const schemes = ref<AssessmentScheme[]>([])

const filter = reactive({ year: '', term: '', grade: '', class: '' })
const rows = ref<ExamScheduleRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const km = computed(() => locale.value === 'km')
const pick = (en: string | null | undefined, kh: string | null | undefined) => ((km.value ? kh || en : en || kh) ?? '').trim()

const yearOptions = computed(() => years.value.map((y) => ({ value: String(y.id), label: y.is_current ? `${y.name} (${t('gradebook.current')})` : y.name })))
const termsOf = (yearId: string) => terms.value.filter((tm) => String(tm.academic_year_id) === yearId)
const termFilterOptions = computed(() => [{ value: '', label: t('gradebook.allTerms') }, ...termsOf(filter.year).map((tm) => ({ value: String(tm.id), label: tm.name }))])
const gradeFilterOptions = computed(() => [{ value: '', label: t('gradebook.allGrades') }, ...grades.value.map((g) => ({ value: String(g.id), label: g.name }))])
const classFilterOptions = computed(() => [
  { value: '', label: t('gradebook.allClasses') },
  ...classes.value
    .filter((c) => (!filter.grade || String(c.grade_level_id) === filter.grade) && (!filter.year || !c.academic_year_id || String(c.academic_year_id) === filter.year))
    .map((c) => ({ value: String(c.id), label: c.name })),
])

async function load() {
  if (!filter.year) {
    rows.value = []
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    rows.value = await examSchedulesService.list({
      academic_year_id: Number(filter.year),
      academic_term_id: filter.term ? Number(filter.term) : undefined,
      grade_level_id: filter.grade ? Number(filter.grade) : undefined,
      class_id: filter.class ? Number(filter.class) : undefined,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

function setFilter(key: keyof typeof filter, value: string) {
  filter[key] = value
  if (key === 'year') filter.term = ''
  if (key === 'grade') filter.class = ''
  void load()
}

const who = (r: ExamScheduleRow) => r.class?.name ?? r.grade_level?.name ?? '—'
const time = (r: ExamScheduleRow) => `${r.start_time}–${r.end_time}`

const columns = computed(() => [
  { key: 'date', label: t('gradebook.schedule.date') },
  { key: 'time', label: t('gradebook.schedule.time') },
  { key: 'subject', label: t('gradebook.schedule.subject') },
  { key: 'who', label: t('gradebook.schedule.who') },
  { key: 'component', label: t('gradebook.schedule.component') },
  { key: 'room', label: t('gradebook.schedule.room') },
  { key: 'invigilator', label: t('gradebook.schedule.invigilator') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<ExamScheduleRow | null>(null)
const form = reactive({
  academic_year_id: '', academic_term_id: '', scope: 'grade' as 'grade' | 'class', grade_level_id: '', class_id: '',
  book_id: '', assessment_component_id: '', exam_date: '', start_time: '07:30', end_time: '09:00',
  classroom_id: '', invigilator_staff_id: '', notes: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const err = (key: string) => errors.value[key]?.[0]

const formTermOptions = computed(() => [{ value: '', label: t('gradebook.none') }, ...termsOf(form.academic_year_id).map((tm) => ({ value: String(tm.id), label: tm.name }))])
const formGradeOptions = computed(() => grades.value.map((g) => ({ value: String(g.id), label: g.name })))
const formClassOptions = computed(() =>
  classes.value
    .filter((c) => !c.academic_year_id || !form.academic_year_id || String(c.academic_year_id) === form.academic_year_id)
    .map((c) => ({ value: String(c.id), label: c.grade_level ? `${c.name} (${c.grade_level.name})` : c.name })),
)
const bookOptions = computed(() => books.value.map((b) => ({ value: String(b.id), label: b.title })))
const roomOptions = computed(() => [{ value: '', label: t('gradebook.none') }, ...rooms.value.map((r) => ({ value: String(r.id), label: r.name }))])
const staffOptions = computed(() => [{ value: '', label: t('gradebook.none') }, ...staff.value.map((s) => ({ value: String(s.id), label: s.full_name }))])
/** The components of the grade's own setup, else the default's. */
const componentOptions = computed(() => {
  const gradeId = form.scope === 'grade' ? Number(form.grade_level_id) : (classes.value.find((c) => String(c.id) === form.class_id)?.grade_level_id ?? null)
  const scheme = schemes.value.find((s) => s.grade_level?.id === gradeId) ?? schemes.value.find((s) => s.grade_level === null)
  return [{ value: '', label: t('gradebook.none') }, ...(scheme?.components ?? []).map((c) => ({ value: String(c.id), label: pick(c.name, c.name_km) }))]
})

function open(row: ExamScheduleRow | null) {
  editing.value = row
  form.academic_year_id = row?.academic_year ? String(row.academic_year.id) : filter.year
  form.academic_term_id = row?.term ? String(row.term.id) : filter.term
  form.scope = row?.class ? 'class' : 'grade'
  form.grade_level_id = row?.grade_level ? String(row.grade_level.id) : filter.grade
  form.class_id = row?.class ? String(row.class.id) : filter.class
  form.book_id = row?.book ? String(row.book.id) : ''
  form.assessment_component_id = row?.component ? String(row.component.id) : ''
  form.exam_date = row?.exam_date ?? ''
  form.start_time = row?.start_time ?? '07:30'
  form.end_time = row?.end_time ?? '09:00'
  form.classroom_id = row?.classroom ? String(row.classroom.id) : ''
  form.invigilator_staff_id = row?.invigilator ? String(row.invigilator.id) : ''
  form.notes = row?.notes ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const num = (v: string) => (v ? Number(v) : null)
  const input = {
    academic_year_id: Number(form.academic_year_id),
    academic_term_id: num(form.academic_term_id),
    assessment_component_id: num(form.assessment_component_id),
    grade_level_id: form.scope === 'grade' ? num(form.grade_level_id) : null,
    class_id: form.scope === 'class' ? num(form.class_id) : null,
    book_id: Number(form.book_id),
    exam_date: form.exam_date,
    start_time: form.start_time,
    end_time: form.end_time,
    classroom_id: num(form.classroom_id),
    invigilator_staff_id: num(form.invigilator_staff_id),
    notes: form.notes.trim() || null,
  }
  try {
    if (editing.value) await examSchedulesService.update(editing.value.id, input)
    else await examSchedulesService.create(input)
    formOpen.value = false
    if (!filter.year) filter.year = form.academic_year_id
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(row: ExamScheduleRow) {
  if (!(await confirmDialog.confirm({ message: t('gradebook.schedule.deleteConfirm', { subject: row.book?.title ?? '' }), danger: true }))) return
  try {
    await examSchedulesService.remove(row.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.deleteFailed')
  }
}

const printPage = () => window.print()

onMounted(async () => {
  const safe = <T,>(allowed: boolean, p: () => Promise<T[]>) => (allowed ? p().catch(() => [] as T[]) : Promise.resolve([] as T[]))
  ;[years.value, terms.value, grades.value, classes.value, books.value, rooms.value, staff.value, schemes.value] = await Promise.all([
    academicYearsService.listAll().catch(() => []),
    safe(auth.can('academic-terms.view'), () => academicTermsService.list()),
    gradeLevelsService.list(true).catch(() => []),
    safe(auth.can('classes.view'), () => classesService.listAll()),
    safe(auth.can('books.view'), () => booksService.listAll()),
    safe(auth.can('classrooms.view') && canManage.value, () => classroomsService.listAll()),
    safe(auth.can('staff.view') && canManage.value, () => staffService.listAll()),
    safe(auth.can('assessment.view'), () => assessmentSchemesService.list()),
  ])
  const current = years.value.find((y) => y.is_current) ?? years.value[0]
  if (current) filter.year = String(current.id)
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center print:hidden">
      <BaseSelect class="lg:w-48" :model-value="filter.year" :options="yearOptions" @update:model-value="(v) => setFilter('year', v)" />
      <BaseSelect class="lg:w-44" :model-value="filter.term" :options="termFilterOptions" @update:model-value="(v) => setFilter('term', v)" />
      <BaseSelect class="lg:w-44" :model-value="filter.grade" :options="gradeFilterOptions" @update:model-value="(v) => setFilter('grade', v)" />
      <BaseSelect class="lg:w-44" :model-value="filter.class" :options="classFilterOptions" @update:model-value="(v) => setFilter('class', v)" />
      <div class="flex gap-2 sm:col-span-2 lg:ml-auto">
        <BaseButton variant="outline" :disabled="rows.length === 0" @click="printPage">{{ t('gradebook.print') }}</BaseButton>
        <BaseButton v-if="canManage" :disabled="years.length === 0" @click="open(null)">{{ t('gradebook.schedule.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4 print:hidden">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && years.length === 0" variant="info" class="mb-4">{{ t('gradebook.schedule.noYears') }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden print:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('gradebook.schedule.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="r in rows" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-neutral-900">{{ r.book?.title }}</p>
              <p class="text-sm text-neutral-700">{{ who(r) }}<span v-if="r.component"> · {{ pick(r.component.name, r.component.name_km) }}</span></p>
            </div>
            <EditIconButton v-if="canManage" @click="open(r)" />
          </div>
          <p class="mt-1 text-sm text-neutral-800">{{ formatDate(r.exam_date) }} · {{ time(r) }}</p>
          <p class="text-xs text-neutral-500">
            {{ r.classroom?.name ?? '—' }} · {{ r.invigilator?.name ?? '—' }}<span v-if="r.term"> · {{ r.term.name }}</span>
          </p>
          <button v-if="canManage" type="button" class="mt-2 text-sm text-red-600 hover:underline" @click="remove(r)">{{ t('gradebook.delete') }}</button>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop (and print): table -->
    <div class="hidden sm:block print:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('gradebook.schedule.empty')">
        <template #cell-date="{ row }"><span class="whitespace-nowrap">{{ formatDate(row.exam_date) }}</span></template>
        <template #cell-time="{ row }"><span class="whitespace-nowrap">{{ time(row) }}</span></template>
        <template #cell-subject="{ row }">
          <span class="font-medium text-neutral-900">{{ row.book?.title }}</span>
          <p v-if="row.notes" class="text-xs text-neutral-500">{{ row.notes }}</p>
        </template>
        <template #cell-who="{ row }">{{ who(row) }}</template>
        <template #cell-component="{ row }">{{ row.component ? pick(row.component.name, row.component.name_km) : '—' }}</template>
        <template #cell-room="{ row }">{{ row.classroom?.name ?? '—' }}</template>
        <template #cell-invigilator="{ row }">{{ row.invigilator?.name ?? '—' }}</template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3 print:hidden">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm text-red-600 hover:underline" @click="remove(row)">{{ t('gradebook.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('gradebook.schedule.editTitle') : t('gradebook.schedule.add')" size="lg">
      <form id="exam-schedule-form" class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.academic_year_id" :options="yearOptions" :label="t('gradebook.academicYear')" :error="err('academic_year_id')" required />
          <BaseSelect v-model="form.academic_term_id" :options="formTermOptions" :label="t('gradebook.term')" :error="err('academic_term_id')" />
        </div>

        <fieldset>
          <legend class="mb-2 text-sm font-medium text-neutral-700">{{ t('gradebook.schedule.for') }}</legend>
          <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-2"><input v-model="form.scope" type="radio" value="grade" /> {{ t('gradebook.schedule.wholeGrade') }}</label>
            <label class="flex items-center gap-2"><input v-model="form.scope" type="radio" value="class" /> {{ t('gradebook.schedule.oneClass') }}</label>
          </div>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-if="form.scope === 'grade'" v-model="form.grade_level_id" :options="formGradeOptions" :label="t('gradebook.gradeLevel')" :error="err('grade_level_id') || err('class_id')" required />
          <BaseSelect v-else v-model="form.class_id" :options="formClassOptions" :label="t('gradebook.class')" :error="err('class_id') || err('grade_level_id')" required />
          <BaseSelect v-model="form.book_id" :options="bookOptions" :label="t('gradebook.schedule.subject')" :error="err('book_id')" required />
          <BaseSelect v-model="form.assessment_component_id" :options="componentOptions" :label="t('gradebook.schedule.component')" :error="err('assessment_component_id')" />
          <BaseInput v-model="form.exam_date" type="date" :label="t('gradebook.schedule.date')" :error="err('exam_date')" required />
          <BaseInput v-model="form.start_time" type="time" :label="t('gradebook.schedule.start')" :error="err('start_time')" required />
          <BaseInput v-model="form.end_time" type="time" :label="t('gradebook.schedule.end')" :error="err('end_time')" required />
          <BaseSelect v-model="form.classroom_id" :options="roomOptions" :label="t('gradebook.schedule.room')" :error="err('classroom_id')" />
          <BaseSelect v-model="form.invigilator_staff_id" :options="staffOptions" :label="t('gradebook.schedule.invigilator')" :error="err('invigilator_staff_id')" />
        </div>
        <BaseInput v-model="form.notes" :label="t('gradebook.schedule.notes')" :error="err('notes')" />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('gradebook.cancel') }}</BaseButton>
        <BaseButton type="submit" form="exam-schedule-form" :loading="saving">{{ t('gradebook.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
