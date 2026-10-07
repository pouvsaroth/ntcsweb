<script setup lang="ts">
import { computed, onMounted, ref, shallowReactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PhotoReceivedModal from '@/components/admin/PhotoReceivedModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { academicYearsService } from '@/services/academicYears'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { useAuthStore } from '@/stores/auth'
import { renderCertificateSample } from '@/utils/certificateSample'
import { formatDate } from '@/utils/date'
import { printImages } from '@/utils/printImage'
import { exportTableAsImage } from '@/utils/tableImage'

/**
 * Examination → Certificate: every student who passed the exam (score ≥ 85,
 * from the Grades tab — see backend ExamApplication::scopePassed()), with
 * the academic year their score was entered in. When a student hands in the
 * photo for their certificate, staff tick them and click "Received Photo";
 * once the certificate is handed over, "Issued". "Print Certificate" prints
 * one page per ticked student.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canUpdate = computed(() => auth.can('exam-applications.update'))

// --- Filters -------------------------------------------------------------------

/** Ticked: only students who haven't handed in their photo yet. Unticked: everyone. */
const onlyNotGiven = ref(false)
/** Ticked: only students whose certificate hasn't been handed over yet. */
const onlyNotIssued = ref(false)
/** '' = every academic year. */
const academicYearId = ref('')
const academicYearOptions = ref<{ value: string; label: string }[]>([])

function listOptions() {
  return {
    certificate: true,
    photoReceived: onlyNotGiven.value ? ('no' as const) : undefined,
    certificateIssued: onlyNotIssued.value ? ('no' as const) : undefined,
    academicYearId: academicYearId.value ? Number(academicYearId.value) : undefined,
  }
}

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<ExamApplication>((query) =>
  examApplicationsService.list(query, listOptions()),
)

function onFilterChange() {
  selectedIds.value = []
  setPage(1)
}

function onAcademicYearChange(value: string) {
  academicYearId.value = value
  onFilterChange()
}

/** What the exported image's subtitle says was included. */
function filterSummary(): string {
  const year = academicYearOptions.value.find((o) => o.value === academicYearId.value)?.label ?? t('admin.examCertificate.allAcademicYears')
  return [
    year,
    onlyNotGiven.value ? t('admin.examCertificate.onlyNotGiven') : null,
    onlyNotIssued.value ? t('admin.examCertificate.onlyNotIssued') : null,
  ]
    .filter(Boolean)
    .join('  ·  ')
}

// --- Table + selection -----------------------------------------------------------

const selectedIds = ref<number[]>([])

// Ticks survive paging, so every row seen so far is remembered by id —
// Print Certificate then covers a row ticked on another page too.
// shallowReactive so a reload (e.g. after Issued) refreshes what it prints.
const seenRows = shallowReactive(new Map<number, ExamApplication>())
watch(items, (rows) => rows.forEach((row) => seenRows.set(row.id, row)), { immediate: true })
const selectedRows = computed(() =>
  selectedIds.value.map((id) => seenRows.get(id)).filter((row): row is ExamApplication => row !== undefined),
)

const columns = computed(() => [
  { key: 'photo_received', label: t('admin.examCertificate.columnPhotoReceived') },
  { key: 'issued', label: t('admin.examCertificate.columnIssued') },
  { key: 'full_name', label: t('admin.exams.columnFullName') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'academic_year', label: t('admin.examCertificate.academicYear') },
  { key: 'exam_date', label: t('admin.exams.columnExamDate') },
  { key: 'score', label: t('admin.examCertificate.columnScore'), align: 'text-right' },
  { key: 'mention', label: t('admin.examCertificate.columnMention') },
])

const mentionVariant: Record<string, 'success' | 'warning' | 'neutral'> = {
  excellent: 'success',
  very_good: 'success',
  good: 'warning',
}

function bookName(row: ExamApplication): string {
  return row.book?.title ?? row.enrollment.course_package?.name ?? '—'
}

/** Received Photo and Issued share one dialog — see PhotoReceivedModal's `mode`. */
const modalOpen = ref(false)
const modalMode = ref<'photo' | 'issued'>('photo')

function openModal(mode: 'photo' | 'issued') {
  modalMode.value = mode
  modalOpen.value = true
}

// --- Export as image -----------------------------------------------------------
// Every passed student under the current filters, not just the page on screen.

const exporting = ref(false)
const exportError = ref<string | null>(null)

async function fetchAllForExport(): Promise<ExamApplication[]> {
  const all: ExamApplication[] = []
  for (let page = 1; ; page++) {
    const result = await examApplicationsService.list({ page, per_page: 100 }, listOptions())
    all.push(...result.data)
    if (result.pagination?.type !== 'length_aware' || page >= result.pagination.last_page) return all
  }
}

function dateCell(date: string | null | undefined, remark: string | null | undefined, emptyKey: string) {
  return date
    ? { text: `✓ ${formatDate(date)}`, badge: 'success' as const, subtext: remark || undefined }
    : { text: t(emptyKey) }
}

async function exportImage() {
  exporting.value = true
  exportError.value = null
  try {
    const rows = await fetchAllForExport()
    await exportTableAsImage({
      title: t('admin.examCertificate.title'),
      subtitle: [filterSummary(), `${t('admin.examCertificate.exportedOn')} ${formatDate(new Date())}`].join('  ·  '),
      columns: [
        { label: t('admin.examCertificate.columnPhotoReceived'), width: 150, maxWidth: 260 },
        { label: t('admin.examCertificate.columnIssued'), width: 150, maxWidth: 260 },
        { label: t('admin.exams.columnFullName'), width: 200 },
        { label: t('admin.exams.columnBook'), width: 160, maxWidth: 260 },
        { label: t('admin.examCertificate.academicYear'), width: 120 },
        { label: t('admin.exams.columnExamDate'), width: 110 },
        { label: t('admin.examCertificate.columnScore'), align: 'right', width: 80 },
        { label: t('admin.examCertificate.columnMention'), width: 110 },
      ],
      rows: rows.map((row) => [
        dateCell(row.photo_received_date, row.photo_received_remark, 'admin.examCertificate.notReceived'),
        dateCell(row.certificate_issued_date, row.certificate_issued_remark, 'admin.examCertificate.notIssued'),
        { text: row.student.name, bold: true },
        { text: bookName(row) },
        { text: row.score?.academic_year?.name ?? '—' },
        { text: formatDate(row.exam_date) },
        { text: row.score ? String(row.score.score) : '—', bold: true },
        row.score
          ? { text: t(`admin.myScores.mentions.${row.score.mention}`), badge: mentionVariant[row.score.mention] ?? 'neutral' }
          : { text: '—' },
      ]),
      emptyText: t('admin.examCertificate.emptyMessage'),
      fileName: `certificate-${new Date().toISOString().slice(0, 10)}.png`,
    })
  } catch {
    exportError.value = t('admin.examCertificate.exportImageFailed')
  } finally {
    exporting.value = false
  }
}

// --- Print Certificate -----------------------------------------------------------
// One A4 portrait page per ticked student. A placeholder page for now — see
// utils/certificateSample.ts, which the real certificate design replaces.

const printing = ref(false)

async function printCertificates() {
  printing.value = true
  exportError.value = null
  try {
    const pages = await Promise.all(
      selectedRows.value.map((row) =>
        renderCertificateSample({
          heading: t('admin.examCertificate.sampleHeading'),
          note: t('admin.examCertificate.sampleNote'),
          lines: [
            [t('admin.exams.columnFullName'), row.student.name],
            [t('admin.exams.columnBook'), bookName(row)],
            [t('admin.examCertificate.columnScore'), row.score ? String(row.score.score) : '—'],
            [t('admin.examCertificate.columnMention'), row.score ? t(`admin.myScores.mentions.${row.score.mention}`) : '—'],
            [t('admin.examCertificate.academicYear'), row.score?.academic_year?.name ?? '—'],
            [t('admin.exams.columnExamDate'), formatDate(row.exam_date)],
          ],
        }),
      ),
    )
    await printImages(pages, t('admin.examCertificate.printCertificate'), 'A4 portrait')
  } catch {
    exportError.value = t('admin.examCertificate.printFailed')
  } finally {
    printing.value = false
  }
}

async function onSaved() {
  selectedIds.value = []
  await fetch()
}

onMounted(async () => {
  void fetch()

  const years = await academicYearsService.listAll()
  academicYearOptions.value = [
    { value: '', label: t('admin.examCertificate.allAcademicYears') },
    ...years.map((year) => ({ value: String(year.id), label: year.name })),
  ]
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.examCertificate.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('admin.examCertificate.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <BaseButton variant="outline" :loading="exporting" @click="exportImage">{{ t('admin.examCertificate.exportImage') }}</BaseButton>
        <BaseButton variant="outline" :loading="printing" :disabled="selectedRows.length === 0" @click="printCertificates">
          {{ t('admin.examCertificate.printCertificate') }}<template v-if="selectedRows.length"> ({{ selectedRows.length }})</template>
        </BaseButton>
        <BaseButton v-if="canUpdate" :disabled="selectedIds.length === 0" @click="openModal('photo')">
          {{ t('admin.examCertificate.receivedPhoto') }}<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
        </BaseButton>
        <BaseButton v-if="canUpdate" :disabled="selectedIds.length === 0" @click="openModal('issued')">
          {{ t('admin.examCertificate.issued') }}<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
        </BaseButton>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-3">
      <BaseSelect
        class="w-56"
        :model-value="academicYearId"
        :options="academicYearOptions"
        :placeholder="t('admin.examCertificate.allAcademicYears')"
        @update:model-value="onAcademicYearChange"
      />
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
        <input
          v-model="onlyNotGiven"
          type="checkbox"
          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
          @change="onFilterChange"
        />
        {{ t('admin.examCertificate.onlyNotGiven') }}
      </label>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
        <input
          v-model="onlyNotIssued"
          type="checkbox"
          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
          @change="onFilterChange"
        />
        {{ t('admin.examCertificate.onlyNotIssued') }}
      </label>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>

    <!-- Always selectable: ticking picks who Print Certificate prints, even
         for someone who can't record Received Photo / Issued. -->
    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      selectable
      :selected="selectedIds"
      :empty-message="t('admin.examCertificate.emptyMessage')"
      @update:selected="selectedIds = $event as number[]"
    >
      <template #cell-full_name="{ row }">{{ (row as ExamApplication).student.name }}</template>
      <template #cell-book="{ row }">{{ bookName(row as ExamApplication) }}</template>
      <template #cell-academic_year="{ row }">{{ (row as ExamApplication).score?.academic_year?.name ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ formatDate((row as ExamApplication).exam_date) }}</template>
      <template #cell-score="{ row }">
        <span class="font-semibold text-neutral-900">{{ (row as ExamApplication).score?.score ?? '—' }}</span>
      </template>
      <template #cell-mention="{ row }">
        <BaseBadge v-if="(row as ExamApplication).score" :variant="mentionVariant[(row as ExamApplication).score!.mention] ?? 'neutral'">
          {{ t(`admin.myScores.mentions.${(row as ExamApplication).score!.mention}`) }}
        </BaseBadge>
      </template>
      <template #cell-photo_received="{ row }">
        <template v-if="(row as ExamApplication).photo_received_date">
          <BaseBadge variant="success">✓ {{ formatDate((row as ExamApplication).photo_received_date) }}</BaseBadge>
          <p v-if="(row as ExamApplication).photo_received_remark" class="mt-1 max-w-56 text-xs text-neutral-500">
            {{ (row as ExamApplication).photo_received_remark }}
          </p>
        </template>
        <span v-else class="text-sm text-neutral-400">{{ t('admin.examCertificate.notReceived') }}</span>
      </template>
      <template #cell-issued="{ row }">
        <template v-if="(row as ExamApplication).certificate_issued_date">
          <BaseBadge variant="success">✓ {{ formatDate((row as ExamApplication).certificate_issued_date) }}</BaseBadge>
          <p v-if="(row as ExamApplication).certificate_issued_remark" class="mt-1 max-w-56 text-xs text-neutral-500">
            {{ (row as ExamApplication).certificate_issued_remark }}
          </p>
        </template>
        <span v-else class="text-sm text-neutral-400">{{ t('admin.examCertificate.notIssued') }}</span>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PhotoReceivedModal v-model="modalOpen" :mode="modalMode" :ids="selectedIds" @saved="onSaved" />
  </div>
</template>
