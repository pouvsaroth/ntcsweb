<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
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
import { QUALIFICATION_LEVELS, QUALIFICATION_TYPES, teachersService, type Qualification, type Teacher } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/** HRM > Teacher > Qualification: degrees, certificates, skills and specialised subjects of a teacher. */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('teachers.manage'))

const teachers = ref<Teacher[]>([])
const staffId = ref('')
const rows = ref<Qualification[]>([])
const subjects = ref<{ id: number; title: string }[]>([])
const allSubjects = ref<{ id: number; title: string }[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const teacherOptions = computed(() => teachers.value.map((x) => ({ value: String(x.id), label: x.employee_code ? `${x.name} (${x.employee_code})` : x.name })))
const typeOptions = computed(() => QUALIFICATION_TYPES.map((v) => ({ value: v, label: t(`teacherMgmt.qual.types.${v}`) })))
const levelOptions = computed(() => [{ value: '', label: '—' }, ...QUALIFICATION_LEVELS.map((v) => ({ value: v, label: t(`teacherMgmt.qual.levels.${v}`) }))])

async function load() {
  if (!staffId.value) return
  loading.value = true
  error.value = null
  try {
    const data = await teachersService.qualifications(Number(staffId.value))
    rows.value = data.qualifications
    subjects.value = data.subjects
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function onTeacher(value: string) {
  staffId.value = value
  void load()
}

const columns = computed(() => [
  { key: 'type', label: t('teacherMgmt.qual.type') },
  { key: 'title', label: t('teacherMgmt.qual.title') },
  { key: 'institution', label: t('teacherMgmt.qual.institution') },
  { key: 'year', label: t('teacherMgmt.qual.year') },
  ...(canManage.value ? [{ key: 'actions', label: t('teacherMgmt.actions'), align: 'text-right' }] : []),
])
const levelText = (q: Qualification) => (q.level ? t(`teacherMgmt.qual.levels.${q.level}`) : null)

// --- Qualification form ------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Qualification | null>(null)
const form = reactive({ type: 'degree', level: '', title: '', institution: '', year: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

function open(q: Qualification | null) {
  editing.value = q
  form.type = q?.type ?? 'degree'
  form.level = q?.level ?? ''
  form.title = q?.title ?? ''
  form.institution = q?.institution ?? ''
  form.year = q?.year ? String(q.year) : ''
  form.note = q?.note ?? ''
  errors.value = {}
  formOpen.value = true
}
async function save() {
  saving.value = true
  errors.value = {}
  const input = {
    staff_id: Number(staffId.value),
    type: form.type as Qualification['type'],
    level: (form.type === 'degree' && form.level ? form.level : null) as Qualification['level'],
    title: form.title.trim(),
    institution: form.institution.trim() || null,
    year: form.year ? Number(form.year) : null,
    note: form.note.trim() || null,
  }
  try {
    if (editing.value) await teachersService.updateQualification(editing.value.id, input)
    else await teachersService.createQualification(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { title: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')] }
  } finally {
    saving.value = false
  }
}
async function remove(q: Qualification) {
  if (!(await confirmDialog.confirm({ message: t('teacherMgmt.deleteConfirm', { name: q.title }), danger: true }))) return
  await teachersService.removeQualification(q.id).catch(() => undefined)
  await load()
}

// --- Specialised subjects ------------------------------------------------------
const subjectsOpen = ref(false)
const picked = ref<number[]>([])
const subjectsSaving = ref(false)
function openSubjects() {
  picked.value = subjects.value.map((s) => s.id)
  subjectsOpen.value = true
}
async function saveSubjects() {
  subjectsSaving.value = true
  try {
    await teachersService.saveSubjects(Number(staffId.value), picked.value)
    subjectsOpen.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')
  } finally {
    subjectsSaving.value = false
  }
}

onMounted(async () => {
  try {
    const [list, options] = await Promise.all([teachersService.list(), teachersService.options()])
    teachers.value = list
    allSubjects.value = options.subjects
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  }
  if (teachers.value.length) {
    staffId.value = String(teachers.value[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-72 max-w-full" :model-value="staffId" :options="teacherOptions" :placeholder="t('teacherMgmt.chooseTeacher')" @update:model-value="onTeacher" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="canManage && staffId" class="ml-auto" @click="open(null)">{{ t('teacherMgmt.qual.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!error && teachers.length === 0" variant="info" class="mb-4">{{ t('teacherMgmt.noTeachers') }}</BaseAlert>

    <template v-if="staffId">
      <!-- Specialised subjects -->
      <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ t('teacherMgmt.qual.subjects') }}</p>
          <button v-if="canManage" type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openSubjects">{{ t('teacherMgmt.edit') }}</button>
        </div>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <span v-for="s in subjects" :key="s.id" class="rounded-full bg-secondary-50 px-2.5 py-0.5 text-xs font-medium text-secondary-800">{{ s.title }}</span>
          <span v-if="subjects.length === 0" class="text-sm text-neutral-400">{{ t('teacherMgmt.qual.noSubjects') }}</span>
        </div>
      </div>

      <!-- Cards on a phone (below sm) -->
      <div class="space-y-2 sm:hidden">
        <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.qual.empty') }}</p>
        <div v-for="row in rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.title }}</p>
            <BaseBadge variant="neutral">{{ t(`teacherMgmt.qual.types.${row.type}`) }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ [levelText(row), row.institution, row.year].filter(Boolean).join(' · ') || '—' }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('teacherMgmt.delete') }}</button>
          </div>
        </div>
      </div>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('teacherMgmt.qual.empty')">
          <template #cell-type="{ row }"><BaseBadge variant="neutral">{{ t(`teacherMgmt.qual.types.${row.type}`) }}</BaseBadge></template>
          <template #cell-title="{ row }">
            <p class="font-medium text-neutral-800">{{ row.title }}</p>
            <p v-if="levelText(row as Qualification) || row.note" class="text-xs text-neutral-500">{{ [levelText(row as Qualification), row.note].filter(Boolean).join(' · ') }}</p>
          </template>
          <template #cell-institution="{ row }">{{ row.institution || '—' }}</template>
          <template #cell-year="{ row }">{{ row.year || '—' }}</template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-2">
              <EditIconButton @click="open(row as Qualification)" />
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Qualification)">{{ t('teacherMgmt.delete') }}</button>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="editing ? t('teacherMgmt.qual.edit') : t('teacherMgmt.qual.add')">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.type" :options="typeOptions" required :label="t('teacherMgmt.qual.type')" :error="errors.type?.[0]" />
          <BaseSelect v-if="form.type === 'degree'" v-model="form.level" :options="levelOptions" :label="t('teacherMgmt.qual.level')" :error="errors.level?.[0]" />
        </div>
        <BaseInput v-model="form.title" required :label="t('teacherMgmt.qual.title')" :hint="t('teacherMgmt.qual.titleHint')" :error="errors.title?.[0]" />
        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <BaseInput v-model="form.institution" :label="t('teacherMgmt.qual.institution')" :error="errors.institution?.[0]" />
          <BaseInput v-model="form.year" type="number" :label="t('teacherMgmt.qual.year')" :error="errors.year?.[0]" />
        </div>
        <BaseInput v-model="form.note" :label="t('teacherMgmt.note')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="subjectsOpen" :title="t('teacherMgmt.qual.subjects')">
      <p v-if="allSubjects.length === 0" class="text-sm text-neutral-500">{{ t('teacherMgmt.noSubjects') }}</p>
      <div class="grid max-h-80 gap-1 overflow-y-auto sm:grid-cols-2">
        <label v-for="s in allSubjects" :key="s.id" class="flex items-center gap-2 rounded px-2 py-1 text-sm text-neutral-700 hover:bg-neutral-50">
          <input v-model="picked" type="checkbox" :value="s.id" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ s.title }}
        </label>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="subjectsOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="subjectsSaving" @click="saveSubjects">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
