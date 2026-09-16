<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import PromotionFormModal from '@/components/admin/PromotionFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { promotionsService, type Promotion } from '@/services/promotions'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<Promotion>((query) =>
  promotionsService.list(query),
)

const columns = computed(() => [
  { key: 'image_url', label: t('admin.promotions.columnPreview') },
  { key: 'title', label: t('admin.promotions.columnTitle') },
  { key: 'sort_order', label: t('admin.promotions.columnOrder'), sortable: true },
  { key: 'status', label: t('admin.promotions.columnStatus') },
  { key: 'actions', label: t('admin.promotions.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingPromotion = ref<Promotion | null>(null)

function openCreate() {
  editingPromotion.value = null
  modalOpen.value = true
}

function openEdit(promotion: Promotion) {
  editingPromotion.value = promotion
  modalOpen.value = true
}

async function remove(promotion: Promotion) {
  if (!window.confirm(t('admin.promotions.deleteConfirm'))) return
  await promotionsService.remove(promotion.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.promotions.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.promotions.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.promotions.addImage') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.promotions.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-image_url="{ row }">
        <div class="h-14 w-24 overflow-hidden rounded-md bg-neutral-100">
          <img :src="row.image_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-title="{ row }">{{ row.title || '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.promotions.statusActive') : t('admin.promotions.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.promotions.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PromotionFormModal v-model="modalOpen" :promotion="editingPromotion" @saved="fetch" />
  </div>
</template>
