<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import FormCategoryFormModal from '@/components/admin/FormCategoryFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formCategoriesService, type FormCategory } from '@/services/formCategories'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<FormCategory>((query) =>
  formCategoriesService.list(query),
  { perPage: 25 },
)

const columns = computed(() => [
  { key: 'name', label: t('admin.formCategories.columnName') },
  { key: 'order', label: t('admin.formCategories.columnOrder') },
  { key: 'actions', label: t('admin.formCategories.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingCategory = ref<FormCategory | null>(null)

function openCreate() {
  editingCategory.value = null
  modalOpen.value = true
}

function openEdit(category: FormCategory) {
  editingCategory.value = category
  modalOpen.value = true
}

async function remove(category: FormCategory) {
  if (!window.confirm(t('admin.formCategories.deleteConfirm'))) return
  await formCategoriesService.remove(category.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.formCategories.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.formCategories.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.formCategories.addCategory') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.formCategories.emptyMessage')"
    >
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.formCategories.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <FormCategoryFormModal v-model="modalOpen" :category="editingCategory" @saved="fetch" />
  </div>
</template>
