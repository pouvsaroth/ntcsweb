<script setup lang="ts">
import { computed, onMounted, ref, shallowReactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PhotoReceivedModal from '@/components/admin/PhotoReceivedModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { academicYearsService } from '@/services/academicYears'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { staffCardDesignsService, type StaffCardDesign } from '@/services/staffCardDesigns'
import { useAuthStore } from '@/stores/auth'
import { certificateValues, todayIso } from '@/utils/certificate'
import { formatDate } from '@/utils/date'
import { printImages } from '@/utils/printImage'
import { renderCardImage, type CardSide } from '@/utils/staffCardRender'
import { exportTableAsImage } from '@/utils/tableImage'

/**
 * Examination → Certificate: every student who passed the exam (score ≥ 85,
 * from the Grades tab — see backend ExamApplication::scopePassed()), with
 * the academic year their score was entered in. When a student hands in the
 * photo for their certificate, staff tick them and click "Received Photo";
 * once the certificate is handed over, "Issued". "Print Certificate" prints
 * one page per ticked student on a certificate design (Examination >
 * Certificate designs), with one issue date for them all — and can mark
 * them Issued on that date.
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
/** '' = every student. Type-to-find (SearchableSelect) by name or student code. */
const studentId = ref('')
const studentOptions = ref<{ value: string; label: string; hint?: string }[]>([])

function listOptions() {
  return {
    certificate: true,
    photoReceived: onlyNotGiven.value ? ('no' as const) : undefined,
    certificateIssued: onlyNotIssued.value ? ('no' as const) : undefined,
    academicYearId: academicYearId.value ? Number(academicYearId.value) : undefined,
    studentId: studentId.value ? Number(studentId.value) : undefined,
  }
}

/**
 * Every passed student (no other filter applied), for the Student picker —
 * read from the same certificate list rather than all students, so it never
 * offers someone who would list nobody.
 */
async function loadStudentOptions() {
  const seen = new Map<number, { value: string; label: string; hint?: string }>()
  for (let page = 1; ; page++) {
    const result = await examApplicationsService.list({ page, per_page: 100 }, { certificate: true })
    for (const row of result.data) {
      seen.set(row.student.id, { value: String(row.student.id), label: row.student.name, hint: row.student.student_code })
    }
    if (result.pagination?.type !== 'length_aware' || page >= result.pagination.last_page) break
  }
  studentOptions.value = [...seen.values()].sort((a, b) => a.label.localeCompare(b.label))
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

function onStudentChange(value: string) {
  studentId.value = value
  onFilterChange()
}

/** What the exported image's subtitle says was included. */
function filterSummary(): string {
  const year = academicYearOptions.value.find((o) => o.value === academicYearId.value)?.label ?? t('admin.examCertificate.allAcademicYears')
  return [
    year,
    studentOptions.value.find((o) => o.value === studentId.value)?.label ?? null,
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
// The ticked students only, when any are ticked; otherwise every passed
// student under the current filters, not just the page on screen.

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

/** Ticked rows in the order the list shows them (ticks can span pages), not the order they were ticked. */
function sortedLikeList(rows: ExamApplication[]): ExamApplication[] {
  const order = [...seenRows.keys()]
  return [...rows].sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id))
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
    const selected = selectedRows.value
    const rows = selected.length > 0 ? sortedLikeList(selected) : await fetchAllForExport()
    const scope = selected.length > 0 ? t('admin.examCertificate.selectedStudents', { count: selected.length }) : filterSummary()
    await exportTableAsImage({
      title: t('admin.examCertificate.title'),
      subtitle: [scope, `${t('admin.examCertificate.exportedOn')} ${formatDate(new Date())}`].join('  ·  '),
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
// One page per ticked student on the picked certificate design, all with the
// same issue date; then (ticked) they're marked Issued on that date.

const canViewDesigns = computed(() => auth.can('certificate-designs.view'))
const designs = ref<StaffCardDesign[]>([])
const designId = ref('')
const design = computed(() => designs.value.find((d) => String(d.id) === designId.value) ?? null)
const designOptions = computed(() => designs.value.map((d) => ({ value: String(d.id), label: d.name })))
const DESIGN_KEY = 'exam-certificate-design'

async function loadDesigns() {
  if (!canViewDesigns.value) return
  const result = await staffCardDesignsService.list({ page: 1, per_page: 100 }, 'certificate')
  designs.value = result.data
  let remembered: string | null = null
  try {
    remembered = localStorage.getItem(DESIGN_KEY)
  } catch {
    // Storage blocked: just start with the first design.
  }
  designId.value = designs.value.some((d) => String(d.id) === remembered) ? remembered! : designs.value[0] ? String(designs.value[0].id) : ''
}
watch(designId, (id) => {
  try {
    if (id) localStorage.setItem(DESIGN_KEY, id)
  } catch {
    // Not remembered — fine.
  }
})

/** One issue date for every certificate printed now (dd-mm-yyyy in the box, ISO here). */
const issueDate = ref(todayIso())
/** After printing, mark the ticked students who aren't Issued yet as Issued on the issue date. */
const markIssued = ref(true)
const printing = ref(false)
const printNotice = ref<string | null>(null)

function sourceFor(row: ExamApplication, d: StaffCardDesign) {
  return {
    name_km: row.student.name,
    name_en: row.student.english_name,
    gender: row.student.gender,
    date_of_birth: row.student.date_of_birth,
    course: row.enrollment.course_package?.name ?? null,
    book: row.book?.title ?? null,
    exam_date: row.exam_date,
    mention: row.score?.mention ?? null,
    score: row.score ? String(row.score.score) : null,
    file_code: row.file_code,
    student_code: row.student.student_code,
    academic_year: row.score?.academic_year?.name ?? null,
    photo_url: row.student.photo_url,
    issue_date: issueDate.value || null,
    logoUrl: d.school_logo_url,
    signatureUrl: d.signature_url,
  }
}

async function printCertificates() {
  const d = design.value
  if (!d) return
  printing.value = true
  exportError.value = null
  printNotice.value = null
  try {
    const rows = sortedLikeList(selectedRows.value)
    const sides = (['front', 'back'] as CardSide[]).filter((side) => d[`has_${side}`])
    const pages: Blob[] = []
    // One page per side, one student after another (not all at once: each page is a 300 dpi image).
    for (const row of rows) {
      const values = certificateValues(sourceFor(row, d))
      for (const side of sides) pages.push(await renderCardImage(d, values, [side]))
    }
    await printImages(pages, t('admin.examCertificate.printCertificate'), `${d.width_mm}mm ${d.height_mm}mm`)
  } catch {
    exportError.value = t('admin.examCertificate.printFailed')
    printing.value = false
    return
  }

  try {
    const toMark = selectedRows.value.filter((row) => !row.certificate_issued_date).map((row) => row.id)
    if (markIssued.value && canUpdate.value && toMark.length > 0) {
      if (issueDate.value && issueDate.value <= todayIso()) {
        await examApplicationsService.markCertificateIssued(toMark, issueDate.value, null)
        printNotice.value = t('admin.examCertificate.markedIssued', { count: toMark.length, date: formatDate(issueDate.value) })
        await onSaved()
      } else {
        printNotice.value = t('admin.examCertificate.futureNotMarked')
      }
    }
  } catch {
    exportError.value = t('admin.examCertificate.markIssuedFailed')
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
  void loadDesigns().catch(() => {
    // No designs offered — the hint below says where to add one.
  })
  void loadStudentOptions().catch(() => {
    // The picker just stays empty — the list itself still works.
  })

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
        <BaseButton variant="outline" :loading="exporting" :title="selectedRows.length ? t('admin.examCertificate.exportSelectedHint') : t('admin.examCertificate.exportAllHint')" @click="exportImage">
          {{ t('admin.examCertificate.exportImage') }}<template v-if="selectedRows.length"> ({{ selectedRows.length }})</template>
        </BaseButton>
        <BaseButton variant="outline" :loading="printing" :disabled="selectedRows.length === 0 || !design" @click="printCertificates">
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
      <SearchableSelect
        class="w-64"
        :model-value="studentId"
        :options="studentOptions"
        :placeholder="t('admin.attendance.allStudents')"
        @update:model-value="onStudentChange"
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

    <!-- Print settings: the design, and one issue date for every ticked student -->
    <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
      <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
        <div v-if="designs.length" class="w-full sm:w-64">
          <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.examCertificate.design') }}</label>
          <BaseSelect v-model="designId" :options="designOptions" />
        </div>
        <div class="w-full sm:w-44">
          <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.examCertificate.issueDate') }}</label>
          <DateInput v-model="issueDate" />
        </div>
        <label v-if="canUpdate" class="inline-flex items-center gap-2 pb-2 text-sm text-neutral-700">
          <input v-model="markIssued" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.examCertificate.markIssuedOnPrint') }}
        </label>
      </div>
      <p v-if="!designs.length" class="mt-2 text-xs text-neutral-500">
        {{ canViewDesigns ? t('admin.examCertificate.noDesign') : t('admin.examCertificate.noDesignAccess') }}
        <RouterLink v-if="canViewDesigns" to="/admin/exams/certificate-designs" class="font-medium text-primary-700 hover:underline">{{ t('admin.examCertificate.addDesign') }}</RouterLink>
      </p>
      <p v-else class="mt-2 text-xs text-neutral-500">{{ t('admin.examCertificate.printHint') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="exportError" variant="danger" class="mb-4">{{ exportError }}</BaseAlert>
    <BaseAlert v-if="printNotice" variant="success" class="mb-4">{{ printNotice }}</BaseAlert>

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
