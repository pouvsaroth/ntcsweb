<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { formatFileSize, publicDownloadsService, type PublicDownloadFolder, type PublicDownloadFolderDetail } from '@/services/downloads'

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

watch(folderId, () => void load(), { immediate: true })

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
</script>

<template>
  <div>
    <PageHero :title="folder?.name ?? t('downloads.title')" :subtitle="folder ? undefined : t('downloads.subtitle')" />

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

        <div v-if="folder.files.length" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <div v-for="file in folder.files" :key="file.id" class="flex flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white">
            <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-neutral-100">
              <img v-if="file.is_image" :src="file.url" :alt="file.name" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
              <span v-else class="rounded-lg bg-white px-3 py-2 text-lg font-bold uppercase text-neutral-500 shadow-sm">{{ file.extension }}</span>
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
  </div>
</template>
