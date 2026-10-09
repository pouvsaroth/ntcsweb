<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddExamScoreModal from '@/components/admin/AddExamScoreModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { examScoresService, type ExamScoreEntry, type ExamScoreOption } from '@/services/examScores'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { exportTableAsImage } from '@/utils/tableImage'

const { t } = useI18n()
const auth = useAuthStore()

const canUpdate = computed(() => auth.can('exam-scores.update') || auth.can('exam-scores.manage-all'))

// --- Course / Class / Book pickers ----------------------------------------
// Only real combinations that actually have a scoreable application — see
// ExamScoreService::options() — so a picker never offers a choice that
// would list nobody. Each level narrows the ones below it. Also scopes the
// "Add Score" popup's own unscored list, so scoring can be done one class
// at a time.

const options = ref<ExamScoreOption[]>([])
const courseId = ref<number | null>(null)
const classId = ref<number | null>(null)
const bookId = ref<number | null>(null)

function uniqueOptions(rows: ExamScoreOption[], pick: (row: ExamScoreOption) => { id: number; label: string } | null) {
  const seen = new Map<number, string>()
  for (const row of rows) {
    const entry = pick(row)
    if (entry) seen.set(entry.id, entry.label)
  }
  return [...seen].map(([value, label]) => ({ value: String(value), label }))
}

const courseOptions = computed(() => uniqueOptions(options.value, (row) => ({ id: row.course_package.id, label: row.course_package.name })))

const classOptions = computed(() =>
  uniqueOptions(
    options.value.filter((row) => !courseId.value || row.course_package.id === courseId.value),
    (row) => ({ id: row.school_class.id, label: row.school_class.name }),
  ),
)

const bookOptions = computed(() =>
  uniqueOptions(
    options.value.filter(
      (row) => (!courseId.value || row.course_package.id === courseId.value) && (!classId.value || row.school_class.id === classId.value),
    ),
    (row) => (row.book ? { id: row.book.id, label: row.book.title } : null),
  ),
)

function onCourseChange(value: string) {
  courseId.value = value ? Number(value) : null
  classId.value = null
  bookId.value = null
}

function onClassChange(value: string) {
  classId.value = value ? Number(value) : null
  bookId.value = null
}

function onBookChange(value: string) {
  bookId.value = value ? Number(value) : null
}

const search = ref('')

// --- Main list: only students already scored — a gradebook, not a to-do -----
// list. Unscored students only ever appear inside the "Add Score" popup.

const rows = ref<ExamScoreEntry[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    rows.value = await examScoresService.list({
      course_package_id: courseId.value,
      class_id: classId.value,
      book_id: bookId.value,
      scored: true,
      search: search.value.trim() || undefined,
    })
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.grades.loadFailed')
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch([courseId, classId, bookId], () => void load())

let searchDebounce: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => void load(), 400)
})

// --- Add Score popup ---------------------------------------------------

const scoreModalOpen = ref(false)
const scoreModalFilters = computed(() => ({
  course_package_id: courseId.value,
  class_id: classId.value,
  book_id: bookId.value,
}))

async function onScoresSaved() {
  await load()
}

// --- Make-up Exam checkbox (already-scored rows) ------------------------
// Re-submits the row's own unchanged score with `make_up: true` — the same
// ExamScoreService::record() call the Add Score popup's own checkbox
// makes, just for a student flagged after the fact rather than at first
// scoring. Disabled once has_make_up is true: the server itself is
// idempotent (see the backend docblock), but there's no reason to let
// someone click it again.

const makeUpSaving = ref<Set<number>>(new Set())
const makeUpError = ref<string | null>(null)

async function toggleMakeUp(row: ExamScoreEntry) {
  if (row.has_make_up || row.score === null || makeUpSaving.value.has(row.exam_application_id)) return

  makeUpSaving.value = new Set(makeUpSaving.value).add(row.exam_application_id)
  makeUpError.value = null

  try {
    await examScoresService.record([{ exam_application_id: row.exam_application_id, score: Number(row.score), remark: row.remark, make_up: true }])
    await load()
  } catch (error) {
    makeUpError.value = error instanceof ApiRequestError ? error.message : t('admin.grades.saveFailed')
  } finally {
    const next = new Set(makeUpSaving.value)
    next.delete(row.exam_application_id)
    makeUpSaving.value = next
  }
}

// --- Row selection (checkbox before Enrollment Code) ---------------------
// Only rows still in the current list count — a filter change that drops a
// ticked row also drops it from the selection.

const selectedIds = ref<number[]>([])

function toggleSelected(id: number, checked: boolean) {
  selectedIds.value = checked ? [...selectedIds.value, id] : selectedIds.value.filter((selected) => selected !== id)
}

watch(rows, (current) => {
  const ids = new Set(current.map((row) => row.exam_application_id))
  selectedIds.value = selectedIds.value.filter((id) => ids.has(id))
})

/** Examination → Certificate's Mention colours, plus red for Fail (Certificate only lists passes). */
const mentionVariant: Record<string, 'success' | 'warning' | 'danger'> = {
  excellent: 'success',
  very_good: 'success',
  good: 'warning',
  fail: 'danger',
}

// --- Export to Image (ticked students only) ---------------------------------

const exporting = ref(false)
const exportError = ref<string | null>(null)

async function exportImage() {
  // In list order (exam date ascending), not the order they were ticked.
  const selected = rows.value.filter((row) => selectedIds.value.includes(row.exam_application_id))
  if (selected.length === 0) return

  exporting.value = true
  exportError.value = null
  try {
    await exportTableAsImage({
      title: t('admin.grades.exportTitle'),
      subtitle: `${t('admin.exams.exportedOn')} ${formatDate(new Date())}`,
      columns: [
        { label: t('admin.grades.columnStudent'), width: 200 },
        { label: t('admin.grades.columnBook'), width: 160, maxWidth: 260 },
        { label: t('admin.grades.columnExamDate'), width: 110 },
        { label: t('admin.grades.columnScore'), align: 'right', width: 80 },
        { label: t('admin.examCertificate.columnMention'), width: 110 },
      ],
      rows: selected.map((row) => [
        { text: row.student?.name ?? '—', bold: true },
        { text: row.book?.title ?? '—' },
        { text: row.exam_date ? formatDate(row.exam_date) : '—' },
        { text: row.score ?? '—', bold: true },
        row.mention ? { text: t(`admin.myScores.mentions.${row.mention}`), badge: mentionVariant[row.mention] } : { text: '—' },
      ]),
      emptyText: t('admin.grades.emptyMessage'),
      fileName: `examination-result-${new Date().toISOString().slice(0, 10)}.png`,
    })
  } catch {
    exportError.value = t('admin.exams.exportImageFailed')
  } finally {
    exporting.value = false
  }
}

const columns = [
  { key: 'enrollment_code', label: t('admin.grades.columnEnrollmentCode') },
  { key: 'student', label: t('admin.grades.columnStudent') },
  { key: 'course', label: t('admin.grades.columnCourse') },
  { key: 'class', label: t('admin.grades.columnClass') },
  { key: 'book', label: t('admin.grades.columnBook') },
  { key: 'exam_date', label: t('admin.grades.columnExamDate') },
  { key: 'score', label: t('admin.grades.columnScore') },
  { key: 'mention', label: t('admin.examCertificate.columnMention') },
  { key: 'remark', label: t('admin.grades.columnRemark') },
  { key: 'make_up', label: t('admin.grades.makeUpExam') },
]

onMounted(async () => {
  // Unguarded before: a failed options() call (e.g. a role that reaches
  // this tab — ExaminationTabs shows it unconditionally — but lacks
  // exam-scores.view) threw here, load() never ran, and since `loading`
  // starts true and only load()'s own finally block ever clears it, the
  // page spun forever with no error shown at all.
  try {
    options.value = await examScoresService.options()
  } catch (err) {
    loadError.value = err instanceof ApiRequestError ? err.message : t('admin.grades.loadFailed')
    loading.value = false
    return
  }

  await load()
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.grades.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.grades.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <BaseButton variant="outline" :loading="exporting" :disabled="selectedIds.length === 0" @click="exportImage">
          {{ t('admin.exams.exportImage') }}<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
        </BaseButton>
        <BaseButton v-if="canUpdate" @click="scoreModalOpen = true">{{ t('admin.grades.addScore') }}</BaseButton>
      </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseSelect
        :model-value="courseId !== null ? String(courseId) : ''"
        :options="courseOptions"
        :placeholder="t('admin.grades.allCourses')"
        :label="t('admin.grades.course')"
        @update:model-value="onCourseChange"
      />
      <BaseSelect
        :model-value="classId !== null ? String(classId) : ''"
        :options="classOptions"
        :placeholder="t('admin.grades.allClasses')"
        :label="t('admin.grades.class')"
        @update:model-value="onClassChange"
      />
      <BaseSelect
        :model-value="bookId !== null ? String(bookId) : ''"
        :options="bookOptions"
        :placeholder="t('admin.grades.allBooks')"
        :label="t('admin.grades.book')"
        @update:model-value="onBookChange"
      />
      <BaseInput v-model="search" :label="t('admin.grades.search')" :placeholder="t('admin.grades.searchPlaceholder')" />
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>
    <BaseAlert v-if="makeUpError" variant="danger" class="mb-4">{{ makeUpError }}</BaseAlert>
    <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>

    <BaseSpinner v-if="loading" class="mx-auto" />

    <!-- Cards on small screens — below sm: this replaces the DataTable entirely. -->
    <div v-if="!loading" class="sm:hidden">
      <p v-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.grades.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div
          v-for="row in rows"
          :key="row.exam_application_id"
          class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 flex-1 items-start gap-2">
              <input
                type="checkbox"
                class="mt-1 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :checked="selectedIds.includes(row.exam_application_id)"
                :aria-label="t('common.selectRow')"
                @change="toggleSelected(row.exam_application_id, ($event.target as HTMLInputElement).checked)"
              />
              <div class="min-w-0">
                <p class="truncate font-medium text-neutral-900">{{ row.student?.name ?? '—' }}</p>
                <p class="truncate text-xs text-neutral-500">{{ row.enrollment_code ?? '—' }}</p>
              </div>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-lg font-semibold text-neutral-900">{{ row.score ?? '—' }}</p>
              <BaseBadge v-if="row.mention" :variant="mentionVariant[row.mention]">{{ t(`admin.myScores.mentions.${row.mention}`) }}</BaseBadge>
            </div>
          </div>
          <p class="mt-2 truncate text-xs text-neutral-500">
            {{ [row.course_package?.name, row.school_class?.name, row.book?.title].filter(Boolean).join(' · ') || '—' }}
          </p>
          <div class="mt-1 flex items-center justify-between gap-2 text-xs text-neutral-500">
            <span>{{ t('admin.grades.columnExamDate') }}: <span class="font-medium text-neutral-800">{{ row.exam_date ? formatDate(row.exam_date) : '—' }}</span></span>
            <label class="flex items-center gap-1.5">
              <input
                type="checkbox"
                :checked="row.has_make_up"
                :disabled="!canUpdate || row.has_make_up || makeUpSaving.has(row.exam_application_id)"
                class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                @change="toggleMakeUp(row)"
              />
              {{ t('admin.grades.makeUpExam') }}
            </label>
          </div>
          <p v-if="row.remark" class="mt-1 text-xs text-neutral-500">{{ t('admin.grades.columnRemark') }}: {{ row.remark }}</p>
        </div>
      </div>
    </div>

    <DataTable
      v-if="!loading"
      class="hidden sm:block"
      :columns="columns"
      :rows="rows"
      row-key="exam_application_id"
      selectable
      :selected="selectedIds"
      :empty-message="t('admin.grades.emptyMessage')"
      @update:selected="selectedIds = $event as number[]"
    >
      <template #cell-enrollment_code="{ row }">{{ (row as ExamScoreEntry).enrollment_code ?? '—' }}</template>
      <template #cell-student="{ row }">{{ (row as ExamScoreEntry).student?.name ?? '—' }}</template>
      <template #cell-course="{ row }">{{ (row as ExamScoreEntry).course_package?.name ?? '—' }}</template>
      <template #cell-class="{ row }">{{ (row as ExamScoreEntry).school_class?.name ?? '—' }}</template>
      <template #cell-book="{ row }">{{ (row as ExamScoreEntry).book?.title ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ (row as ExamScoreEntry).exam_date ? formatDate((row as ExamScoreEntry).exam_date) : '—' }}</template>
      <template #cell-score="{ row }">{{ (row as ExamScoreEntry).score ?? '—' }}</template>
      <template #cell-mention="{ row }">
        <BaseBadge v-if="(row as ExamScoreEntry).mention" :variant="mentionVariant[(row as ExamScoreEntry).mention!]">
          {{ t(`admin.myScores.mentions.${(row as ExamScoreEntry).mention}`) }}
        </BaseBadge>
        <template v-else>—</template>
      </template>
      <template #cell-remark="{ row }">{{ (row as ExamScoreEntry).remark ?? '—' }}</template>
      <template #cell-make_up="{ row }">
        <input
          type="checkbox"
          :checked="(row as ExamScoreEntry).has_make_up"
          :disabled="!canUpdate || (row as ExamScoreEntry).has_make_up || makeUpSaving.has((row as ExamScoreEntry).exam_application_id)"
          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
          @change="toggleMakeUp(row as ExamScoreEntry)"
        />
      </template>
    </DataTable>

    <AddExamScoreModal v-model="scoreModalOpen" :filters="scoreModalFilters" @saved="onScoresSaved" />
  </div>
</template>
