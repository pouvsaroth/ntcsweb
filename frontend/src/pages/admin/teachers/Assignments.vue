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
import { teachersService, type Assignment, type Teacher, type TeacherOptions } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Teacher > Assignment: a teacher on a class, for a subject and an
 * academic year, with weekly hours. An assigned teacher also teaches the
 * class (attendance and scores of their classes).
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('teachers.manage'))

const teachers = ref<Teacher[]>([])
const options = ref<TeacherOptions>({ classes: [], subjects: [], academic_years: [] })
const filter = reactive({ academic_year_id: '', staff_id: '', class_id: '' })
const rows = ref<Assignment[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const all = (label: string) => ({ value: '', label })
const teacherOptions = computed(() => teachers.value.map((x) => ({ value: String(x.id), label: x.name })))
const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.academic_year ? `${c.name} · ${c.academic_year}` : c.name })))
const subjectOptions = computed(() => [all('—'), ...options.value.subjects.map((s) => ({ value: String(s.id), label: s.title }))])
const yearOptions = computed(() => options.value.academic_years.map((y) => ({ value: String(y.id), label: y.name })))

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await teachersService.assignments({
      academic_year_id: filter.academic_year_id ? Number(filter.academic_year_id) : undefined,
      staff_id: filter.staff_id ? Number(filter.staff_id) : undefined,
      class_id: filter.class_id ? Number(filter.class_id) : undefined,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function setFilter(key: keyof typeof filter, value: string) {
  filter[key] = value
  void load()
}

const totalHours = computed(() => rows.value.reduce((sum, r) => sum + (r.hours_per_week ?? 0), 0))
const columns = computed(() => [
  { key: 'teacher', label: t('teacherMgmt.teacher') },
  { key: 'class', label: t('teacherMgmt.class') },
  { key: 'subject', label: t('teacherMgmt.subject') },
  { key: 'year', label: t('teacherMgmt.academicYear') },
  { key: 'hours', label: t('teacherMgmt.hoursPerWeek') },
  ...(canManage.value ? [{ key: 'actions', label: t('teacherMgmt.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Assignment | null>(null)
const form = reactive({ staff_id: '', class_id: '', book_id: '', academic_year_id: '', hours_per_week: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

function open(a: Assignment | null) {
  editing.value = a
  form.staff_id = a ? String(a.staff_id) : filter.staff_id
  form.class_id = a ? String(a.class_id) : filter.class_id
  form.book_id = a?.book_id ? String(a.book_id) : ''
  form.academic_year_id = a?.academic_year_id ? String(a.academic_year_id) : filter.academic_year_id
  form.hours_per_week = a?.hours_per_week != null ? String(a.hours_per_week) : ''
  errors.value = {}
  formOpen.value = true
}
/** Picking a class fills in its academic year. */
function onClass(value: string) {
  form.class_id = value
  const year = options.value.classes.find((c) => String(c.id) === value)?.academic_year_id
  if (year) form.academic_year_id = String(year)
}
async function save() {
  saving.value = true
  errors.value = {}
  const input = {
    staff_id: Number(form.staff_id),
    class_id: Number(form.class_id),
    book_id: form.book_id ? Number(form.book_id) : null,
    academic_year_id: form.academic_year_id ? Number(form.academic_year_id) : null,
    hours_per_week: form.hours_per_week === '' ? null : Number(form.hours_per_week),
  }
  try {
    if (editing.value) await teachersService.updateAssignment(editing.value.id, input)
    else await teachersService.createAssignment(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { staff_id: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')] }
  } finally {
    saving.value = false
  }
}
async function remove(a: Assignment) {
  if (!(await confirmDialog.confirm({ message: t('teacherMgmt.assign.deleteConfirm', { teacher: a.teacher, class: a.class }), danger: true }))) return
  await teachersService.removeAssignment(a.id).catch(() => undefined)
  await load()
}

onMounted(async () => {
  try {
    const [list, opts] = await Promise.all([teachersService.list(), teachersService.options()])
    teachers.value = list
    options.value = opts
    const current = opts.academic_years.find((y) => y.is_current)
    if (current) filter.academic_year_id = String(current.id)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-40" :model-value="filter.academic_year_id" :options="[all(t('teacherMgmt.allYears')), ...yearOptions]" @update:model-value="setFilter('academic_year_id', $event)" />
      <BaseSelect class="w-56" :model-value="filter.staff_id" :options="[all(t('teacherMgmt.allTeachers')), ...teacherOptions]" @update:model-value="setFilter('staff_id', $event)" />
      <BaseSelect class="w-48" :model-value="filter.class_id" :options="[all(t('teacherMgmt.allClasses')), ...classOptions]" @update:model-value="setFilter('class_id', $event)" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="canManage" class="ml-auto" :disabled="teachers.length === 0" @click="open(null)">{{ t('teacherMgmt.assign.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="rows.length" class="mb-2 text-sm text-neutral-500">{{ t('teacherMgmt.assign.total', { count: rows.length, hours: totalHours }) }}</p>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.assign.empty') }}</p>
      <div v-for="row in rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <p class="text-sm font-semibold text-neutral-800">{{ row.teacher }}</p>
        <p class="text-xs text-neutral-500">{{ [row.class, row.subject, row.academic_year].filter(Boolean).join(' · ') }}</p>
        <p v-if="row.hours_per_week != null" class="text-xs text-neutral-500">{{ t('teacherMgmt.hoursWeekN', { hours: row.hours_per_week }) }}</p>
        <div v-if="canManage" class="mt-2 flex justify-end gap-3">
          <EditIconButton @click="open(row)" />
          <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('teacherMgmt.delete') }}</button>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('teacherMgmt.assign.empty')">
        <template #cell-teacher="{ row }"><span class="font-medium text-neutral-800">{{ row.teacher }}</span></template>
        <template #cell-class="{ row }">{{ row.class }}</template>
        <template #cell-subject="{ row }">{{ row.subject || '—' }}</template>
        <template #cell-year="{ row }">{{ row.academic_year || '—' }}</template>
        <template #cell-hours="{ row }">{{ row.hours_per_week ?? '—' }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as Assignment)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Assignment)">{{ t('teacherMgmt.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('teacherMgmt.assign.edit') : t('teacherMgmt.assign.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseSelect v-model="form.staff_id" :options="teacherOptions" :placeholder="t('teacherMgmt.chooseTeacher')" required :label="t('teacherMgmt.teacher')" :error="errors.staff_id?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect :model-value="form.class_id" :options="classOptions" :placeholder="t('teacherMgmt.chooseClass')" required :label="t('teacherMgmt.class')" :error="errors.class_id?.[0]" @update:model-value="onClass" />
          <BaseSelect v-model="form.book_id" :options="subjectOptions" :label="t('teacherMgmt.subject')" :error="errors.book_id?.[0]" />
          <BaseSelect v-model="form.academic_year_id" :options="[all('—'), ...yearOptions]" :label="t('teacherMgmt.academicYear')" :error="errors.academic_year_id?.[0]" />
          <BaseInput v-model="form.hours_per_week" type="number" :label="t('teacherMgmt.hoursPerWeek')" :error="errors.hours_per_week?.[0]" />
        </div>
        <p class="text-xs text-neutral-500">{{ t('teacherMgmt.assign.hint') }}</p>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.staff_id || !form.class_id" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
