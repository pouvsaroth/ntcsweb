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
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

import { MAX_WEBSITE_FILE_BYTES, WEBSITE_FILE_ACCEPT, formatBytes, websiteApi, type WebsiteFile, type WebsiteFolder, type WebsiteStatus } from '../../api'
import { useSession } from '../../session'

/**
 * Downloads: folders (a top folder may hold sub-folders) of files anyone can
 * download from the website's Download page. Folders on the left, the
 * chosen folder's files on the right; stacked on a phone.
 */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.website.manage'))

// --- Folders ----------------------------------------------------------------

const folders = ref<WebsiteFolder[]>([])
const loadingFolders = ref(false)
const error = ref<string | null>(null)
const selectedId = ref<number | null>(null)

const allFolders = computed(() => folders.value.flatMap((f) => [f, ...(f.children ?? [])]))
const selected = computed(() => allFolders.value.find((f) => f.id === selectedId.value) ?? null)
const selectedParent = computed(() => (selected.value?.parent_id ? (allFolders.value.find((f) => f.id === selected.value!.parent_id) ?? null) : null))

function fail(e: unknown): void {
  error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
}

async function loadFolders(): Promise<void> {
  loadingFolders.value = true
  error.value = null
  try {
    folders.value = await websiteApi.folders()
    if (selectedId.value !== null && !selected.value) selectedId.value = null
    if (selectedId.value === null && folders.value.length > 0) await select(folders.value[0])
  } catch (e) {
    fail(e)
  } finally {
    loadingFolders.value = false
  }
}

async function select(folder: WebsiteFolder): Promise<void> {
  selectedId.value = folder.id
  uploads.value = []
  await loadFiles()
}

const folderOpen = ref(false)
const editingFolder = ref<WebsiteFolder | null>(null)
const newParent = ref<WebsiteFolder | null>(null)
const folderForm = reactive({ name: '', sort_order: '0', status: 'active' as WebsiteStatus })
const folderBusy = ref(false)
const folderError = ref<ApiRequestError | null>(null)
const statusOptions = computed(() => [
  { value: 'active', label: t('bo.website.shown') },
  { value: 'inactive', label: t('bo.website.hidden') },
])

function openFolder(folder: WebsiteFolder | null, parent: WebsiteFolder | null = null): void {
  editingFolder.value = folder
  newParent.value = parent
  folderForm.name = folder?.name ?? ''
  folderForm.sort_order = String(folder?.sort_order ?? 0)
  folderForm.status = folder?.status ?? 'active'
  folderError.value = null
  folderOpen.value = true
}

async function saveFolder(): Promise<void> {
  folderBusy.value = true
  folderError.value = null
  const body = { name: folderForm.name, sort_order: Number(folderForm.sort_order) || 0, status: folderForm.status }
  try {
    const saved = editingFolder.value
      ? await websiteApi.updateFolder(editingFolder.value.id, body)
      : await websiteApi.createFolder({ ...body, parent_id: newParent.value?.id ?? null })
    const isNew = editingFolder.value === null
    folderOpen.value = false
    await loadFolders()
    if (isNew) await select(saved)
  } catch (e) {
    folderError.value = e instanceof ApiRequestError ? e : null
  } finally {
    folderBusy.value = false
  }
}

// --- Files ------------------------------------------------------------------

const files = ref<WebsiteFile[]>([])
const loadingFiles = ref(false)

async function loadFiles(): Promise<void> {
  if (selectedId.value === null) return
  loadingFiles.value = true
  try {
    files.value = await websiteApi.files(selectedId.value)
  } catch (e) {
    fail(e)
  } finally {
    loadingFiles.value = false
  }
}

/** One row per file picked — sent one at a time so each can be the full 50 MB. */
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

async function onFilesPicked(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const picked = Array.from(input.files ?? [])
  input.value = ''
  const folderId = selectedId.value
  if (picked.length === 0 || folderId === null) return

  const rows = picked.map((file) => {
    const row: UploadRow = { key: ++uploadKey, name: file.name, progress: 0, status: 'waiting' }
    if (file.size > MAX_WEBSITE_FILE_BYTES) {
      row.status = 'failed'
      row.error = t('bo.website.downloads.tooLarge')
    }
    return { file, row }
  })
  uploads.value = [...uploads.value.filter((u) => u.status !== 'done'), ...rows.map((r) => r.row)]

  for (const { file, row: plain } of rows) {
    const row = uploads.value.find((u) => u.key === plain.key)!
    if (row.status === 'failed') continue
    row.status = 'uploading'
    try {
      await websiteApi.uploadFile(folderId, file, (fraction) => (row.progress = fraction))
      row.status = 'done'
      row.progress = 1
    } catch (e) {
      row.status = 'failed'
      row.error = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed')
    }
  }

  if (selectedId.value === folderId) await loadFiles()
  await loadFolders()
}

const renaming = ref<WebsiteFile | null>(null)
const renameValue = ref('')
const renameBusy = ref(false)
const renameError = ref<string | null>(null)
function openRename(file: WebsiteFile): void {
  renaming.value = file
  renameValue.value = file.name
  renameError.value = null
}
async function saveRename(): Promise<void> {
  if (!renaming.value) return
  renameBusy.value = true
  try {
    await websiteApi.renameFile(renaming.value.id, renameValue.value)
    renaming.value = null
    await loadFiles()
  } catch (e) {
    renameError.value = e instanceof ApiRequestError ? (e.fieldError('name') ?? e.message) : t('bo.common.failed')
  } finally {
    renameBusy.value = false
  }
}

/** Removing a folder or a file asks first. */
const removing = ref<{ kind: 'folder'; item: WebsiteFolder } | { kind: 'file'; item: WebsiteFile } | null>(null)
const removeBusy = ref(false)
async function confirmRemove(): Promise<void> {
  const target = removing.value
  if (!target) return
  removeBusy.value = true
  try {
    if (target.kind === 'folder') {
      await websiteApi.removeFolder(target.item.id)
      if (selectedId.value === target.item.id || selected.value?.parent_id === target.item.id) selectedId.value = null
      files.value = []
      await loadFolders()
    } else {
      await websiteApi.removeFile(target.item.id)
      await Promise.all([loadFiles(), loadFolders()])
    }
    removing.value = null
  } catch (e) {
    fail(e)
    removing.value = null
  } finally {
    removeBusy.value = false
  }
}

onMounted(loadFolders)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('bo.website.downloads.hint') }}</p>
      <BaseButton v-if="canManage" @click="openFolder(null)">{{ t('bo.website.downloads.newFolder') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
      <aside class="self-start rounded-[--radius-card] border border-neutral-200 bg-white">
        <div v-if="loadingFolders && folders.length === 0" class="flex justify-center py-8"><BaseSpinner /></div>
        <p v-else-if="folders.length === 0" class="p-4 text-sm text-neutral-500">{{ t('bo.website.downloads.noFolders') }}</p>
        <ul v-else class="divide-y divide-neutral-100 py-1">
          <li v-for="folder in folders" :key="folder.id">
            <button
              type="button"
              class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-neutral-50"
              :class="selectedId === folder.id ? 'bg-primary-50 font-semibold text-neutral-900' : 'text-neutral-800'"
              @click="select(folder)"
            >
              <span aria-hidden="true">📁</span>
              <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
              <BaseBadge v-if="folder.status === 'inactive'" variant="neutral">{{ t('bo.website.hidden') }}</BaseBadge>
              <span class="text-xs text-neutral-400">{{ folder.files_count ?? 0 }}</span>
            </button>
            <ul v-if="folder.children?.length">
              <li v-for="child in folder.children" :key="child.id">
                <button
                  type="button"
                  class="flex w-full items-center gap-2 py-2 pr-3 pl-9 text-left text-sm hover:bg-neutral-50"
                  :class="selectedId === child.id ? 'bg-primary-50 font-semibold text-neutral-900' : 'text-neutral-700'"
                  @click="select(child)"
                >
                  <span aria-hidden="true">📂</span>
                  <span class="min-w-0 flex-1 truncate">{{ child.name }}</span>
                  <BaseBadge v-if="child.status === 'inactive'" variant="neutral">{{ t('bo.website.hidden') }}</BaseBadge>
                  <span class="text-xs text-neutral-400">{{ child.files_count ?? 0 }}</span>
                </button>
              </li>
            </ul>
          </li>
        </ul>
      </aside>

      <section class="min-w-0">
        <p v-if="!selected" class="rounded-[--radius-card] border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500">
          {{ folders.length === 0 ? t('bo.website.downloads.noFolders') : t('bo.website.downloads.pickFolder') }}
        </p>

        <template v-else>
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <p v-if="selectedParent" class="text-xs text-neutral-500">{{ selectedParent.name }} ›</p>
              <h2 class="flex items-center gap-2 truncate text-lg font-semibold text-neutral-900">
                {{ selected.name }}
                <BaseBadge v-if="selected.status === 'inactive'" variant="neutral">{{ t('bo.website.hidden') }}</BaseBadge>
              </h2>
            </div>
            <div v-if="canManage" class="flex flex-wrap gap-2">
              <BaseButton v-if="selected.parent_id === null" variant="outline" size="sm" @click="openFolder(null, selected)">
                {{ t('bo.website.downloads.newSubFolder') }}
              </BaseButton>
              <BaseButton variant="outline" size="sm" @click="openFolder(selected)">{{ t('bo.website.edit') }}</BaseButton>
              <BaseButton variant="danger" size="sm" @click="removing = { kind: 'folder', item: selected }">{{ t('bo.website.downloads.removeFolder') }}</BaseButton>
              <BaseButton size="sm" :loading="uploading" @click="fileInput?.click()">{{ t('bo.website.downloads.upload') }}</BaseButton>
              <input ref="fileInput" type="file" multiple class="hidden" :accept="WEBSITE_FILE_ACCEPT" @change="onFilesPicked" />
            </div>
          </div>
          <p v-if="canManage" class="mb-4 text-xs text-neutral-500">{{ t('bo.website.downloads.uploadHint') }}</p>

          <ul v-if="uploads.length" class="mb-4 space-y-2 rounded-[--radius-card] border border-neutral-200 bg-white p-3">
            <li v-for="row in uploads" :key="row.key" class="text-sm">
              <div class="flex items-center justify-between gap-3">
                <span class="min-w-0 truncate text-neutral-800">{{ row.name }}</span>
                <span v-if="row.status === 'done'" class="shrink-0 text-xs font-medium text-green-600">✓</span>
                <span v-else-if="row.status === 'failed'" class="shrink-0 text-xs text-red-600">{{ row.error }}</span>
                <span v-else class="shrink-0 text-xs text-neutral-500">{{ Math.round(row.progress * 100) }}%</span>
              </div>
              <div v-if="row.status === 'uploading' || row.status === 'waiting'" class="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100">
                <div class="h-full bg-primary-500 transition-all" :style="{ width: `${Math.round(row.progress * 100)}%` }" />
              </div>
            </li>
          </ul>

          <div v-if="loadingFiles" class="flex justify-center py-8"><BaseSpinner /></div>
          <p v-else-if="files.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
            {{ t('bo.website.downloads.emptyFolder') }}
          </p>
          <ul v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
            <li v-for="file in files" :key="file.id" class="flex items-center gap-3 px-3 py-2.5">
              <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-neutral-100">
                <img v-if="file.is_image" :src="file.url" alt="" class="h-full w-full object-cover" />
                <span v-else class="text-[10px] font-bold text-neutral-500 uppercase">{{ file.extension }}</span>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-neutral-900">
                  {{ file.name }}<span class="font-normal text-neutral-400">.{{ file.extension }}</span>
                </p>
                <p class="text-xs text-neutral-500">{{ formatBytes(file.size) }} · {{ formatDate(file.created_at) }}</p>
              </div>
              <div v-if="canManage" class="flex shrink-0 flex-col items-end gap-1 text-sm sm:flex-row sm:gap-3">
                <button type="button" class="text-secondary-700 hover:underline" @click="openRename(file)">{{ t('bo.website.downloads.rename') }}</button>
                <button type="button" class="text-red-600 hover:underline" @click="removing = { kind: 'file', item: file }">{{ t('bo.website.remove') }}</button>
              </div>
            </li>
          </ul>
        </template>
      </section>
    </div>

    <BaseModal
      v-model="folderOpen"
      :title="editingFolder ? t('bo.website.downloads.editFolder') : newParent ? t('bo.website.downloads.newSubFolderIn', { name: newParent.name }) : t('bo.website.downloads.newFolder')"
      size="sm"
    >
      <form id="bo-website-folder" class="space-y-4" @submit.prevent="saveFolder">
        <BaseAlert v-if="folderError && !folderError.fieldError('name')" variant="danger">{{ folderError.message }}</BaseAlert>
        <BaseInput v-model="folderForm.name" :label="t('bo.website.downloads.folderName')" :error="folderError?.fieldError('name')" required />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="folderForm.sort_order" type="number" :label="t('bo.website.order')" />
          <BaseSelect v-model="folderForm.status" :label="t('bo.website.status')" :options="statusOptions" />
        </div>
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="folderOpen = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-website-folder" :loading="folderBusy">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal :model-value="renaming !== null" :title="t('bo.website.downloads.renameTitle')" size="sm" @update:model-value="renaming = null">
      <form id="bo-website-rename" @submit.prevent="saveRename">
        <BaseInput v-model="renameValue" :label="t('bo.website.downloads.fileName')" :error="renameError ?? undefined" required />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="renaming = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-website-rename" :loading="renameBusy">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal :model-value="removing !== null" :title="t('bo.website.removeTitle')" size="sm" @update:model-value="removing = null">
      <p class="text-sm text-neutral-600">
        {{ removing?.kind === 'folder' ? t('bo.website.downloads.removeFolderText', { name: removing.item.name }) : t('bo.website.removeText') }}
      </p>
      <template #footer>
        <BaseButton variant="ghost" @click="removing = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton variant="danger" :loading="removeBusy" @click="confirmRemove">{{ t('bo.website.remove') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
