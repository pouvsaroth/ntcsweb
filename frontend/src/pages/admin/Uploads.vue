<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import DownloadFolderFormModal from '@/components/admin/DownloadFolderFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import {
  DOWNLOAD_FILE_ACCEPT,
  MAX_DOWNLOAD_FILE_BYTES,
  downloadsService,
  formatFileSize,
  type DownloadFile,
  type DownloadFolder,
} from '@/services/downloads'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Website → Upload: folders (two levels — a top folder may hold
 * sub-folders) of files anyone can download from the public site's Download
 * page. Folder list on the left, the chosen folder's files on the right.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('downloads.create'))
const canUpdate = computed(() => auth.can('downloads.update'))
const canDelete = computed(() => auth.can('downloads.delete'))

// --- Folders ---------------------------------------------------------------

const folders = ref<DownloadFolder[]>([])
const loadingFolders = ref(false)
const error = ref<string | null>(null)
const selectedId = ref<number | null>(null)

/** Top folders and their sub-folders, flattened — for finding the selected one. */
const allFolders = computed(() => folders.value.flatMap((f) => [f, ...(f.children ?? [])]))
const selected = computed(() => allFolders.value.find((f) => f.id === selectedId.value) ?? null)
const selectedParent = computed(() => (selected.value?.parent_id ? allFolders.value.find((f) => f.id === selected.value!.parent_id) ?? null : null))

async function loadFolders() {
  loadingFolders.value = true
  error.value = null
  try {
    folders.value = await downloadsService.listFolders()
    if (selectedId.value !== null && !selected.value) selectedId.value = null
    if (selectedId.value === null && folders.value.length > 0) await select(folders.value[0])
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.uploads.loadFailed')
  } finally {
    loadingFolders.value = false
  }
}

async function select(folder: DownloadFolder) {
  selectedId.value = folder.id
  uploads.value = []
  await loadFiles()
}

const folderModalOpen = ref(false)
const editingFolder = ref<DownloadFolder | null>(null)
const newFolderParent = ref<DownloadFolder | null>(null)

function openNewFolder(parent: DownloadFolder | null = null) {
  editingFolder.value = null
  newFolderParent.value = parent
  folderModalOpen.value = true
}

function openEditFolder(folder: DownloadFolder) {
  editingFolder.value = folder
  newFolderParent.value = null
  folderModalOpen.value = true
}

async function onFolderSaved(folder: DownloadFolder) {
  const isNew = editingFolder.value === null
  await loadFolders()
  if (isNew) await select(folder)
}

async function removeFolder(folder: DownloadFolder) {
  const ok = await confirmDialog.confirm({
    message: t('admin.uploads.deleteFolderConfirm', { name: folder.name }),
    confirmLabel: t('admin.uploads.delete'),
    danger: true,
  })
  if (!ok) return

  try {
    await downloadsService.removeFolder(folder.id)
    if (selectedId.value === folder.id || selected.value?.parent_id === folder.id) selectedId.value = null
    files.value = []
    await loadFolders()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.uploads.saveFailed')
  }
}

// --- Files -------------------------------------------------------------------

const files = ref<DownloadFile[]>([])
const loadingFiles = ref(false)

async function loadFiles() {
  if (selectedId.value === null) return
  loadingFiles.value = true
  try {
    files.value = await downloadsService.listFiles(selectedId.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.uploads.loadFailed')
  } finally {
    loadingFiles.value = false
  }
}

const fileColumns = computed(() => [
  { key: 'preview', label: t('admin.uploads.columnPreview') },
  { key: 'name', label: t('admin.uploads.columnName') },
  { key: 'size', label: t('admin.uploads.columnSize'), align: 'text-right' },
  { key: 'created_at', label: t('admin.uploads.columnUploaded') },
  { key: 'actions', label: t('admin.uploads.columnActions'), align: 'text-right' },
])

/** One row per file picked — sent one by one so each can be the full 50 MB. */
interface UploadRow {
  key: number
  name: string
  progress: number
  status: 'waiting' | 'uploading' | 'done' | 'failed'
  error?: string
}

const uploads = ref<UploadRow[]>([])
const uploading = computed(() => uploads.value.some((u) => u.status === 'waiting' || u.status === 'uploading'))
const fileInput = ref<HTMLInputElement | null>(null)
let uploadKey = 0

async function onFilesPicked(event: Event) {
  const input = event.target as HTMLInputElement
  const picked = Array.from(input.files ?? [])
  input.value = ''
  const folderId = selectedId.value
  if (picked.length === 0 || folderId === null) return

  const rows = picked.map((file) => {
    const row: UploadRow = { key: ++uploadKey, name: file.name, progress: 0, status: 'waiting' }
    if (file.size > MAX_DOWNLOAD_FILE_BYTES) {
      row.status = 'failed'
      row.error = t('admin.uploads.tooLarge')
    }
    return { file, row }
  })
  uploads.value = [...uploads.value.filter((u) => u.status !== 'done'), ...rows.map((r) => r.row)]

  for (const { file, row: plain } of rows) {
    // The reactive copy, so progress updates show.
    const row = uploads.value.find((u) => u.key === plain.key)!
    if (row.status === 'failed') continue
    row.status = 'uploading'
    try {
      await downloadsService.uploadFile(folderId, file, (fraction) => (row.progress = fraction))
      row.status = 'done'
      row.progress = 1
    } catch (e) {
      row.status = 'failed'
      row.error = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('admin.uploads.uploadFailed')
    }
  }

  if (selectedId.value === folderId) await loadFiles()
  await loadFolders()
}

const renameOpen = ref(false)
const renaming = ref<DownloadFile | null>(null)
const renameValue = ref('')
const renameError = ref<string | null>(null)
const renameSubmitting = ref(false)

function openRename(file: DownloadFile) {
  renaming.value = file
  renameValue.value = file.name
  renameError.value = null
  renameOpen.value = true
}

async function submitRename() {
  if (!renaming.value) return
  renameSubmitting.value = true
  renameError.value = null
  try {
    await downloadsService.renameFile(renaming.value.id, renameValue.value)
    renameOpen.value = false
    await loadFiles()
  } catch (e) {
    renameError.value = e instanceof ApiRequestError ? (e.errors?.name?.[0] ?? e.message) : t('admin.uploads.saveFailed')
  } finally {
    renameSubmitting.value = false
  }
}

async function removeFile(file: DownloadFile) {
  const ok = await confirmDialog.confirm({
    message: t('admin.uploads.deleteFileConfirm', { name: file.name }),
    confirmLabel: t('admin.uploads.delete'),
    danger: true,
  })
  if (!ok) return

  try {
    await downloadsService.removeFile(file.id)
    await Promise.all([loadFiles(), loadFolders()])
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.uploads.saveFailed')
  }
}

onMounted(() => loadFolders())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.uploads.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('admin.uploads.subtitle') }}</p>
      </div>
      <BaseButton v-if="canCreate" @click="openNewFolder()">{{ t('admin.uploads.newFolder') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
      <!-- Folder list -->
      <aside class="self-start rounded-[--radius-card] border border-neutral-200 bg-white">
        <div v-if="loadingFolders && folders.length === 0" class="flex justify-center py-8"><BaseSpinner /></div>
        <p v-else-if="folders.length === 0" class="p-4 text-sm text-neutral-500">{{ t('admin.uploads.noFolders') }}</p>
        <ul v-else class="divide-y divide-neutral-100 py-1">
          <li v-for="folder in folders" :key="folder.id">
            <button
              type="button"
              class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-neutral-50"
              :class="selectedId === folder.id ? 'bg-primary-50 font-semibold text-primary-800' : 'text-neutral-800'"
              @click="select(folder)"
            >
              <svg class="h-5 w-5 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z" />
              </svg>
              <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
              <BaseBadge v-if="folder.status === 'inactive'" variant="neutral">{{ t('admin.uploads.hidden') }}</BaseBadge>
              <span class="text-xs text-neutral-400">{{ folder.files_count ?? 0 }}</span>
            </button>
            <ul v-if="folder.children?.length">
              <li v-for="child in folder.children" :key="child.id">
                <button
                  type="button"
                  class="flex w-full items-center gap-2 py-2 pl-9 pr-3 text-left text-sm hover:bg-neutral-50"
                  :class="selectedId === child.id ? 'bg-primary-50 font-semibold text-primary-800' : 'text-neutral-700'"
                  @click="select(child)"
                >
                  <svg class="h-4 w-4 shrink-0 text-amber-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z" />
                  </svg>
                  <span class="min-w-0 flex-1 truncate">{{ child.name }}</span>
                  <BaseBadge v-if="child.status === 'inactive'" variant="neutral">{{ t('admin.uploads.hidden') }}</BaseBadge>
                  <span class="text-xs text-neutral-400">{{ child.files_count ?? 0 }}</span>
                </button>
              </li>
            </ul>
          </li>
        </ul>
      </aside>

      <!-- The chosen folder -->
      <section class="min-w-0">
        <p v-if="!selected" class="rounded-[--radius-card] border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500">
          {{ folders.length === 0 ? t('admin.uploads.noFolders') : t('admin.uploads.selectFolder') }}
        </p>

        <template v-else>
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <p v-if="selectedParent" class="text-xs text-neutral-500">{{ selectedParent.name }} ›</p>
              <h2 class="flex items-center gap-2 truncate text-lg font-semibold text-neutral-900">
                {{ selected.name }}
                <BaseBadge v-if="selected.status === 'inactive'" variant="neutral">{{ t('admin.uploads.hidden') }}</BaseBadge>
              </h2>
            </div>
            <div class="flex flex-wrap gap-2">
              <BaseButton v-if="canCreate && selected.parent_id === null" variant="outline" size="sm" @click="openNewFolder(selected)">
                {{ t('admin.uploads.newSubFolder') }}
              </BaseButton>
              <BaseButton v-if="canUpdate" variant="outline" size="sm" @click="openEditFolder(selected)">{{ t('common.edit') }}</BaseButton>
              <BaseButton v-if="canDelete" variant="danger" size="sm" @click="removeFolder(selected)">{{ t('admin.uploads.deleteFolder') }}</BaseButton>
              <BaseButton v-if="canCreate" size="sm" :loading="uploading" @click="fileInput?.click()">{{ t('admin.uploads.uploadFiles') }}</BaseButton>
              <input ref="fileInput" type="file" multiple class="hidden" :accept="DOWNLOAD_FILE_ACCEPT" @change="onFilesPicked" />
            </div>
          </div>

          <p v-if="canCreate" class="mb-4 text-xs text-neutral-500">{{ t('admin.uploads.uploadHint') }}</p>

          <ul v-if="uploads.length" class="mb-4 space-y-2 rounded-[--radius-card] border border-neutral-200 bg-white p-3">
            <li v-for="row in uploads" :key="row.key" class="text-sm">
              <div class="flex items-center justify-between gap-3">
                <span class="min-w-0 truncate text-neutral-800">{{ row.name }}</span>
                <span v-if="row.status === 'done'" class="shrink-0 text-xs font-medium text-success-600">✓</span>
                <span v-else-if="row.status === 'failed'" class="shrink-0 text-xs text-danger-600">{{ row.error }}</span>
                <span v-else class="shrink-0 text-xs text-neutral-500">{{ Math.round(row.progress * 100) }}%</span>
              </div>
              <div v-if="row.status === 'uploading' || row.status === 'waiting'" class="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100">
                <div class="h-full bg-primary-600 transition-all" :style="{ width: `${Math.round(row.progress * 100)}%` }" />
              </div>
            </li>
          </ul>

          <DataTable
            :columns="fileColumns"
            :rows="files"
            row-key="id"
            :loading="loadingFiles"
            :empty-message="t('admin.uploads.emptyFolder')"
          >
            <template #cell-preview="{ row }">
              <div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-md bg-neutral-100">
                <img v-if="(row as DownloadFile).is_image" :src="(row as DownloadFile).url" alt="" class="h-full w-full object-cover" />
                <span v-else class="text-[10px] font-bold uppercase text-neutral-500">{{ (row as DownloadFile).extension }}</span>
              </div>
            </template>
            <template #cell-name="{ row }">
              <span class="font-medium text-neutral-900">{{ (row as DownloadFile).name }}</span>
              <span class="text-neutral-400">.{{ (row as DownloadFile).extension }}</span>
            </template>
            <template #cell-size="{ row }">{{ formatFileSize((row as DownloadFile).size) }}</template>
            <template #cell-created_at="{ row }">{{ formatDate((row as DownloadFile).created_at) }}</template>
            <template #cell-actions="{ row }">
              <div class="flex justify-end gap-2">
                <EditIconButton v-if="canUpdate" @click="openRename(row as DownloadFile)" />
                <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeFile(row as DownloadFile)">
                  {{ t('admin.uploads.delete') }}
                </button>
              </div>
            </template>
          </DataTable>
        </template>
      </section>
    </div>

    <DownloadFolderFormModal v-model="folderModalOpen" :folder="editingFolder" :parent="newFolderParent" @saved="onFolderSaved" />

    <BaseModal v-model="renameOpen" :title="t('admin.uploads.renameFile')">
      <form @submit.prevent="submitRename">
        <BaseInput v-model="renameValue" :label="t('admin.uploads.fileName')" :error="renameError ?? undefined" required />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="renameOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="renameSubmitting" @click="submitRename">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
