<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import {
  REMEDIAL_OUTCOMES,
  remedialService,
  studentResultsService,
  type GradebookPick,
  type RemedialBelowRow,
  type RemedialOutcome,
  type RemedialOverview,
  type RemedialSession,
  type RosterStudent,
} from '@/services/gradebook'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Examination > Remedial Teaching (docs/academics/gradebook.md, 7e): for a
 * class and term, the students below the pass mark in each subject (from the
 * scores entered so far), and the remedial sessions held for them — who came
 * and the outcome. Teachers record sessions for the subjects they enter
 * scores in; everyone who can open the class sees them.
 */
const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const pick = ref<GradebookPick | null>(null)
const data = ref<RemedialOverview | null>(null)
const roster = ref<RosterStudent[]>([])
// Spinner until the picker has chosen a class and the first data is in.
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const subjectFilter = ref('')

async function load() {
  const p = pick.value
  if (!p?.termId || !p.classId) {
    data.value = null
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    const [overview, students] = await Promise.all([remedialService.overview(p.classId, p.termId), studentResultsService.students(p.classId)])
    data.value = overview
    roster.value = students
    if (subjectFilter.value && !overview.subjects.some((s) => String(s.id) === subjectFilter.value)) subjectFilter.value = ''
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

function onPick(p: GradebookPick) {
  pick.value = p
  notice.value = null
  void load()
}

const subjectOptions = computed(() => [
  { value: '', label: t('gradebook.remedial.allSubjects') },
  ...(data.value?.subjects ?? []).map((s) => ({ value: String(s.id), label: s.title })),
])
const bySubject = <T extends { subject: { id: number } }>(rows: T[]) => (subjectFilter.value ? rows.filter((r) => String(r.subject.id) === subjectFilter.value) : rows)
const below = computed(() => bySubject(data.value?.below ?? []).map((r) => ({ ...r, key: `${r.subject.id}-${r.student.id}` })))
const sessions = computed(() => bySubject(data.value?.sessions ?? []))
const recordable = computed(() => (data.value?.subjects ?? []).filter((s) => s.can_record))
const canRecord = (subjectId: number) => recordable.value.some((s) => s.id === subjectId)

const outcomeVariant: Record<RemedialOutcome, 'success' | 'neutral' | 'warning'> = { improved: 'success', no_change: 'neutral', needs_more: 'warning' }
const pct = (n: number | null) => (n === null ? '—' : `${Math.round(n * 100) / 100}%`)
const when = (s: RemedialSession) => [formatDate(s.session_date), s.start_time && s.end_time ? `${s.start_time}–${s.end_time}` : s.start_time].filter(Boolean).join(' · ')

const columns = computed(() => [
  { key: 'student', label: t('gradebook.remedial.students') },
  { key: 'subject', label: t('gradebook.subject') },
  { key: 'percent', label: '%' },
  { key: 'sessions', label: t('gradebook.remedial.sessions') },
  { key: 'plan', label: '', align: 'text-right' },
])

// --- The session form ---------------------------------------------------------

interface Line {
  checked: boolean
  attended: boolean
  outcome: RemedialOutcome | ''
  remark: string
}

const formOpen = ref(false)
const editing = ref<RemedialSession | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const err = (key: string) => errors.value[key]?.[0]
const form = reactive({ book_id: '', staff_id: '', session_date: '', start_time: '', end_time: '', topic: '', notes: '' })
const lines = ref<Record<number, Line>>({})

const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const blankLine = (): Line => ({ checked: false, attended: false, outcome: '', remark: '' })
const belowIn = (subjectId: string) => new Map((data.value?.below ?? []).filter((r) => String(r.subject.id) === subjectId).map((r) => [r.student.id, r.percent]))
const belowNow = computed(() => belowIn(form.book_id))

/** Students below the mark in the chosen subject first, then the rest A–Z. */
const formStudents = computed(() => [...roster.value].sort((a, b) => Number(belowNow.value.has(b.id)) - Number(belowNow.value.has(a.id)) || a.name.localeCompare(b.name)))
const pickedCount = computed(() => Object.values(lines.value).filter((l) => l.checked).length)

function tickBelow() {
  for (const s of roster.value) lines.value[s.id] = { ...(lines.value[s.id] ?? blankLine()), checked: belowNow.value.has(s.id) }
}

function openNew(subjectId?: number) {
  editing.value = null
  errors.value = {}
  saveError.value = null
  const first = subjectId ?? (subjectFilter.value && canRecord(Number(subjectFilter.value)) ? Number(subjectFilter.value) : recordable.value[0]?.id)
  Object.assign(form, { book_id: first ? String(first) : '', staff_id: '', session_date: today(), start_time: '', end_time: '', topic: '', notes: '' })
  lines.value = Object.fromEntries(roster.value.map((s) => [s.id, blankLine()]))
  tickBelow()
  formOpen.value = true
}

function openEdit(s: RemedialSession) {
  editing.value = s
  errors.value = {}
  saveError.value = null
  Object.assign(form, {
    book_id: String(s.subject.id),
    staff_id: s.teacher ? String(s.teacher.id) : '',
    session_date: s.session_date,
    start_time: s.start_time ?? '',
    end_time: s.end_time ?? '',
    topic: s.topic,
    notes: s.notes ?? '',
  })
  lines.value = Object.fromEntries(roster.value.map((st) => [st.id, blankLine()]))
  for (const r of s.students) lines.value[r.student_id] = { checked: true, attended: r.attended === true, outcome: r.outcome ?? '', remark: r.remark ?? '' }
  formOpen.value = true
}

function onSubject(value: string) {
  form.book_id = value
  // A new session: re-tick the students below the mark in that subject.
  if (!editing.value) tickBelow()
}

const formSubjectOptions = computed(() => recordable.value.map((s) => ({ value: String(s.id), label: s.title })))
const teacherOptions = computed(() => [{ value: '', label: t('gradebook.none') }, ...(data.value?.teachers ?? []).map((s) => ({ value: String(s.id), label: s.name }))])
const outcomeOptions = computed(() => [{ value: '', label: t('gradebook.none') }, ...REMEDIAL_OUTCOMES.map((o) => ({ value: o, label: t(`gradebook.remedial.outcomes.${o}`) }))])

async function save() {
  const p = pick.value
  if (!p?.classId || !p.termId) return
  errors.value = {}
  saveError.value = null
  const students = Object.entries(lines.value)
    .filter(([, l]) => l.checked)
    .map(([id, l]) => ({
      student_id: Number(id),
      // Not ticked as came: unknown until an outcome is recorded.
      attended: l.attended ? true : l.outcome ? false : null,
      outcome: l.outcome || null,
      remark: l.remark.trim() || null,
    }))
  if (students.length === 0) {
    saveError.value = t('gradebook.remedial.pickStudents')
    return
  }
  saving.value = true
  const input = {
    class_id: p.classId,
    academic_term_id: p.termId,
    book_id: Number(form.book_id),
    // A new session with no teacher picked: the server puts the person recording it.
    staff_id: form.staff_id ? Number(form.staff_id) : editing.value ? null : undefined,
    session_date: form.session_date,
    start_time: form.start_time || null,
    end_time: form.end_time || null,
    topic: form.topic.trim(),
    notes: form.notes.trim() || null,
    students,
  }
  try {
    if (editing.value) await remedialService.update(editing.value.id, input)
    else await remedialService.create(input)
    formOpen.value = false
    notice.value = t('gradebook.remedial.saved')
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(s: RemedialSession) {
  if (!(await confirmDialog.confirm({ message: t('gradebook.remedial.deleteConfirm'), danger: true }))) return
  error.value = null
  try {
    await remedialService.remove(s.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.deleteFailed')
  }
}
const studentsError = computed(() => err('students') ?? Object.entries(errors.value).find(([k]) => k.startsWith('students.'))?.[1]?.[0])
</script>

<template>
  <div>
    <GradebookPicker class="mb-4" @change="onPick" />

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="data">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <p class="text-sm text-neutral-500">{{ t('gradebook.remedial.hint', { pass: data.pass_mark }) }}</p>
        <BaseSelect v-model="subjectFilter" class="w-full sm:ml-auto sm:w-56" :options="subjectOptions" />
      </div>

      <!-- Students below the pass mark -->
      <h2 class="mb-2 text-base font-semibold text-neutral-900">{{ t('gradebook.remedial.below') }} ({{ below.length }})</h2>

      <!-- Cards on a phone (below sm) -->
      <div class="mb-6 sm:hidden">
        <p v-if="below.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
          {{ t('gradebook.remedial.noneBelow') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="r in below" :key="r.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-semibold text-neutral-900">{{ r.student.name }}</p>
                <p class="text-xs text-neutral-500">{{ r.student.code }} · {{ r.subject.title }}</p>
              </div>
              <span class="text-sm font-semibold text-red-600">{{ pct(r.percent) }}</span>
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-1 text-xs">
              <BaseBadge v-if="r.sheet_status !== 'approved'" variant="neutral">{{ t('gradebook.remedial.draftScores') }}</BaseBadge>
              <BaseBadge v-if="!r.complete" variant="warning">{{ t('gradebook.remedial.incomplete') }}</BaseBadge>
              <span v-if="r.sessions" class="text-neutral-600">{{ t('gradebook.remedial.sessionsN', { n: r.sessions }) }}</span>
              <BaseBadge v-if="r.last_outcome" :variant="outcomeVariant[r.last_outcome]">{{ t(`gradebook.remedial.outcomes.${r.last_outcome}`) }}</BaseBadge>
            </div>
            <button v-if="canRecord(r.subject.id)" type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="openNew(r.subject.id)">
              {{ t('gradebook.remedial.addFor') }}
            </button>
          </div>
        </div>
      </div>

      <div class="mb-6 hidden sm:block">
        <DataTable :columns="columns" :rows="below" row-key="key" :empty-message="t('gradebook.remedial.noneBelow')">
          <template #cell-student="{ row }">
            <p class="font-medium text-neutral-900">{{ row.student.name }}</p>
            <p class="text-xs text-neutral-500">{{ row.student.code }}</p>
          </template>
          <template #cell-subject="{ row }">{{ row.subject.title }}</template>
          <template #cell-percent="{ row }">
            <span class="font-semibold text-red-600">{{ pct(row.percent) }}</span>
            <span v-if="row.band" class="ml-1 text-xs text-neutral-500">{{ row.band }}</span>
            <div class="mt-0.5 flex flex-wrap gap-1">
              <BaseBadge v-if="row.sheet_status !== 'approved'" variant="neutral">{{ t('gradebook.remedial.draftScores') }}</BaseBadge>
              <BaseBadge v-if="!row.complete" variant="warning">{{ t('gradebook.remedial.incomplete') }}</BaseBadge>
            </div>
          </template>
          <template #cell-sessions="{ row }">
            <span v-if="row.sessions" class="text-sm text-neutral-700">{{ t('gradebook.remedial.sessionsN', { n: row.sessions }) }}</span>
            <span v-else class="text-neutral-400">—</span>
            <BaseBadge v-if="row.last_outcome" class="ml-1" :variant="outcomeVariant[(row as RemedialBelowRow).last_outcome!]">{{ t(`gradebook.remedial.outcomes.${row.last_outcome}`) }}</BaseBadge>
          </template>
          <template #cell-plan="{ row }">
            <button v-if="canRecord(row.subject.id)" type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openNew(row.subject.id)">
              {{ t('gradebook.remedial.addFor') }}
            </button>
          </template>
        </DataTable>
      </div>

      <!-- Sessions -->
      <div class="mb-2 flex items-center justify-between gap-2">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('gradebook.remedial.sessions') }} ({{ sessions.length }})</h2>
        <BaseButton v-if="recordable.length" size="sm" @click="openNew()">{{ t('gradebook.remedial.add') }}</BaseButton>
      </div>
      <p v-if="sessions.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
        {{ t('gradebook.remedial.noSessions') }}
      </p>
      <!-- One card per session; the same on phone and desktop (two per row from lg). -->
      <div v-else class="grid gap-3 lg:grid-cols-2">
        <div v-for="s in sessions" :key="s.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-neutral-900">{{ s.topic }}</p>
              <p class="text-xs text-neutral-500">{{ s.subject.title }} · {{ when(s) }}<template v-if="s.teacher"> · {{ s.teacher.name }}</template></p>
            </div>
            <span class="shrink-0 text-xs text-neutral-500">{{ t('gradebook.remedial.studentsN', { n: s.students.length }) }}</span>
          </div>
          <p v-if="s.notes" class="mt-1 text-sm whitespace-pre-line text-neutral-600">{{ s.notes }}</p>
          <ul class="mt-2 divide-y divide-neutral-100 text-sm">
            <li v-for="r in s.students" :key="r.student_id" class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5">
              <span class="font-medium text-neutral-800">{{ r.name }}</span>
              <span class="text-xs text-neutral-500">{{ t('gradebook.remedial.percentBefore') }} {{ pct(r.percent_before) }}</span>
              <span v-if="r.attended !== null" class="text-xs" :class="r.attended ? 'text-green-700' : 'text-red-600'">{{ r.attended ? '✓' : '✗' }} {{ t('gradebook.remedial.attended') }}</span>
              <BaseBadge v-if="r.outcome" class="ml-auto" :variant="outcomeVariant[r.outcome]">{{ t(`gradebook.remedial.outcomes.${r.outcome}`) }}</BaseBadge>
              <p v-if="r.remark" class="w-full text-xs text-neutral-500">{{ r.remark }}</p>
            </li>
          </ul>
          <div v-if="s.can_edit" class="mt-3 flex gap-3 text-sm">
            <button type="button" class="font-medium text-secondary-700 hover:underline" @click="openEdit(s)">{{ t('gradebook.edit') }}</button>
            <button type="button" class="ml-auto text-red-600 hover:underline" @click="remove(s)">{{ t('gradebook.delete') }}</button>
          </div>
        </div>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="editing ? t('gradebook.remedial.edit') : t('gradebook.remedial.addFor')" size="lg">
      <form id="remedial-form" class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect :model-value="form.book_id" :options="formSubjectOptions" :label="t('gradebook.remedial.subject')" :error="err('book_id')" required @update:model-value="onSubject" />
          <BaseSelect v-model="form.staff_id" :options="teacherOptions" :label="t('gradebook.remedial.teacher')" :error="err('staff_id')" />
          <BaseInput v-model="form.session_date" type="date" :label="t('gradebook.remedial.date')" :error="err('session_date')" required />
          <div class="grid grid-cols-2 gap-2">
            <BaseInput v-model="form.start_time" type="time" :label="t('gradebook.remedial.start')" :error="err('start_time')" />
            <BaseInput v-model="form.end_time" type="time" :label="t('gradebook.remedial.end')" :error="err('end_time')" />
          </div>
        </div>
        <BaseInput v-model="form.topic" :label="t('gradebook.remedial.topic')" :error="err('topic')" required />
        <BaseInput v-model="form.notes" :label="t('gradebook.remedial.notes')" :error="err('notes')" />

        <fieldset>
          <legend class="text-sm font-medium text-neutral-700">{{ t('gradebook.remedial.students') }} ({{ pickedCount }})</legend>
          <p class="mb-2 text-xs text-neutral-500">{{ t('gradebook.remedial.studentsHint') }}</p>
          <p v-if="studentsError" class="mb-2 text-xs text-red-600">{{ studentsError }}</p>
          <div class="max-h-80 divide-y divide-neutral-100 overflow-y-auto rounded-md border border-neutral-200">
            <div v-for="s in formStudents" :key="s.id" class="p-2">
              <label class="flex items-center gap-2 text-sm">
                <input v-model="lines[s.id]!.checked" type="checkbox" />
                <span class="font-medium text-neutral-800">{{ s.name }}</span>
                <span class="text-xs text-neutral-500">{{ s.code }}</span>
                <span v-if="belowNow.has(s.id)" class="ml-auto text-xs font-medium text-red-600">{{ t('gradebook.remedial.belowMark') }} {{ pct(belowNow.get(s.id) ?? null) }}</span>
              </label>
              <div v-if="lines[s.id]!.checked" class="mt-2 grid gap-2 pl-6 sm:grid-cols-[auto_10rem_1fr] sm:items-center">
                <label class="flex items-center gap-2 text-xs text-neutral-700"><input v-model="lines[s.id]!.attended" type="checkbox" /> {{ t('gradebook.remedial.attended') }}</label>
                <BaseSelect v-model="lines[s.id]!.outcome" :options="outcomeOptions" :placeholder="t('gradebook.remedial.outcome')" />
                <BaseInput v-model="lines[s.id]!.remark" :placeholder="t('gradebook.remedial.remark')" />
              </div>
            </div>
          </div>
        </fieldset>
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('gradebook.cancel') }}</BaseButton>
        <BaseButton type="submit" form="remedial-form" :loading="saving">{{ t('gradebook.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
