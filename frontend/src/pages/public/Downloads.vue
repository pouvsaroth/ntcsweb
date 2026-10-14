<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import {
  formatFileSize,
  MAX_ZIP_BYTES,
  MAX_ZIP_FILES,
  publicDownloadsService,
  type PublicDownloadFolder,
  type DownloadFile,
  type PublicDownloadFolderDetail,
} from '@/services/downloads'

/**
 * The Download page — folders of files anyone can download, managed under
 * the admin panel's Upload menu (see admin/Uploads.vue). `?folder=<id>`
 * opens one folder (its sub-folders and files), so a folder's link can be
 * shared; without it, the top folders are listed.
 */
const { t } = useI18n()
const route = useRoute()

const folderId = computed(() => {
  const id = Number(route.query.folder)
  return Number.isInteger(id) && id > 0 ? id : null
})

const folders = ref<PublicDownloadFolder[]>([])
const folder = ref<PublicDownloadFolderDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load() {
  loading.value = true
  selected.value = new Set()
  viewingIndex.value = null
  notFound.value = false
  try {
    if (folderId.value === null) {
      folder.value = null
      folders.value = await publicDownloadsService.list()
    } else {
      folder.value = await publicDownloadsService.folder(folderId.value)
      notFound.value = folder.value === null
    }
  } finally {
    loading.value = false
  }
}

// --- Several files at once: tick them and get one .zip ----------------------

const selected = ref(new Set<number>())

const allSelected = computed(() => !!folder.value?.files.length && selected.value.size === folder.value.files.length)

function toggle(id: number) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(folder.value?.files.map((f) => f.id) ?? [])
}

/** What the zip would hold — the ticked files, or the whole folder when none are ticked. */
const zipFiles = computed(() => {
  const files = folder.value?.files ?? []
  return selected.value.size ? files.filter((f) => selected.value.has(f.id)) : files
})

const zipTooBig = computed(
  () => zipFiles.value.length > MAX_ZIP_FILES || zipFiles.value.reduce((sum, f) => sum + f.size, 0) > MAX_ZIP_BYTES,
)

// --- Photo view: click a picture's thumbnail to see it full-size ----------------

const images = computed(() => folder.value?.files.filter((f) => f.is_image) ?? [])
const viewingIndex = ref<number | null>(null)
const viewing = computed(() => (viewingIndex.value === null ? null : (images.value[viewingIndex.value] ?? null)))

function view(file: DownloadFile) {
  const index = images.value.findIndex((f) => f.id === file.id)
  viewingIndex.value = index >= 0 ? index : null
}

function closeView() {
  viewingIndex.value = null
}

function step(delta: number) {
  if (viewingIndex.value === null || images.value.length < 2) return
  viewingIndex.value = (viewingIndex.value + delta + images.value.length) % images.value.length
}

function onKey(event: KeyboardEvent) {
  if (viewingIndex.value === null) return
  if (event.key === 'Escape') closeView()
  else if (event.key === 'ArrowLeft') step(-1)
  else if (event.key === 'ArrowRight') step(1)
}

// Swipe left/right on a phone.
let touchStartX: number | null = null
function onTouchStart(event: TouchEvent) {
  touchStartX = event.touches[0]?.clientX ?? null
}
function onTouchEnd(event: TouchEvent) {
  const endX = event.changedTouches[0]?.clientX
  if (touchStartX !== null && endX !== undefined && Math.abs(endX - touchStartX) > 50) step(endX < touchStartX ? 1 : -1)
  touchStartX = null
}

watch(viewingIndex, (index) => {
  document.body.style.overflow = index === null ? '' : 'hidden'
})
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})

const zipUrl = computed(() => (folder.value ? publicDownloadsService.zipUrl(folder.value.id, [...selected.value]) : ''))

function folderLink(id: number) {
  return { path: '/downloads', query: { folder: String(id) } }
}

/** How much a folder card holds — "3 folders · 12 files". */
function folderSummary(f: PublicDownloadFolder): string {
  const parts = []
  if (f.children?.length) parts.push(t('downloads.foldersCount', { count: f.children.length }, f.children.length))
  parts.push(t('downloads.filesCount', { count: f.files_count }, f.files_count))
  return parts.join(' · ')
}

// Last, so everything load() resets (selection, photo view) already exists.
watch(folderId, () => void load(), { immediate: true })
</script>

<template>
  <div>

    <SectionContainer>
      <!-- Where you are: Download › Parent › Folder -->
      <nav v-if="folderId !== null" class="mb-6 flex flex-wrap items-center gap-1.5 text-sm text-neutral-500" aria-label="breadcrumb">
        <RouterLink to="/downloads" class="font-medium text-primary-700 hover:underline">{{ t('downloads.title') }}</RouterLink>
        <template v-if="folder?.parent">
          <span>›</span>
          <RouterLink :to="folderLink(folder.parent.id)" class="font-medium text-primary-700 hover:underline">{{ folder.parent.name }}</RouterLink>
        </template>
        <template v-if="folder">
          <span>›</span>
          <span class="text-neutral-800">{{ folder.name }}</span>
        </template>
      </nav>

      <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <div v-for="i in 8" :key="i" class="h-28 animate-pulse rounded-2xl bg-neutral-100" />
      </div>

      <EmptyState v-else-if="notFound" :title="t('downloads.notFoundTitle')" :message="t('downloads.notFoundMessage')" />

      <!-- Top level: the folders -->
      <template v-else-if="folderId === null">
        <EmptyState v-if="folders.length === 0" :title="t('downloads.emptyTitle')" :message="t('downloads.emptyMessage')" />
        <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <RouterLink
            v-for="f in folders"
            :key="f.id"
            :to="folderLink(f.id)"
            class="flex flex-col items-center gap-2 rounded-2xl border border-neutral-200 bg-white p-5 text-center transition hover:border-primary-400 hover:shadow-md"
          >
            <svg class="h-12 w-12 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z" />
            </svg>
            <span class="line-clamp-2 font-semibold text-neutral-900">{{ f.name }}</span>
            <span class="text-xs text-neutral-500">{{ folderSummary(f) }}</span>
          </RouterLink>
        </div>
      </template>

      <!-- One folder: its sub-folders, then its files -->
      <template v-else-if="folder">
        <div v-if="folder.children.length" class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <RouterLink
            v-for="child in folder.children"
            :key="child.id"
            :to="folderLink(child.id)"
            class="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 transition hover:border-primary-400 hover:shadow-md"
          >
            <svg class="h-9 w-9 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z" />
            </svg>
            <span class="min-w-0">
              <span class="block truncate font-semibold text-neutral-900">{{ child.name }}</span>
              <span class="text-xs text-neutral-500">{{ folderSummary(child) }}</span>
            </span>
          </RouterLink>
        </div>

        <EmptyState
          v-if="folder.files.length === 0 && folder.children.length === 0"
          :title="t('downloads.emptyFolderTitle')"
          :message="t('downloads.emptyFolderMessage')"
        />

        <div
          v-if="folder.files.length > 1"
          class="mb-4 flex flex-col gap-3 rounded-2xl border border-neutral-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-neutral-800">
            <input type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600" :checked="allSelected" @change="toggleAll" />
            {{ t('downloads.selectAll') }}
            <span v-if="selected.size" class="font-normal text-neutral-500">· {{ t('downloads.selectedCount', { count: selected.size }) }}</span>
          </label>
          <div class="flex flex-col gap-1 sm:items-end">
            <span
              v-if="zipTooBig"
              class="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-neutral-200 px-4 py-2 text-sm font-medium text-neutral-500"
            >
              {{ selected.size ? t('downloads.downloadSelected') : t('downloads.downloadAll') }}
            </span>
            <a
              v-else
              :href="zipUrl"
              class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-secondary-900 hover:bg-primary-700"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
              </svg>
              {{ selected.size ? t('downloads.downloadSelected') : t('downloads.downloadAll') }}
            </a>
            <p v-if="zipTooBig" class="text-xs text-danger-600">
              {{ t('downloads.tooManyForZip', { files: MAX_ZIP_FILES, size: formatFileSize(MAX_ZIP_BYTES) }) }}
            </p>
          </div>
        </div>

        <div v-if="folder.files.length" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <div
            v-for="file in folder.files"
            :key="file.id"
            class="flex flex-col overflow-hidden rounded-2xl border bg-white"
            :class="selected.has(file.id) ? 'border-primary-500 ring-2 ring-primary-200' : 'border-neutral-200'"
          >
            <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-neutral-100">
              <button
                v-if="file.is_image"
                type="button"
                class="group absolute inset-0 cursor-zoom-in"
                :aria-label="t('downloads.viewPhoto', { name: file.name })"
                @click="view(file)"
              >
                <img :src="file.url" :alt="file.name" class="h-full w-full object-cover transition-transform group-hover:scale-105" loading="lazy" />
              </button>
              <span v-else class="rounded-lg bg-white px-3 py-2 text-lg font-bold uppercase text-neutral-500 shadow-sm">{{ file.extension }}</span>
              <label
                v-if="folder.files.length > 1"
                class="absolute left-2 top-2 flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg bg-white/90 shadow-sm"
              >
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-neutral-300 text-primary-600"
                  :checked="selected.has(file.id)"
                  :aria-label="t('downloads.selectFile', { name: file.name })"
                  @change="toggle(file.id)"
                />
              </label>
            </div>
            <div class="flex flex-1 flex-col gap-2 p-3">
              <p class="break-words text-sm font-medium text-neutral-900">
                {{ file.name }}<span class="text-neutral-400">.{{ file.extension }}</span>
              </p>
              <p class="text-xs text-neutral-500">{{ formatFileSize(file.size) }}</p>
              <a
                :href="publicDownloadsService.fileUrl(file.id)"
                class="mt-auto inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-secondary-900 hover:bg-primary-700"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                </svg>
                {{ t('downloads.download') }}
              </a>
            </div>
          </div>
        </div>
      </template>
    </SectionContainer>

    <!-- Photo view — full-size, with previous/next through the folder's pictures. -->
    <div
      v-if="viewing"
      class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/90 p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="viewing.name"
      @click.self="closeView"
      @touchstart.passive="onTouchStart"
      @touchend="onTouchEnd"
    >
      <button
        type="button"
        class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20"
        :aria-label="t('common.close')"
        @click="closeView"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>

      <template v-if="images.length > 1">
        <button
          type="button"
          class="absolute left-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 sm:left-4"
          :aria-label="t('downloads.previousPhoto')"
          @click="step(-1)"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <button
          type="button"
          class="absolute right-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 sm:right-4"
          :aria-label="t('downloads.nextPhoto')"
          @click="step(1)"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </template>

      <div class="flex max-h-full max-w-4xl flex-col items-center">
        <img :key="viewing.id" :src="viewing.url" :alt="viewing.name" class="max-h-[75vh] max-w-full rounded-lg object-contain" />

        <div class="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 px-12 text-center">
          <p class="break-all text-sm text-white/90">
            {{ viewing.name }}<span class="text-white/50">.{{ viewing.extension }}</span>
            <span v-if="images.length > 1" class="ml-2 text-white/50">{{ viewingIndex! + 1 }} / {{ images.length }}</span>
          </p>
          <a
            :href="publicDownloadsService.fileUrl(viewing.id)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-neutral-800 hover:bg-neutral-100"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
            </svg>
            {{ t('downloads.download') }}
          </a>
        </div>
      </div>
    </div>
  </div>
</template>
