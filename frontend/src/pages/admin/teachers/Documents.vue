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
import { DOCUMENT_TYPES, teachersService, type Teacher, type TeacherDocument } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/** HRM > Teacher > Documents: a teacher's work files — private, opened only from here. */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('teachers.manage'))

const teachers = ref<Teacher[]>([])
const staffId = ref('')
const rows = ref<TeacherDocument[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const teacherOptions = computed(() => teachers.value.map((x) => ({ value: String(x.id), label: x.name })))
const typeOptions = computed(() => DOCUMENT_TYPES.map((v) => ({ value: v, label: t(`teacherMgmt.docs.types.${v}`) })))
const size = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await teachersService.documents(staffId.value ? Number(staffId.value) : undefined)
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

async function download(doc: TeacherDocument) {
  try {
    await teachersService.downloadDocument(doc)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  }
}
async function remove(doc: TeacherDocument) {
  if (!(await confirmDialog.confirm({ message: t('teacherMgmt.deleteConfirm', { name: doc.title }), danger: true }))) return
  await teachersService.removeDocument(doc.id).catch(() => undefined)
  await load()
}

const columns = computed(() => [
  { key: 'title', label: t('teacherMgmt.docs.title') },
  { key: 'teacher', label: t('teacherMgmt.teacher') },
  { key: 'type', label: t('teacherMgmt.docs.type') },
  { key: 'expires', label: t('teacherMgmt.docs.expires') },
  { key: 'actions', label: t('teacherMgmt.actions'), align: 'text-right' },
])

// --- Upload ----------------------------------------------------------------------
const formOpen = ref(false)
const form = reactive({ staff_id: '', type: 'contract', title: '', expires_on: '', note: '' })
const file = ref<File | null>(null)
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

function open() {
  form.staff_id = staffId.value
  form.type = 'contract'
  form.title = ''
  form.expires_on = ''
  form.note = ''
  file.value = null
  errors.value = {}
  formOpen.value = true
}
function onFile(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
  if (file.value && !form.title) form.title = file.value.name.replace(/\.[^.]+$/, '')
}
async function save() {
  if (!file.value) return
  saving.value = true
  errors.value = {}
  try {
    await teachersService.uploadDocument({
      staff_id: Number(form.staff_id),
      type: form.type,
      title: form.title.trim(),
      expires_on: form.expires_on || null,
      note: form.note.trim() || null,
      file: file.value,
    })
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { file: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')] }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  teachers.value = await teachersService.list().catch(() => [])
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-72 max-w-full" :model-value="staffId" :options="[{ value: '', label: t('teacherMgmt.allTeachers') }, ...teacherOptions]" @update:model-value="onTeacher" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="canManage" class="ml-auto" :disabled="teachers.length === 0" @click="open">{{ t('teacherMgmt.docs.add') }}</BaseButton>
    </div>
    <p class="mb-3 text-xs text-neutral-500">{{ t('teacherMgmt.docs.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.docs.empty') }}</p>
      <div v-for="row in rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ row.title }}</p>
          <BaseBadge variant="neutral">{{ t(`teacherMgmt.docs.types.${row.type}`) }}</BaseBadge>
        </div>
        <p class="text-xs text-neutral-500">{{ row.teacher }} · {{ row.file_name }} · {{ size(row.size) }}</p>
        <p v-if="row.expires_on" class="text-xs" :class="row.expired ? 'text-red-600' : 'text-neutral-500'">{{ t('teacherMgmt.docs.expires') }}: {{ formatDate(row.expires_on) }}</p>
        <div class="mt-2 flex justify-end gap-3">
          <button type="button" class="text-sm font-medium text-secondary-700" @click="download(row)">{{ t('teacherMgmt.docs.open') }}</button>
          <button v-if="canManage" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('teacherMgmt.delete') }}</button>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('teacherMgmt.docs.empty')">
        <template #cell-title="{ row }">
          <p class="font-medium text-neutral-800">{{ row.title }}</p>
          <p class="text-xs text-neutral-500">{{ row.file_name }} · {{ size(row.size) }}<template v-if="row.note"> · {{ row.note }}</template></p>
        </template>
        <template #cell-teacher="{ row }">{{ row.teacher }}</template>
        <template #cell-type="{ row }"><BaseBadge variant="neutral">{{ t(`teacherMgmt.docs.types.${row.type}`) }}</BaseBadge></template>
        <template #cell-expires="{ row }">
          <span v-if="row.expires_on" :class="row.expired ? 'font-medium text-red-600' : ''">{{ formatDate(row.expires_on) }}<template v-if="row.expired"> · {{ t('teacherMgmt.docs.expired') }}</template></span>
          <span v-else class="text-neutral-400">—</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-3">
            <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="download(row as TeacherDocument)">{{ t('teacherMgmt.docs.open') }}</button>
            <button v-if="canManage" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as TeacherDocument)">{{ t('teacherMgmt.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="t('teacherMgmt.docs.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseSelect v-model="form.staff_id" :options="teacherOptions" :placeholder="t('teacherMgmt.chooseTeacher')" required :label="t('teacherMgmt.teacher')" :error="errors.staff_id?.[0]" />
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('teacherMgmt.docs.file') }} <span class="text-red-500">*</span></label>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-lg file:border-0 file:bg-secondary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-800" @change="onFile" />
          <p class="mt-1 text-xs" :class="errors.file ? 'text-red-600' : 'text-neutral-500'">{{ errors.file?.[0] ?? t('teacherMgmt.docs.fileHint') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.type" :options="typeOptions" :label="t('teacherMgmt.docs.type')" />
          <BaseInput v-model="form.expires_on" type="date" :label="t('teacherMgmt.docs.expires')" :error="errors.expires_on?.[0]" />
        </div>
        <BaseInput v-model="form.title" required :label="t('teacherMgmt.docs.title')" :error="errors.title?.[0]" />
        <BaseInput v-model="form.note" :label="t('teacherMgmt.note')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!file || !form.staff_id" @click="save">{{ t('teacherMgmt.docs.upload') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
