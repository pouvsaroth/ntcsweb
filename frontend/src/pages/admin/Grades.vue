<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddExamScoreModal from '@/components/admin/AddExamScoreModal.vue'
import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { examScoresService, type ExamScoreEntry, type ExamScoreOption } from '@/services/examScores'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

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

const columns = [
  { key: 'enrollment_code', label: t('admin.grades.columnEnrollmentCode') },
  { key: 'student', label: t('admin.grades.columnStudent') },
  { key: 'course', label: t('admin.grades.columnCourse') },
  { key: 'class', label: t('admin.grades.columnClass') },
  { key: 'book', label: t('admin.grades.columnBook') },
  { key: 'exam_date', label: t('admin.grades.columnExamDate') },
  { key: 'score', label: t('admin.grades.columnScore') },
  { key: 'remark', label: t('admin.grades.columnRemark') },
]

onMounted(async () => {
  options.value = await examScoresService.options()
  await load()
})
</script>

<template>
  <div>
    <ExaminationTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.grades.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.grades.subtitle') }}</p>
      </div>
      <BaseButton v-if="canUpdate" @click="scoreModalOpen = true">{{ t('admin.grades.addScore') }}</BaseButton>
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

    <BaseSpinner v-if="loading" class="mx-auto" />

    <DataTable v-else :columns="columns" :rows="rows" row-key="exam_application_id" :empty-message="t('admin.grades.emptyMessage')">
      <template #cell-enrollment_code="{ row }">{{ (row as ExamScoreEntry).enrollment_code ?? '—' }}</template>
      <template #cell-student="{ row }">{{ (row as ExamScoreEntry).student?.name ?? '—' }}</template>
      <template #cell-course="{ row }">{{ (row as ExamScoreEntry).course_package?.name ?? '—' }}</template>
      <template #cell-class="{ row }">{{ (row as ExamScoreEntry).school_class?.name ?? '—' }}</template>
      <template #cell-book="{ row }">{{ (row as ExamScoreEntry).book?.title ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ (row as ExamScoreEntry).exam_date ? formatDate((row as ExamScoreEntry).exam_date) : '—' }}</template>
      <template #cell-score="{ row }">{{ (row as ExamScoreEntry).score ?? '—' }}</template>
      <template #cell-remark="{ row }">{{ (row as ExamScoreEntry).remark ?? '—' }}</template>
    </DataTable>

    <AddExamScoreModal v-model="scoreModalOpen" :filters="scoreModalFilters" @saved="onScoresSaved" />
  </div>
</template>
