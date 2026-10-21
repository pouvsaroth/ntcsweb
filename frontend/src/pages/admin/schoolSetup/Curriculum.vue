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
import { curriculumService, gradeLevelsService, type Curriculum, type CurriculumLesson, type CurriculumSubject, type GradeLevel } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * School setup > Curriculum: per grade level, its subjects (the school's
 * Books) with study hours per week, and each subject's lessons with hours.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('curriculum.manage'))

const grades = ref<GradeLevel[]>([])
const gradeId = ref('')
const data = ref<Curriculum | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const gradeOptions = computed(() => grades.value.map((g) => ({ value: String(g.id), label: g.name })))

async function load() {
  if (!gradeId.value) return
  loading.value = true
  error.value = null
  try {
    data.value = await curriculumService.show(Number(gradeId.value))
    if (lessonsFor.value) lessonsFor.value = data.value.subjects.find((s) => s.book_id === lessonsFor.value!.book_id) ?? null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

function onGrade(value: string) {
  gradeId.value = value
  void load()
}

const hours = (n: number | null) => (n === null ? '—' : String(n))

const columns = computed(() => [
  { key: 'title', label: t('schoolSetup.curriculum.subject') },
  { key: 'hours', label: t('schoolSetup.curriculum.hoursPerWeek') },
  { key: 'lessons', label: t('schoolSetup.curriculum.lessons') },
  { key: 'actions', label: t('schoolSetup.actions'), align: 'text-right' },
])

// --- Subject (add / change hours) -----------------------------------------------
const subjectOpen = ref(false)
const subjectEditing = ref<CurriculumSubject | null>(null)
const subjectForm = reactive({ book_id: '', hours_per_week: '' })
const subjectErrors = ref<Record<string, string[]>>({})
const saving = ref(false)
const subjectOptions = computed(() => (data.value?.available_subjects ?? []).map((b) => ({ value: String(b.id), label: b.title })))

function openSubject(subject: CurriculumSubject | null) {
  subjectEditing.value = subject
  subjectForm.book_id = subject ? String(subject.book_id) : ''
  subjectForm.hours_per_week = subject?.hours_per_week != null ? String(subject.hours_per_week) : ''
  subjectErrors.value = {}
  subjectOpen.value = true
}

async function saveSubject() {
  saving.value = true
  subjectErrors.value = {}
  try {
    await curriculumService.saveSubject(Number(gradeId.value), Number(subjectForm.book_id), subjectForm.hours_per_week === '' ? null : Number(subjectForm.hours_per_week))
    subjectOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) subjectErrors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    saving.value = false
  }
}

async function removeSubject(subject: CurriculumSubject) {
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.curriculum.removeConfirm', { name: subject.title }), danger: true }))) return
  try {
    await curriculumService.removeSubject(Number(gradeId.value), subject.book_id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.deleteFailed')
  }
}

// --- Lessons of one subject -----------------------------------------------------
const lessonsFor = ref<CurriculumSubject | null>(null)
const lessonForm = reactive({ title: '', hours: '', description: '' })
const lessonEditing = ref<CurriculumLesson | null>(null)
const lessonErrors = ref<Record<string, string[]>>({})
const lessonSaving = ref(false)
const lessonsOpen = computed({
  get: () => lessonsFor.value !== null,
  set: (open: boolean) => {
    if (!open) lessonsFor.value = null
  },
})

function openLessons(subject: CurriculumSubject) {
  lessonsFor.value = subject
  editLesson(null)
}

function editLesson(lesson: CurriculumLesson | null) {
  lessonEditing.value = lesson
  lessonForm.title = lesson?.title ?? ''
  lessonForm.hours = lesson?.hours != null ? String(lesson.hours) : ''
  lessonForm.description = lesson?.description ?? ''
  lessonErrors.value = {}
}

async function saveLesson() {
  if (!lessonsFor.value) return
  lessonSaving.value = true
  lessonErrors.value = {}
  const input = { title: lessonForm.title.trim(), hours: lessonForm.hours === '' ? null : Number(lessonForm.hours), description: lessonForm.description.trim() || null }
  try {
    if (lessonEditing.value) await curriculumService.updateLesson(lessonEditing.value.id, input)
    else await curriculumService.createLesson({ ...input, grade_level_id: Number(gradeId.value), book_id: lessonsFor.value.book_id })
    editLesson(null)
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) lessonErrors.value = e.errors
    else lessonErrors.value = { title: [e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')] }
  } finally {
    lessonSaving.value = false
  }
}

async function removeLesson(lesson: CurriculumLesson) {
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.curriculum.deleteLessonConfirm', { name: lesson.title }), danger: true }))) return
  await curriculumService.removeLesson(lesson.id).catch(() => undefined)
  await load()
}

onMounted(async () => {
  try {
    grades.value = await gradeLevelsService.list(true)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  }
  if (grades.value.length) {
    gradeId.value = String(grades.value[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-56" :model-value="gradeId" :options="gradeOptions" :placeholder="t('schoolSetup.chooseGrade')" @update:model-value="onGrade" />
      <p v-if="data" class="text-sm text-neutral-500">{{ t('schoolSetup.curriculum.total', { hours: data.hours_per_week, count: data.subjects.length }) }}</p>
      <BaseButton v-if="canManage && data" class="ml-auto" :disabled="subjectOptions.length === 0" @click="openSubject(null)">{{ t('schoolSetup.curriculum.addSubject') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && grades.length === 0" variant="info" class="mb-4">
      {{ t('schoolSetup.noGrades') }}
      <RouterLink to="/admin/school-setup/grade-levels" class="font-medium underline">{{ t('schoolSetup.tabs.gradeLevels') }}</RouterLink>
    </BaseAlert>
    <p v-if="data" class="mb-3 text-xs text-neutral-500">
      {{ t('schoolSetup.curriculum.subjectsHint') }}
      <RouterLink to="/admin/books" class="text-secondary-700 hover:underline">{{ t('schoolSetup.curriculum.manageSubjects') }}</RouterLink>
    </p>

    <template v-if="gradeId">
      <!-- Cards on a phone (below sm) -->
      <div class="sm:hidden">
        <div v-if="loading && !data" class="flex justify-center py-10"><BaseSpinner /></div>
        <p v-else-if="data && data.subjects.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('schoolSetup.curriculum.empty') }}
        </p>
        <div v-else-if="data" class="space-y-2">
          <div v-for="row in data.subjects" :key="row.book_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <p class="text-sm font-semibold text-neutral-800">{{ row.title }}</p>
            <p class="text-xs text-neutral-500">
              {{ t('schoolSetup.curriculum.hoursWeekN', { hours: hours(row.hours_per_week) }) }} ·
              {{ t('schoolSetup.curriculum.lessonsN', { count: row.lessons.length, hours: row.lessons_hours }) }}
            </p>
            <div class="mt-2 flex flex-wrap justify-end gap-3">
              <button type="button" class="text-sm font-medium text-secondary-700" @click="openLessons(row)">{{ t('schoolSetup.curriculum.lessons') }}</button>
              <template v-if="canManage">
                <EditIconButton @click="openSubject(row)" />
                <button type="button" class="text-sm font-medium text-danger-600" @click="removeSubject(row)">{{ t('schoolSetup.remove') }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="data?.subjects ?? []" row-key="book_id" :loading="loading && !data" :empty-message="t('schoolSetup.curriculum.empty')">
          <template #cell-title="{ row }"><span class="font-medium text-neutral-800">{{ row.title }}</span></template>
          <template #cell-hours="{ row }">{{ hours(row.hours_per_week) }}</template>
          <template #cell-lessons="{ row }">
            <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openLessons(row as CurriculumSubject)">
              {{ t('schoolSetup.curriculum.lessonsN', { count: row.lessons.length, hours: row.lessons_hours }) }}
            </button>
          </template>
          <template #cell-actions="{ row }">
            <div v-if="canManage" class="flex justify-end gap-2">
              <EditIconButton @click="openSubject(row as CurriculumSubject)" />
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSubject(row as CurriculumSubject)">{{ t('schoolSetup.remove') }}</button>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <!-- Add a subject / change its hours -->
    <BaseModal v-model="subjectOpen" size="sm" :title="subjectEditing ? subjectEditing.title : t('schoolSetup.curriculum.addSubject')">
      <form class="space-y-4" @submit.prevent="saveSubject">
        <BaseSelect
          v-if="!subjectEditing"
          v-model="subjectForm.book_id"
          :options="subjectOptions"
          :placeholder="t('schoolSetup.choose')"
          required
          :label="t('schoolSetup.curriculum.subject')"
          :error="subjectErrors.book_id?.[0]"
        />
        <BaseInput v-model="subjectForm.hours_per_week" type="number" :label="t('schoolSetup.curriculum.hoursPerWeek')" :error="subjectErrors.hours_per_week?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="subjectOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!subjectForm.book_id" @click="saveSubject">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Lessons of one subject -->
    <BaseModal v-model="lessonsOpen" :title="lessonsFor ? t('schoolSetup.curriculum.lessonsOf', { name: lessonsFor.title }) : ''">
      <div v-if="lessonsFor" class="space-y-4">
        <p v-if="lessonsFor.lessons.length === 0" class="text-sm text-neutral-500">{{ t('schoolSetup.curriculum.noLessons') }}</p>
        <ol v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="(lesson, i) in lessonsFor.lessons" :key="lesson.id" class="flex items-start gap-3 px-3 py-2">
            <span class="w-6 shrink-0 text-sm text-neutral-400">{{ i + 1 }}.</span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-neutral-800">{{ lesson.title }}</p>
              <p v-if="lesson.description" class="text-xs text-neutral-500">{{ lesson.description }}</p>
            </div>
            <span class="shrink-0 text-xs text-neutral-500">{{ lesson.hours != null ? t('schoolSetup.curriculum.hoursN', { hours: lesson.hours }) : '' }}</span>
            <div v-if="canManage" class="flex shrink-0 gap-2">
              <EditIconButton @click="editLesson(lesson)" />
              <button type="button" class="text-xs font-medium text-danger-600" @click="removeLesson(lesson)">{{ t('schoolSetup.delete') }}</button>
            </div>
          </li>
        </ol>

        <form v-if="canManage" class="space-y-3 rounded-lg bg-neutral-50 p-3" @submit.prevent="saveLesson">
          <p class="text-sm font-medium text-neutral-700">{{ lessonEditing ? t('schoolSetup.curriculum.editLesson') : t('schoolSetup.curriculum.addLesson') }}</p>
          <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
            <BaseInput v-model="lessonForm.title" required :label="t('schoolSetup.curriculum.lessonTitle')" :error="lessonErrors.title?.[0] ?? lessonErrors.book_id?.[0]" />
            <BaseInput v-model="lessonForm.hours" type="number" :label="t('schoolSetup.curriculum.hours')" :error="lessonErrors.hours?.[0]" />
          </div>
          <BaseInput v-model="lessonForm.description" :label="t('schoolSetup.description')" />
          <div class="flex justify-end gap-2">
            <BaseButton v-if="lessonEditing" variant="ghost" @click="editLesson(null)">{{ t('schoolSetup.cancel') }}</BaseButton>
            <BaseButton type="submit" :loading="lessonSaving" :disabled="!lessonForm.title.trim()">{{ lessonEditing ? t('common.save') : t('schoolSetup.curriculum.addLesson') }}</BaseButton>
          </div>
        </form>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="lessonsOpen = false">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
