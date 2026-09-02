<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CoursePackageFormModal from '@/components/admin/CoursePackageFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<CoursePackage>((query) =>
  coursePackagesService.list(query),
)

const columns = [
  { key: 'code', label: t('admin.coursePackages.columnCode') },
  { key: 'name', label: t('admin.coursePackages.columnName') },
  { key: 'program', label: t('admin.coursePackages.columnProgram') },
  { key: 'books', label: t('admin.coursePackages.columnBooks') },
  { key: 'price', label: t('admin.coursePackages.columnPrice') },
  { key: 'is_active', label: t('admin.coursePackages.columnStatus') },
  { key: 'actions', label: t('admin.coursePackages.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingPackage = ref<CoursePackage | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingPackage.value = null
  modalOpen.value = true
}

function openEdit(coursePackage: CoursePackage) {
  editingPackage.value = coursePackage
  modalOpen.value = true
}

async function remove(coursePackage: CoursePackage) {
  if (!window.confirm(t('admin.coursePackages.deleteConfirm'))) return
  deleteError.value = null

  try {
    await coursePackagesService.remove(coursePackage.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.coursePackages.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.coursePackages.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.coursePackages.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.coursePackages.addPackage') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.coursePackages.emptyMessage')">
      <template #cell-program="{ row }">{{ row.academic_program?.code ?? '—' }}</template>
      <template #cell-books="{ row }">{{ row.books?.map((b) => b.title).join(', ') || '—' }}</template>
      <template #cell-price="{ row }">{{ row.price.toFixed(2) }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.coursePackages.statusActive') : t('admin.coursePackages.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.coursePackages.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CoursePackageFormModal v-model="modalOpen" :course-package="editingPackage" @saved="fetch" />
  </div>
</template>
