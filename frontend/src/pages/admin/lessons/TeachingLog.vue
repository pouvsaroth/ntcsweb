<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { isoDate, lessonPlansService, type LessonOptions, type LogStatus, type PlanRow, type TeachingLogRow } from '@/services/lessonPlans'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Lesson plans > Teaching log: what was taught each day — done, partly or
 * not — with homework given. Picking the day's plan fills it in; linking the
 * curriculum lesson counts it in Curriculum progress.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canWrite = computed(() => auth.can('lesson-plans.write'))

const options = ref<LessonOptions>({ me: null, sees_all: false, classes: [], subjects: [], teachers: [] })
const today = new Date()
const filter = reactive({ from: isoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 30)), to: isoDate(today), staff_id: '', class_id: '' })
const rows = ref<TeachingLogRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const all = (label: string) => ({ value: '', label })
const teacherOptions = computed(() => options.value.teachers.map((x) => ({ value: String(x.id), label: x.name ?? '' })))
const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.name })))
const subjectOptions = computed(() => [all('—'), ...options.value.subjects.map((s) => ({ value: String(s.id), label: s.title }))])
const statusOptions = computed(() => (['done', 'partial', 'not_done'] as LogStatus[]).map((s) => ({ value: s, label: t(`lessonPlans.logStatus.${s}`) })))
const statusVariant = (s: LogStatus) => (s === 'done' ? 'success' : s === 'partial' ? 'warning' : 'danger')

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await lessonPlansService.logs({
      from: filter.from || undefined,
      to: filter.to || undefined,
      staff_id: filter.staff_id ? Number(filter.staff_id) : undefined,
      class_id: filter.class_id ? Number(filter.class_id) : undefined,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}
function set(key: keyof typeof filter, value: string) {
  filter[key] = value
  void load()
}

const columns = computed(() => [
  { key: 'date', label: t('lessonPlans.date') },
  { key: 'title', label: t('lessonPlans.taughtLesson') },
  { key: 'class', label: t('lessonPlans.class') },
  ...(options.value.sees_all ? [{ key: 'teacher', label: t('lessonPlans.teacher') }] : []),
  { key: 'status', label: t('lessonPlans.statusLabel') },
  ...(canWrite.value ? [{ key: 'actions', label: t('lessonPlans.actions'), align: 'text-right' }] : []),
])

// --- Form ------------------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<TeachingLogRow | null>(null)
const form = reactive({ staff_id: '', class_id: '', taught_on: '', lesson_plan_id: '', book_id: '', curriculum_lesson_id: '', title: '', status: 'done' as LogStatus, homework: '', note: '' })
const dayPlans = ref<PlanRow[]>([])
const lessons = ref<{ id: number; title: string }[]>([])
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const planOptions = computed(() => [all(t('lessonPlans.noPlan')), ...dayPlans.value.map((p) => ({ value: String(p.id), label: p.title }))])
const lessonOptions = computed(() => [all(lessons.value.length ? t('lessonPlans.noCurriculumLesson') : t('lessonPlans.noCurriculum')), ...lessons.value.map((l) => ({ value: String(l.id), label: l.title }))])

/** The plans of that class on that day, to link and fill from. */
watch(
  () => [form.class_id, form.taught_on, formOpen.value],
  async () => {
    dayPlans.value = formOpen.value && form.class_id && form.taught_on
      ? await lessonPlansService.plans({ class_id: Number(form.class_id), from: form.taught_on, to: form.taught_on }).catch(() => [])
      : []
  },
)
watch(
  () => [form.class_id, form.book_id],
  async () => {
    lessons.value = form.class_id && form.book_id ? await lessonPlansService.curriculumLessons(Number(form.class_id), Number(form.book_id)).catch(() => []) : []
  },
)
function onPlan(value: string) {
  form.lesson_plan_id = value
  const p = dayPlans.value.find((x) => String(x.id) === value)
  if (!p) return
  form.title = p.title
  form.book_id = p.book_id ? String(p.book_id) : ''
  form.curriculum_lesson_id = p.curriculum_lesson_id ? String(p.curriculum_lesson_id) : ''
}

function open(row: TeachingLogRow | null) {
  editing.value = row
  const v = (x: number | string | null | undefined) => (x === null || x === undefined ? '' : String(x))
  form.staff_id = row ? v(row.staff_id) : v(options.value.me ?? options.value.teachers[0]?.id)
  form.class_id = row ? v(row.class_id) : filter.class_id || v(options.value.classes[0]?.id)
  form.taught_on = row?.taught_on ?? isoDate(new Date())
  form.lesson_plan_id = v(row?.lesson_plan_id)
  form.book_id = v(row?.book_id)
  form.curriculum_lesson_id = v(row?.curriculum_lesson_id)
  form.title = row?.title ?? ''
  form.status = row?.status ?? 'done'
  form.homework = row?.homework ?? ''
  form.note = row?.note ?? ''
  errors.value = {}
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const input = {
    staff_id: form.staff_id ? Number(form.staff_id) : null,
    class_id: Number(form.class_id),
    book_id: form.book_id ? Number(form.book_id) : null,
    curriculum_lesson_id: form.curriculum_lesson_id ? Number(form.curriculum_lesson_id) : null,
    lesson_plan_id: form.lesson_plan_id ? Number(form.lesson_plan_id) : null,
    taught_on: form.taught_on,
    title: form.title.trim(),
    status: form.status,
    homework: form.homework.trim() || null,
    note: form.note.trim() || null,
  }
  try {
    if (editing.value) await lessonPlansService.updateLog(editing.value.id, input)
    else await lessonPlansService.createLog(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { title: [e instanceof ApiRequestError ? e.message : t('lessonPlans.saveFailed')] }
  } finally {
    saving.value = false
  }
}
async function remove(row: TeachingLogRow) {
  if (!(await confirmDialog.confirm({ message: t('lessonPlans.deleteLogConfirm'), danger: true }))) return
  await lessonPlansService.removeLog(row.id).catch(() => undefined)
  await load()
}

onMounted(async () => {
  try {
    options.value = await lessonPlansService.options()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseInput :model-value="filter.from" type="date" class="w-40" :label="t('lessonPlans.from')" @update:model-value="set('from', $event)" />
      <BaseInput :model-value="filter.to" type="date" class="w-40" :label="t('lessonPlans.to')" @update:model-value="set('to', $event)" />
      <BaseSelect v-if="options.sees_all" class="w-48" :model-value="filter.staff_id" :options="[all(t('lessonPlans.allTeachers')), ...teacherOptions]" @update:model-value="set('staff_id', $event)" />
      <BaseSelect class="w-44" :model-value="filter.class_id" :options="[all(t('lessonPlans.allClasses')), ...classOptions]" @update:model-value="set('class_id', $event)" />
      <BaseSpinner v-if="loading" class="mb-2 h-4 w-4" />
      <BaseButton v-if="canWrite" class="ml-auto" :disabled="options.classes.length === 0" @click="open(null)">{{ t('lessonPlans.addLog') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('lessonPlans.noLogs') }}</p>
      <div v-for="r in rows" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ r.title }}</p>
          <BaseBadge :variant="statusVariant(r.status)">{{ t(`lessonPlans.logStatus.${r.status}`) }}</BaseBadge>
        </div>
        <p class="text-xs text-neutral-500">{{ formatDate(r.taught_on) }} · {{ [r.class, r.subject, options.sees_all ? r.teacher : null].filter(Boolean).join(' · ') }}</p>
        <p v-if="r.homework" class="text-xs text-neutral-500">{{ t('lessonPlans.homework') }}: {{ r.homework }}</p>
        <div v-if="canWrite" class="mt-2 flex justify-end gap-3">
          <EditIconButton @click="open(r)" />
          <button type="button" class="text-sm font-medium text-danger-600" @click="remove(r)">{{ t('lessonPlans.delete') }}</button>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('lessonPlans.noLogs')">
        <template #cell-date="{ row }">{{ formatDate(row.taught_on) }}</template>
        <template #cell-title="{ row }">
          <p class="font-medium text-neutral-800">{{ row.title }}</p>
          <p class="text-xs text-neutral-500">{{ [row.subject, row.curriculum_lesson, row.homework ? `${t('lessonPlans.homework')}: ${row.homework}` : null].filter(Boolean).join(' · ') }}</p>
        </template>
        <template #cell-class="{ row }">{{ row.class }}</template>
        <template #cell-teacher="{ row }">{{ row.teacher }}</template>
        <template #cell-status="{ row }"><BaseBadge :variant="statusVariant(row.status)">{{ t(`lessonPlans.logStatus.${row.status}`) }}</BaseBadge></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as TeachingLogRow)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as TeachingLogRow)">{{ t('lessonPlans.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('lessonPlans.editLog') : t('lessonPlans.addLog')">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-if="options.sees_all" v-model="form.staff_id" :options="teacherOptions" :placeholder="t('lessonPlans.chooseTeacher')" required :label="t('lessonPlans.teacher')" :error="errors.staff_id?.[0]" />
          <BaseSelect v-model="form.class_id" :options="classOptions" :placeholder="t('lessonPlans.chooseClass')" required :label="t('lessonPlans.class')" :error="errors.class_id?.[0]" />
          <BaseInput v-model="form.taught_on" type="date" required :label="t('lessonPlans.date')" :error="errors.taught_on?.[0]" />
          <BaseSelect :model-value="form.lesson_plan_id" :options="planOptions" :label="t('lessonPlans.fromPlan')" :hint="dayPlans.length ? undefined : t('lessonPlans.noPlanThatDay')" @update:model-value="onPlan" />
          <BaseSelect v-model="form.book_id" :options="subjectOptions" :label="t('lessonPlans.subject')" />
          <BaseSelect v-model="form.curriculum_lesson_id" :options="lessonOptions" :label="t('lessonPlans.curriculumLesson')" :disabled="!form.book_id" />
        </div>
        <BaseInput v-model="form.title" required :label="t('lessonPlans.taughtLesson')" :error="errors.title?.[0]" />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('lessonPlans.statusLabel')" />
        <BaseInput v-model="form.homework" :label="t('lessonPlans.homework')" />
        <BaseInput v-model="form.note" :label="t('lessonPlans.note')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.class_id || !form.title.trim()" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
