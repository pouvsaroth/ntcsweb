<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import HomeSlideFormModal from '@/components/admin/HomeSlideFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { homeSlidesService, type HomeSlide } from '@/services/homeSlides'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<HomeSlide>((query) =>
  homeSlidesService.list(query),
)

const columns = [
  { key: 'image_url', label: t('admin.homeSlides.columnPreview') },
  { key: 'title', label: t('admin.homeSlides.columnTitle') },
  { key: 'sort_order', label: t('admin.homeSlides.columnOrder'), sortable: true },
  { key: 'status', label: t('admin.homeSlides.columnStatus') },
  { key: 'actions', label: t('admin.homeSlides.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingSlide = ref<HomeSlide | null>(null)

function openCreate() {
  editingSlide.value = null
  modalOpen.value = true
}

function openEdit(slide: HomeSlide) {
  editingSlide.value = slide
  modalOpen.value = true
}

async function remove(slide: HomeSlide) {
  if (!window.confirm(t('admin.homeSlides.deleteConfirm'))) return
  await homeSlidesService.remove(slide.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.homeSlides.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.homeSlides.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.homeSlides.addSlide') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.homeSlides.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-image_url="{ row }">
        <div class="h-12 w-20 overflow-hidden rounded-md bg-neutral-100">
          <img :src="row.image_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-title="{ row }">
        <p class="font-medium text-neutral-800">{{ row.title || '—' }}</p>
        <p v-if="row.subtitle" class="text-xs text-neutral-500">{{ row.subtitle }}</p>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.homeSlides.statusActive') : t('admin.homeSlides.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <button type="button" class="text-sm font-medium text-secondary-600 hover:text-secondary-700" @click="openEdit(row)">
            {{ t('admin.homeSlides.edit') }}
          </button>
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.homeSlides.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <HomeSlideFormModal v-model="modalOpen" :slide="editingSlide" @saved="fetch" />
  </div>
</template>
