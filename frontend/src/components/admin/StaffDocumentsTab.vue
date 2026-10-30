<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { STAFF_DOCUMENT_TYPES, staffDocumentsService, type StaffDocument } from '@/services/staffDocuments'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Staff > Documents (docs/hrm/staff-profile-tabs.md): a staff
 * member's private personal / HR files. A table on desktop, cards on phone.
 * Uploading, editing and deleting need staff.update.
 */
const props = defineProps<{ staffId: number | null }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('staff.update'))
const d = (key: string, params?: Record<string, unknown>) => t(`admin.staff.documents.${key}`, params ?? {})

const rows = ref<StaffDocument[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  if (props.staffId === null) return
  loading.value = true
  error.value = null
  try {
    rows.value = await staffDocumentsService.list(props.staffId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.loadFailed')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => props.staffId, load)

const typeOptions = computed(() => STAFF_DOCUMENT_TYPES.map((v) => ({ value: v, label: d(`types.${v}`) })))
const size = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

async function open(doc: StaffDocument): Promise<void> {
  if (props.staffId === null) return
  try {
    await staffDocumentsService.download(props.staffId, doc)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.loadFailed')
  }
}

async function remove(doc: StaffDocument): Promise<void> {
  if (props.staffId === null) return
  if (!(await confirmDialog.confirm({ message: t('admin.staff.records.deleteConfirm', { name: doc.title }), danger: true }))) return
  try {
    await staffDocumentsService.remove(props.staffId, doc.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.saveFailed')
  }
}

// --- Upload / edit --------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<StaffDocument | null>(null)
const form = reactive({ type: 'id_card', title: '', document_no: '', issued_on: '', expires_on: '', note: '' })
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

function openForm(doc: StaffDocument | null): void {
  editing.value = doc
  form.type = doc?.type ?? 'id_card'
  form.title = doc?.title ?? ''
  form.document_no = doc?.document_no ?? ''
  form.issued_on = doc?.issued_on ?? ''
  form.expires_on = doc?.expires_on ?? ''
  form.note = doc?.note ?? ''
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
  errors.value = {}
  formError.value = null
  formOpen.value = true
}

function onFile(e: Event): void {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
  // A new upload's name is a good first title.
  if (file.value && !form.title.trim()) form.title = file.value.name.replace(/\.[^.]+$/, '')
}

async function save(): Promise<void> {
  if (props.staffId === null) return
  saving.value = true
  errors.value = {}
  formError.value = null
  const input = {
    type: form.type,
    title: form.title.trim(),
    document_no: form.document_no.trim() || null,
    issued_on: form.issued_on || null,
    expires_on: form.expires_on || null,
    note: form.note.trim() || null,
    file: file.value,
  }
  try {
    if (editing.value) await staffDocumentsService.update(props.staffId, editing.value.id, input)
    else await staffDocumentsService.create(props.staffId, input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else formError.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.saveFailed')
  } finally {
    saving.value = false
  }
}

const err = (key: string) => errors.value[key]?.[0]
</script>

<template>
  <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="text-sm font-semibold text-neutral-800">{{ d('title') }}</h2>
        <p class="text-xs text-neutral-500">{{ d('hint') }}</p>
      </div>
      <BaseButton v-if="canManage && staffId !== null" size="sm" @click="openForm(null)">{{ d('add') }}</BaseButton>
    </div>

    <BaseAlert v-if="staffId === null" variant="info">{{ t('admin.staff.records.saveFirst') }}</BaseAlert>
    <template v-else>
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ d('empty') }}</p>

      <template v-else>
        <!-- Desktop: a table -->
        <div class="hidden overflow-x-auto sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-xs font-semibold tracking-wide text-neutral-500 uppercase">
              <tr>
                <th class="px-3 py-2">{{ d('fields.title') }}</th>
                <th class="px-3 py-2">{{ d('fields.type') }}</th>
                <th class="px-3 py-2">{{ d('fields.document_no') }}</th>
                <th class="px-3 py-2">{{ d('fields.issued_on') }}</th>
                <th class="px-3 py-2">{{ d('fields.expires_on') }}</th>
                <th class="px-3 py-2 text-right">{{ t('admin.staff.records.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.id">
                <td class="px-3 py-2.5">
                  <p class="font-medium text-neutral-900">{{ row.title }}</p>
                  <p class="text-xs text-neutral-500">{{ row.file_name }} · {{ size(row.size) }}<template v-if="row.note"> · {{ row.note }}</template></p>
                </td>
                <td class="px-3 py-2.5"><BaseBadge variant="neutral">{{ d(`types.${row.type}`) }}</BaseBadge></td>
                <td class="px-3 py-2.5 text-neutral-700">{{ row.document_no ?? '—' }}</td>
                <td class="px-3 py-2.5 text-neutral-700">{{ row.issued_on ? formatDate(row.issued_on) : '—' }}</td>
                <td class="px-3 py-2.5" :class="row.expired ? 'font-medium text-red-600' : 'text-neutral-700'">
                  {{ row.expires_on ? formatDate(row.expires_on) : '—' }}<template v-if="row.expired"> · {{ d('expired') }}</template>
                </td>
                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                  <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="open(row)">{{ d('open') }}</button>
                  <template v-if="canManage">
                    <button type="button" class="ml-3 text-sm font-medium text-secondary-700 hover:underline" @click="openForm(row)">{{ t('admin.staff.records.edit') }}</button>
                    <button type="button" class="ml-3 text-sm font-medium text-red-600 hover:underline" @click="remove(row)">{{ t('admin.staff.records.delete') }}</button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Phone: cards -->
        <ul class="space-y-3 sm:hidden">
          <li v-for="row in rows" :key="row.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0 font-medium break-words text-neutral-900">{{ row.title }}</p>
              <BaseBadge variant="neutral">{{ d(`types.${row.type}`) }}</BaseBadge>
            </div>
            <p class="text-xs break-all text-neutral-500">{{ row.file_name }} · {{ size(row.size) }}</p>
            <dl class="mt-1 space-y-0.5 text-sm">
              <div v-if="row.document_no" class="flex gap-2">
                <dt class="shrink-0 text-neutral-500">{{ d('fields.document_no') }}:</dt>
                <dd class="min-w-0 break-words text-neutral-800">{{ row.document_no }}</dd>
              </div>
              <div v-if="row.issued_on" class="flex gap-2">
                <dt class="shrink-0 text-neutral-500">{{ d('fields.issued_on') }}:</dt>
                <dd class="text-neutral-800">{{ formatDate(row.issued_on) }}</dd>
              </div>
              <div v-if="row.expires_on" class="flex gap-2">
                <dt class="shrink-0 text-neutral-500">{{ d('fields.expires_on') }}:</dt>
                <dd :class="row.expired ? 'font-medium text-red-600' : 'text-neutral-800'">{{ formatDate(row.expires_on) }}<template v-if="row.expired"> · {{ d('expired') }}</template></dd>
              </div>
            </dl>
            <p v-if="row.note" class="mt-1 text-xs text-neutral-500">{{ row.note }}</p>
            <div class="mt-2 flex gap-4 text-sm">
              <button type="button" class="font-medium text-secondary-700 hover:underline" @click="open(row)">{{ d('open') }}</button>
              <template v-if="canManage">
                <button type="button" class="font-medium text-secondary-700 hover:underline" @click="openForm(row)">{{ t('admin.staff.records.edit') }}</button>
                <button type="button" class="font-medium text-red-600 hover:underline" @click="remove(row)">{{ t('admin.staff.records.delete') }}</button>
              </template>
            </div>
          </li>
        </ul>
      </template>
    </template>

    <BaseModal v-model="formOpen" size="lg" :title="editing ? d('editTitle') : d('add')">
      <form id="staff-document-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
        <BaseAlert v-if="formError" variant="danger" class="sm:col-span-2">{{ formError }}</BaseAlert>
        <div class="sm:col-span-2">
          <label for="staff-document-file" class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ d('fields.file') }} <span v-if="!editing" class="text-red-500">*</span>
          </label>
          <input
            id="staff-document-file"
            ref="fileInput"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
            class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-lg file:border-0 file:bg-secondary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-800"
            @change="onFile"
          />
          <p class="mt-1 text-xs" :class="err('file') ? 'text-red-600' : 'text-neutral-500'">
            {{ err('file') ?? (editing ? d('replaceHint', { name: editing.file_name }) : d('fileHint')) }}
          </p>
        </div>
        <BaseSelect v-model="form.type" :options="typeOptions" :label="d('fields.type')" required :error="err('type')" />
        <BaseInput v-model="form.title" :label="d('fields.title')" required :error="err('title')" />
        <BaseInput v-model="form.document_no" :label="d('fields.document_no')" :error="err('document_no')" />
        <BaseInput v-model="form.issued_on" type="date" :label="d('fields.issued_on')" :error="err('issued_on')" />
        <BaseInput v-model="form.expires_on" type="date" :label="d('fields.expires_on')" :error="err('expires_on')" />
        <BaseInput v-model="form.note" :label="d('fields.note')" :error="err('note')" class="sm:col-span-2" />
      </form>
      <template #footer>
        <div class="flex w-full flex-wrap justify-end gap-2">
          <BaseButton variant="outline" @click="formOpen = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" form="staff-document-form" :loading="saving" :disabled="!editing && !file">{{ editing ? t('common.save') : d('upload') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
