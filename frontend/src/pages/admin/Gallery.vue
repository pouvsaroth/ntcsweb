<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GalleryFormModal from '@/components/admin/GalleryFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { galleryService, type GalleryPhoto } from '@/services/gallery'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<GalleryPhoto>((query) =>
  galleryService.list(query),
)

const columns = computed(() => [
  { key: 'image_url', label: t('admin.gallery.columnPreview') },
  { key: 'caption', label: t('admin.gallery.columnCaption') },
  { key: 'sort_order', label: t('admin.gallery.columnOrder'), sortable: true },
  { key: 'status', label: t('admin.gallery.columnStatus') },
  { key: 'actions', label: t('admin.gallery.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingPhoto = ref<GalleryPhoto | null>(null)

function openCreate() {
  editingPhoto.value = null
  modalOpen.value = true
}

function openEdit(photo: GalleryPhoto) {
  editingPhoto.value = photo
  modalOpen.value = true
}

async function remove(photo: GalleryPhoto) {
  if (!window.confirm(t('admin.gallery.deleteConfirm'))) return
  await galleryService.remove(photo.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.gallery.title') }}</h1>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.gallery.addPhoto') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.gallery.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-image_url="{ row }">
        <div class="h-14 w-14 overflow-hidden rounded-md bg-neutral-100">
          <img :src="row.image_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-caption="{ row }">{{ row.caption || '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.gallery.statusActive') : t('admin.gallery.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.gallery.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <GalleryFormModal v-model="modalOpen" :photo="editingPhoto" @saved="fetch" />
  </div>
</template>
