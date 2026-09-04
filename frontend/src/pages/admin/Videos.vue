<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import VideoFormModal from '@/components/admin/VideoFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { type Video, videosService } from '@/services/videos'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Video>((query) => videosService.list(query))

const columns = [
  { key: 'thumbnail_url', label: t('admin.videos.columnThumbnail') },
  { key: 'title', label: t('admin.videos.columnTitle') },
  { key: 'course_package', label: t('admin.videos.columnCourse') },
  { key: 'sort_order', label: t('admin.videos.columnOrder') },
  { key: 'status', label: t('admin.videos.columnStatus') },
  { key: 'actions', label: t('admin.videos.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingVideo = ref<Video | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingVideo.value = null
  modalOpen.value = true
}

function openEdit(video: Video) {
  editingVideo.value = video
  modalOpen.value = true
}

async function remove(video: Video) {
  if (!window.confirm(t('admin.videos.deleteConfirm'))) return
  deleteError.value = null

  try {
    await videosService.remove(video.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.videos.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.videos.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.videos.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.videos.addVideo') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.videos.emptyMessage')">
      <template #cell-thumbnail_url="{ row }">
        <div class="h-12 w-20 overflow-hidden rounded-lg bg-neutral-100">
          <img v-if="row.thumbnail_url" :src="row.thumbnail_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-course_package="{ row }">{{ row.course_package?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.videos.statusActive') : t('admin.videos.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.videos.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <VideoFormModal v-model="modalOpen" :video="editingVideo" @saved="fetch" />
  </div>
</template>
