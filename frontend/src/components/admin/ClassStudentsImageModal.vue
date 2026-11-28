<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import type { Enrollment } from '@/services/enrollments'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'
import { exportTableAsImage } from '@/utils/tableImage'

/**
 * Class > Students > Export image: tick the fields, then download the
 * students (the checked ones, else all shown, in the roster's order) as a
 * PNG with only those fields. The ticks are remembered on this browser.
 */
const props = defineProps<{
  modelValue: boolean
  rows: Enrollment[]
  title: string
  /** Under the title, e.g. teacher and time; the totals are added here. */
  subtitle?: string
  fileName: string
  paymentTypeLabel: (type: string | null | undefined) => string
  statusLabel: (status: string) => string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const FIELDS = ['code', 'name', 'gender', 'table', 'payment_type', 'class', 'book', 'start_study', 'status', 'responsible_staff', 'note'] as const
type Field = (typeof FIELDS)[number]
const DEFAULT_FIELDS: Field[] = ['code', 'name', 'gender', 'table', 'book']
const STORAGE_KEY = 'classStudents.imageExport.fields'

function savedFields(): Field[] {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null') as unknown
    if (Array.isArray(saved)) {
      const valid = FIELDS.filter((f) => saved.includes(f))
      if (valid.length) return valid
    }
  } catch {
    // Storage blocked or a bad value — use the defaults.
  }
  return [...DEFAULT_FIELDS]
}

const picked = ref<Field[]>(savedFields())
watch(picked, (fields) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(fields))
  } catch {
    // Not remembered this time.
  }
})

function toggle(field: Field): void {
  picked.value = picked.value.includes(field) ? picked.value.filter((f) => f !== field) : FIELDS.filter((f) => f === field || picked.value.includes(f))
}

const fieldLabel = (field: Field): string =>
  ({
    code: t('admin.classStudents.imageFields.code'),
    name: t('admin.classStudents.columnFullName'),
    gender: t('admin.classStudents.columnGender'),
    table: t('admin.classStudents.columnTable'),
    payment_type: t('admin.invoices.paymentType'),
    class: t('admin.classStudents.columnClass'),
    book: t('admin.classStudents.columnBook'),
    start_study: t('admin.classStudents.imageFields.startStudy'),
    status: t('admin.classStudents.imageFields.status'),
    responsible_staff: t('admin.classStudents.columnResponsibleStaff'),
    note: t('admin.classStudents.columnNote'),
  })[field]

function cell(row: Enrollment, field: Field): string {
  switch (field) {
    case 'code':
      return row.student.student_code ?? ''
    case 'name':
      return row.student.full_name
    case 'gender':
      return genderLabel(row.student.gender)
    case 'table':
      return row.table?.name ?? ''
    case 'payment_type':
      return props.paymentTypeLabel(row.payment_type)
    case 'class':
      return row.class?.name ?? ''
    case 'book':
      return row.course_package?.name ?? ''
    case 'start_study':
      return row.start_study_on ? formatDate(row.start_study_on) : ''
    case 'status':
      return props.statusLabel(row.status)
    case 'responsible_staff':
      return row.responsible_staff?.name ?? ''
    case 'note':
      return row.note ?? ''
  }
}

const WIDE: Field[] = ['name', 'book', 'responsible_staff', 'note', 'class']

const female = computed(() => props.rows.filter((r) => ['female', 'f'].includes((r.student.gender ?? '').toLowerCase())).length)
const exporting = ref(false)
const error = ref<string | null>(null)

async function exportImage(): Promise<void> {
  exporting.value = true
  error.value = null
  try {
    await exportTableAsImage({
      title: props.title,
      subtitle: [
        props.subtitle,
        `${t('admin.students.studyingExport.total')}: ${props.rows.length}`,
        `${t('admin.students.studyingExport.female')}: ${female.value}`,
        `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`,
      ]
        .filter(Boolean)
        .join('  ·  '),
      columns: [
        { label: t('admin.classStudents.columnNo'), width: 44, align: 'center' },
        ...picked.value.map((field) => ({ label: fieldLabel(field), width: 70, maxWidth: WIDE.includes(field) ? 260 : undefined })),
      ],
      rows: props.rows.map((row, index) => [{ text: String(index + 1) }, ...picked.value.map((field) => ({ text: cell(row, field) }))]),
      emptyText: '—',
      fileName: props.fileName,
    })
    emit('update:modelValue', false)
  } catch {
    error.value = t('admin.attendance.exportImageFailed')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.classStudents.exportImage')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.classStudents.imageIntro', { n: rows.length }) }}</p>

    <div class="mb-2 flex items-center justify-between gap-2">
      <p class="text-sm font-medium text-neutral-800">{{ t('admin.students.studyingExport.columnsLabel') }}</p>
      <div class="flex gap-3 text-sm">
        <button type="button" class="text-primary-700 hover:underline" @click="picked = [...FIELDS]">{{ t('admin.students.studyingExport.selectAll') }}</button>
        <button type="button" class="text-neutral-500 hover:underline" @click="picked = []">{{ t('admin.students.studyingExport.clear') }}</button>
      </div>
    </div>
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <label
        v-for="field in FIELDS"
        :key="field"
        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
        :class="picked.includes(field) ? 'border-primary-300 bg-primary-50 text-neutral-900' : 'border-neutral-200 text-neutral-600'"
      >
        <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="picked.includes(field)" @change="toggle(field)" />
        {{ fieldLabel(field) }}
      </label>
    </div>
    <p v-if="picked.length === 0" class="mt-2 text-sm text-danger-600">{{ t('admin.students.studyingExport.needColumn') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mt-4">{{ error }}</BaseAlert>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="exporting" :disabled="picked.length === 0 || rows.length === 0" @click="exportImage">{{ t('admin.classStudents.exportImage') }}</BaseButton>
    </template>
  </BaseModal>
</template>
