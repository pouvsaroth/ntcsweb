<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import {
  studentsService,
  studyingExportColumns,
  type StudyingExportColumn,
  type StudyingStudentsList,
  type StudyingStudentsQuery,
} from '@/services/students'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { exportTableAsImage } from '@/utils/tableImage'

/**
 * Students > Export studying students: tick the columns, see the total and
 * female count, then download Excel / PDF (built on the server) or an image
 * (drawn here). Uses the Students page's search, class and year.
 */
const props = defineProps<{
  modelValue: boolean
  filters: { search: string; class_id: string; academic_year_id: string }
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const DEFAULT_COLUMNS: StudyingExportColumn[] = ['code', 'name', 'gender', 'class', 'phone']
const STORAGE_KEY = 'students.studyingExport.columns'

/** The last ticked columns on this browser, or the defaults. */
function savedColumns(): StudyingExportColumn[] {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null') as unknown
    if (Array.isArray(saved)) {
      const valid = studyingExportColumns.filter((c) => saved.includes(c))
      if (valid.length) return valid
    }
  } catch {
    // Storage blocked or bad value — fall back to the defaults.
  }
  return [...DEFAULT_COLUMNS]
}

const picked = ref<StudyingExportColumn[]>(savedColumns())
watch(picked, (columns) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(columns))
  } catch {
    // Not remembered this time; nothing else depends on it.
  }
})

function toggle(column: StudyingExportColumn): void {
  picked.value = picked.value.includes(column) ? picked.value.filter((c) => c !== column) : studyingExportColumns.filter((c) => c === column || picked.value.includes(c))
}

const query = computed<StudyingStudentsQuery>(() => ({
  columns: picked.value.join(','),
  search: props.filters.search.trim(),
  class_id: props.filters.class_id,
  academic_year_id: props.filters.academic_year_id,
}))

// --- Counts (and the rows, for the image) -------------------------------------

const list = ref<StudyingStudentsList | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
let requestId = 0

async function load(): Promise<void> {
  if (!props.modelValue || picked.value.length === 0) return
  const id = ++requestId
  loading.value = true
  error.value = null
  try {
    const result = await studentsService.studying(query.value)
    if (id === requestId) list.value = result
  } catch (e) {
    if (id === requestId) error.value = e instanceof ApiRequestError ? e.message : t('admin.students.studyingExport.failed')
  } finally {
    if (id === requestId) loading.value = false
  }
}

// A text key, so a re-render of the page (new `filters` object, same values) doesn't reload.
watch(() => (props.modelValue ? JSON.stringify(query.value) : ''), load, { immediate: true })

// --- Export ----------------------------------------------------------------

const exporting = ref<'excel' | 'pdf' | 'image' | null>(null)
const canExport = computed(() => picked.value.length > 0 && !loading.value && (list.value?.total ?? 0) > 0 && exporting.value === null)

function fileName(extension: string): string {
  return `studying-students-${new Date().toISOString().slice(0, 10)}.${extension}`
}

async function download(format: 'excel' | 'pdf'): Promise<void> {
  exporting.value = format
  error.value = null
  try {
    await studentsService.downloadStudying(format, query.value, fileName(format === 'excel' ? 'xlsx' : 'pdf'))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.students.studyingExport.failed')
  } finally {
    exporting.value = null
  }
}

const WIDE: StudyingExportColumn[] = ['address', 'guardian', 'class', 'course']

async function exportImage(): Promise<void> {
  const data = list.value
  if (!data) return
  exporting.value = 'image'
  error.value = null
  try {
    await exportTableAsImage({
      title: t('admin.students.studyingExport.listTitle'),
      subtitle: [
        `${t('admin.students.studyingExport.total')}: ${data.total}`,
        `${t('admin.students.studyingExport.female')}: ${data.female}`,
        `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`,
      ].join('  ·  '),
      columns: [
        { label: t('admin.students.studyingExport.no'), width: 44, align: 'center' },
        ...data.columns.map((column) => ({
          label: t(`admin.students.studyingExport.columns.${column}`),
          width: 70,
          maxWidth: WIDE.includes(column) ? 260 : undefined,
        })),
      ],
      rows: data.rows.map((row, index) => [{ text: String(index + 1) }, ...data.columns.map((column) => ({ text: row[column] ?? '' }))]),
      emptyText: '—',
      fileName: fileName('png'),
    })
  } catch {
    error.value = t('admin.attendance.exportImageFailed')
  } finally {
    exporting.value = null
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.students.studyingExport.title')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.students.studyingExport.intro') }}</p>

    <div class="mb-4 grid grid-cols-2 gap-3">
      <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3">
        <p class="text-xs text-neutral-500">{{ t('admin.students.studyingExport.total') }}</p>
        <p class="text-2xl font-semibold tabular-nums text-neutral-900">{{ list && !loading ? list.total : '…' }}</p>
      </div>
      <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3">
        <p class="text-xs text-neutral-500">{{ t('admin.students.studyingExport.female') }}</p>
        <p class="text-2xl font-semibold tabular-nums text-neutral-900">{{ list && !loading ? list.female : '…' }}</p>
      </div>
    </div>

    <div class="mb-2 flex items-center justify-between gap-2">
      <p class="text-sm font-medium text-neutral-800">{{ t('admin.students.studyingExport.columnsLabel') }}</p>
      <div class="flex gap-3 text-sm">
        <button type="button" class="text-primary-700 hover:underline" @click="picked = [...studyingExportColumns]">
          {{ t('admin.students.studyingExport.selectAll') }}
        </button>
        <button type="button" class="text-neutral-500 hover:underline" @click="picked = []">{{ t('admin.students.studyingExport.clear') }}</button>
      </div>
    </div>
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <label
        v-for="column in studyingExportColumns"
        :key="column"
        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
        :class="picked.includes(column) ? 'border-primary-300 bg-primary-50 text-neutral-900' : 'border-neutral-200 text-neutral-600'"
      >
        <input
          type="checkbox"
          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
          :checked="picked.includes(column)"
          @change="toggle(column)"
        />
        {{ t(`admin.students.studyingExport.columns.${column}`) }}
      </label>
    </div>
    <p v-if="picked.length === 0" class="mt-2 text-sm text-danger-600">{{ t('admin.students.studyingExport.needColumn') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mt-4">{{ error }}</BaseAlert>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton variant="outline" :loading="exporting === 'excel'" :disabled="!canExport" @click="download('excel')">
          {{ t('admin.students.studyingExport.excel') }}
        </BaseButton>
        <BaseButton variant="outline" :loading="exporting === 'pdf'" :disabled="!canExport" @click="download('pdf')">
          {{ t('admin.students.studyingExport.pdf') }}
        </BaseButton>
        <BaseButton :loading="exporting === 'image'" :disabled="!canExport" @click="exportImage">
          {{ t('admin.students.studyingExport.image') }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
