<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  PARTY_DOCUMENT_EXTENSIONS,
  PARTY_DOCUMENT_MAX_BYTES,
  fileSize,
  isImage,
  isPdf,
  partyDocumentsService,
  type PartyFile,
  type PartyFolder,
  type PartyListing,
  type PartyPlace,
  type PartyTreeFolder,
} from '@/services/partyDocuments'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Political Party > Documents: the party's own files in folders of any depth,
 * kept private on the server — never on the website. Open folders, search
 * every file by name, upload several at once (or drop them here), preview
 * pictures and PDFs, download one file or a whole folder as a .zip.
 * The open folder is in the address (?folder=), so it can be bookmarked.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const route = useRoute()
const router = useRouter()

const canCreate = computed(() => auth.can('party-documents.create'))
const canUpdate = computed(() => auth.can('party-documents.update'))
const canDelete = computed(() => auth.can('party-documents.delete'))

const folderId = computed<number | null>(() => {
  const raw = Number(route.query.folder)
  return Number.isInteger(raw) && raw > 0 ? raw : null
})

const listing = ref<PartyListing | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    listing.value = await partyDocumentsService.list({ folder_id: folderId.value, search: search.value.trim() })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(folderId, () => {
  search.value = ''
  void load()
})
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 300)
})
onMounted(load)

function openFolder(id: number | null): void {
  notice.value = null
  void router.push({ query: id ? { folder: String(id) } : {} })
}

const searching = computed(() => Boolean(listing.value?.search))
const locationText = (place: PartyPlace[] | null) => (place && place.length ? place.map((p) => p.name).join(' / ') : t('partyDocuments.top'))

// --- Folders -------------------------------------------------------------------

const folderModal = ref<{ mode: 'create' | 'rename'; folder: PartyFolder | null; name: string } | null>(null)
const folderError = ref<string | null>(null)
const savingFolder = ref(false)

function newFolder(): void {
  folderError.value = null
  folderModal.value = { mode: 'create', folder: null, name: '' }
}

function renameFolder(folder: PartyFolder): void {
  folderError.value = null
  folderModal.value = { mode: 'rename', folder, name: folder.name }
}

async function saveFolder(): Promise<void> {
  const modal = folderModal.value
  if (!modal || !modal.name.trim()) return
  savingFolder.value = true
  folderError.value = null
  try {
    if (modal.mode === 'create') await partyDocumentsService.createFolder(modal.name.trim(), folderId.value)
    else if (modal.folder) await partyDocumentsService.updateFolder(modal.folder.id, { name: modal.name.trim() })
    folderModal.value = null
    await load()
  } catch (e) {
    folderError.value = e instanceof ApiRequestError ? (e.errors?.name?.[0] ?? e.message) : t('partyDocuments.saveFailed')
  } finally {
    savingFolder.value = false
  }
}

async function deleteFolder(folder: PartyFolder): Promise<void> {
  const what = t('partyDocuments.deleteFolderConfirm', { name: folder.name, folders: folder.folders_count, files: folder.files_count })
  if (!(await confirmDialog.confirm({ message: what, danger: true }))) return
  try {
    await partyDocumentsService.removeFolder(folder.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.saveFailed')
  }
}

const zipping = ref<number | null>(null)
async function downloadFolder(folder: { id: number; name: string }): Promise<void> {
  zipping.value = folder.id
  error.value = null
  try {
    await partyDocumentsService.downloadFolder(folder)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.downloadFailed')
  } finally {
    zipping.value = null
  }
}

// --- Upload ----------------------------------------------------------------------

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref<{ done: number; total: number } | null>(null)
const dragging = ref(false)
const accept = PARTY_DOCUMENT_EXTENSIONS.map((e) => `.${e}`).join(',')

async function upload(list: File[]): Promise<void> {
  if (!canCreate.value || searching.value || list.length === 0) return
  const allowed = list.filter((f) => PARTY_DOCUMENT_EXTENSIONS.includes((f.name.split('.').pop() ?? '').toLowerCase()) && f.size <= PARTY_DOCUMENT_MAX_BYTES)
  const skipped = list.length - allowed.length
  error.value = null
  notice.value = null
  if (allowed.length === 0) {
    error.value = t('partyDocuments.nothingToUpload')
    return
  }
  uploading.value = { done: 0, total: allowed.length }
  try {
    await partyDocumentsService.upload(folderId.value, allowed, (done) => (uploading.value = { done, total: allowed.length }))
    notice.value = t('partyDocuments.uploaded', { n: allowed.length }) + (skipped ? ` ${t('partyDocuments.skipped', { n: skipped })}` : '')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.uploadFailed')
  } finally {
    uploading.value = null
    await load()
  }
}

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement
  void upload(Array.from(input.files ?? []))
  input.value = ''
}

function onDrop(event: DragEvent): void {
  dragging.value = false
  void upload(Array.from(event.dataTransfer?.files ?? []))
}

// --- Files -------------------------------------------------------------------------

const busyFile = ref<number | null>(null)
async function downloadFile(file: PartyFile): Promise<void> {
  busyFile.value = file.id
  error.value = null
  try {
    await partyDocumentsService.download(file)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.downloadFailed')
  } finally {
    busyFile.value = null
  }
}

const preview = ref<{ file: PartyFile; url: string } | null>(null)
async function openPreview(file: PartyFile): Promise<void> {
  busyFile.value = file.id
  error.value = null
  try {
    const blob = await partyDocumentsService.blob(file)
    closePreview()
    preview.value = { file, url: URL.createObjectURL(blob) }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.downloadFailed')
  } finally {
    busyFile.value = null
  }
}
function closePreview(): void {
  if (preview.value) URL.revokeObjectURL(preview.value.url)
  preview.value = null
}
onBeforeUnmount(closePreview)

const renameModal = ref<{ file: PartyFile; name: string } | null>(null)
const renameError = ref<string | null>(null)
async function saveRename(): Promise<void> {
  const modal = renameModal.value
  if (!modal || !modal.name.trim()) return
  renameError.value = null
  try {
    await partyDocumentsService.updateFile(modal.file.id, { name: modal.name.trim() })
    renameModal.value = null
    await load()
  } catch (e) {
    renameError.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.saveFailed')
  }
}

async function deleteFile(file: PartyFile): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('partyDocuments.deleteFileConfirm', { name: file.name }), danger: true }))) return
  try {
    await partyDocumentsService.removeFile(file.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('partyDocuments.saveFailed')
  }
}

// --- Move (a file or a folder) ------------------------------------------------------------

const tree = ref<PartyTreeFolder[]>([])
const moveModal = ref<{ kind: 'file' | 'folder'; id: number; name: string; target: string } | null>(null)
const moveError = ref<string | null>(null)
const moveOptions = computed(() => [
  { value: '', label: t('partyDocuments.top') },
  ...tree.value
    // A folder can't go inside itself.
    .filter((f) => moveModal.value?.kind !== 'folder' || !isInside(f.id, moveModal.value.id))
    .map((f) => ({ value: String(f.id), label: f.label })),
])

function isInside(id: number, ancestor: number): boolean {
  const byId = new Map(tree.value.map((f) => [f.id, f]))
  for (let f = byId.get(id); f; f = f.parent_id ? byId.get(f.parent_id) : undefined) {
    if (f.id === ancestor) return true
  }
  return false
}

async function openMove(kind: 'file' | 'folder', item: { id: number; name: string }, current: number | null): Promise<void> {
  moveError.value = null
  tree.value = await partyDocumentsService.tree().catch(() => [])
  moveModal.value = { kind, id: item.id, name: item.name, target: current ? String(current) : '' }
}

async function saveMove(): Promise<void> {
  const modal = moveModal.value
  if (!modal) return
  const target = modal.target ? Number(modal.target) : null
  moveError.value = null
  try {
    if (modal.kind === 'file') await partyDocumentsService.updateFile(modal.id, { folder_id: target })
    else await partyDocumentsService.updateFolder(modal.id, { parent_id: target })
    moveModal.value = null
    await load()
  } catch (e) {
    moveError.value = e instanceof ApiRequestError ? (e.errors?.parent_id?.[0] ?? e.errors?.name?.[0] ?? e.message) : t('partyDocuments.saveFailed')
  }
}

const fileKind = (file: PartyFile) => (file.name.split('.').pop() ?? '').toUpperCase().slice(0, 4)
</script>

<template>
  <div @dragover.prevent="dragging = canCreate && !searching" @dragleave.self="dragging = false" @drop.prevent="onDrop">
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('partyDocuments.title') }}</h1>
      <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
        <input
          v-model="search"
          type="search"
          :placeholder="t('partyDocuments.searchPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:w-64"
        />
        <BaseButton v-if="canCreate && !searching" variant="outline" @click="newFolder">{{ t('partyDocuments.newFolder') }}</BaseButton>
        <BaseButton v-if="canCreate && !searching" :loading="uploading !== null" @click="fileInput?.click()">{{ t('partyDocuments.upload') }}</BaseButton>
        <input ref="fileInput" type="file" multiple class="hidden" :accept="accept" @change="onPick" />
      </div>
    </div>

    <p class="mb-3 text-sm text-neutral-500">{{ t('partyDocuments.intro') }}</p>

    <!-- Where we are: Documents / 2027 / Battambang -->
    <nav v-if="!searching" class="mb-3 flex flex-wrap items-center gap-1 text-sm" :aria-label="t('partyDocuments.title')">
      <button type="button" class="font-medium text-primary-700 hover:underline" @click="openFolder(null)">{{ t('partyDocuments.title') }}</button>
      <template v-for="(place, i) in listing?.path ?? []" :key="place.id">
        <span class="text-neutral-400">/</span>
        <button
          type="button"
          class="hover:underline"
          :class="i === (listing?.path.length ?? 0) - 1 ? 'font-semibold text-neutral-900' : 'font-medium text-primary-700'"
          @click="openFolder(place.id)"
        >
          {{ place.name }}
        </button>
      </template>
      <BaseButton
        v-if="listing?.folder"
        size="sm"
        variant="outline"
        class="ml-auto"
        :loading="zipping === listing.folder.id"
        @click="downloadFolder(listing.folder)"
      >
        {{ t('partyDocuments.downloadFolder') }}
      </BaseButton>
    </nav>
    <p v-else class="mb-3 text-sm text-neutral-600">{{ t('partyDocuments.searchResults', { q: listing?.search }) }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-3">{{ notice }}</BaseAlert>
    <BaseAlert v-if="uploading" variant="info" class="mb-3">{{ t('partyDocuments.uploading', { done: uploading.done, total: uploading.total }) }}</BaseAlert>

    <div
      v-if="dragging"
      class="mb-3 rounded-[--radius-card] border-2 border-dashed border-primary-400 bg-primary-50 py-8 text-center text-sm font-medium text-primary-800"
    >
      {{ t('partyDocuments.dropHere') }}
    </div>

    <div v-if="loading && !listing" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="listing">
      <p
        v-if="!listing.folders.length && !listing.files.length"
        class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500"
      >
        {{ searching ? t('partyDocuments.noResults') : canCreate ? t('partyDocuments.emptyCanUpload') : t('partyDocuments.empty') }}
      </p>

      <ul v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]">
        <!-- Folders -->
        <li v-for="folder in listing.folders" :key="`d${folder.id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 sm:px-4">
          <button type="button" class="flex w-full min-w-0 items-center gap-3 text-left sm:w-auto sm:flex-1" @click="openFolder(folder.id)">
            <svg class="h-8 w-8 shrink-0 text-primary-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M3 6.75A2.25 2.25 0 015.25 4.5h4.19a1.5 1.5 0 011.06.44l1.56 1.56h6.69A2.25 2.25 0 0121 8.75v8.5a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 17.25V6.75z" />
            </svg>
            <span class="min-w-0">
              <span class="block truncate font-medium text-neutral-900 hover:underline">{{ folder.name }}</span>
              <span class="block text-xs text-neutral-500">
                {{ t('partyDocuments.folderCounts', { folders: folder.folders_count, files: folder.files_count }) }}
                <template v-if="searching"> · {{ locationText(folder.location) }}</template>
              </span>
            </span>
          </button>
          <div class="-ml-2 flex flex-wrap items-center gap-1 pl-11 text-sm sm:ml-0 sm:pl-0">
            <button type="button" class="rounded px-2 py-1 text-primary-700 hover:bg-neutral-100" :disabled="zipping === folder.id" @click="downloadFolder(folder)">
              {{ zipping === folder.id ? '…' : t('partyDocuments.zip') }}
            </button>
            <button v-if="canUpdate" type="button" class="rounded px-2 py-1 text-neutral-700 hover:bg-neutral-100" @click="renameFolder(folder)">{{ t('partyDocuments.rename') }}</button>
            <button v-if="canUpdate" type="button" class="rounded px-2 py-1 text-neutral-700 hover:bg-neutral-100" @click="openMove('folder', folder, folder.parent_id)">{{ t('partyDocuments.move') }}</button>
            <button v-if="canDelete" type="button" class="rounded px-2 py-1 text-danger-600 hover:bg-neutral-100" @click="deleteFolder(folder)">{{ t('partyDocuments.delete') }}</button>
          </div>
        </li>

        <!-- Files -->
        <li v-for="file in listing.files" :key="`f${file.id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 sm:px-4">
          <button type="button" class="flex w-full min-w-0 items-center gap-3 text-left sm:w-auto sm:flex-1" @click="isImage(file) || isPdf(file) ? openPreview(file) : downloadFile(file)">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-neutral-100 text-[10px] font-bold text-neutral-600">{{ fileKind(file) }}</span>
            <span class="min-w-0">
              <span class="block truncate font-medium text-neutral-900 hover:underline">{{ file.name }}</span>
              <span class="block text-xs text-neutral-500">
                {{ fileSize(file.size) }}<template v-if="file.created_at"> · {{ formatDate(file.created_at) }}</template><template v-if="file.uploaded_by"> · {{ file.uploaded_by }}</template>
                <template v-if="searching">
                  ·
                  <a href="#" class="text-primary-700 hover:underline" @click.prevent.stop="openFolder(file.folder_id)">{{ locationText(file.location) }}</a>
                </template>
              </span>
            </span>
          </button>
          <div class="-ml-2 flex flex-wrap items-center gap-1 pl-11 text-sm sm:ml-0 sm:pl-0">
            <button v-if="isImage(file) || isPdf(file)" type="button" class="rounded px-2 py-1 text-neutral-700 hover:bg-neutral-100" @click="openPreview(file)">{{ t('partyDocuments.view') }}</button>
            <button type="button" class="rounded px-2 py-1 text-primary-700 hover:bg-neutral-100" :disabled="busyFile === file.id" @click="downloadFile(file)">
              {{ busyFile === file.id ? '…' : t('partyDocuments.download') }}
            </button>
            <button v-if="canUpdate" type="button" class="rounded px-2 py-1 text-neutral-700 hover:bg-neutral-100" @click="renameModal = { file, name: file.name }; renameError = null">{{ t('partyDocuments.rename') }}</button>
            <button v-if="canUpdate" type="button" class="rounded px-2 py-1 text-neutral-700 hover:bg-neutral-100" @click="openMove('file', file, file.folder_id)">{{ t('partyDocuments.move') }}</button>
            <button v-if="canDelete" type="button" class="rounded px-2 py-1 text-danger-600 hover:bg-neutral-100" @click="deleteFile(file)">{{ t('partyDocuments.delete') }}</button>
          </div>
        </li>
      </ul>
      <p v-if="canCreate && !searching" class="mt-2 text-xs text-neutral-500">{{ t('partyDocuments.uploadHint') }}</p>
    </template>

    <!-- New folder / rename folder -->
    <BaseModal :model-value="folderModal !== null" :title="folderModal?.mode === 'rename' ? t('partyDocuments.renameFolder') : t('partyDocuments.newFolder')" @update:model-value="folderModal = null">
      <form v-if="folderModal" @submit.prevent="saveFolder">
        <BaseInput v-model="folderModal.name" :label="t('partyDocuments.folderName')" required :error="folderError ?? undefined" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="folderModal = null">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="savingFolder" :disabled="!folderModal?.name.trim()" @click="saveFolder">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Rename file -->
    <BaseModal :model-value="renameModal !== null" :title="t('partyDocuments.renameFile')" @update:model-value="renameModal = null">
      <form v-if="renameModal" @submit.prevent="saveRename">
        <BaseInput v-model="renameModal.name" :label="t('partyDocuments.fileName')" required :error="renameError ?? undefined" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="renameModal = null">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!renameModal?.name.trim()" @click="saveRename">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Move -->
    <BaseModal :model-value="moveModal !== null" :title="t('partyDocuments.moveTitle', { name: moveModal?.name ?? '' })" @update:model-value="moveModal = null">
      <BaseSelect v-if="moveModal" v-model="moveModal.target" :options="moveOptions" :label="t('partyDocuments.moveTo')" :error="moveError ?? undefined" />
      <template #footer>
        <BaseButton variant="outline" @click="moveModal = null">{{ t('common.cancel') }}</BaseButton>
        <BaseButton @click="saveMove">{{ t('partyDocuments.move') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Preview a picture or PDF -->
    <BaseModal :model-value="preview !== null" size="xl" :title="preview?.file.name ?? ''" @update:model-value="closePreview">
      <template v-if="preview">
        <img v-if="isImage(preview.file)" :src="preview.url" :alt="preview.file.name" class="mx-auto max-h-[70vh] w-auto" />
        <iframe v-else :src="preview.url" :title="preview.file.name" class="h-[70vh] w-full rounded border border-neutral-200" />
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="closePreview">{{ t('common.close') }}</BaseButton>
        <BaseButton v-if="preview" @click="preview && downloadFile(preview.file)">{{ t('partyDocuments.download') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
