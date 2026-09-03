<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ClassroomFormModal from '@/components/admin/ClassroomFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { classroomsService, type Classroom } from '@/services/classrooms'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Classroom>((query) => classroomsService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.classrooms.columnName') },
  { key: 'code', label: t('admin.classrooms.columnCode') },
  { key: 'building', label: t('admin.classrooms.columnBuilding') },
  { key: 'capacity', label: t('admin.classrooms.columnCapacity') },
  { key: 'status', label: t('admin.classrooms.columnStatus') },
  { key: 'actions', label: t('admin.classrooms.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingClassroom = ref<Classroom | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingClassroom.value = null
  modalOpen.value = true
}

function openEdit(classroom: Classroom) {
  editingClassroom.value = classroom
  modalOpen.value = true
}

async function remove(classroom: Classroom) {
  if (!window.confirm(t('admin.classrooms.deleteConfirm'))) return
  deleteError.value = null

  try {
    await classroomsService.remove(classroom.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.classrooms.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.classrooms.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.classrooms.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.classrooms.addClassroom') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.classrooms.emptyMessage')">
      <template #cell-code="{ row }">
        {{ row.code || '—' }}
      </template>
      <template #cell-building="{ row }">
        {{ row.building?.name || '—' }}
      </template>
      <template #cell-capacity="{ row }">
        {{ row.capacity ?? '—' }}
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.classrooms.statusActive') : t('admin.classrooms.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <BaseButton size="sm" variant="outline" :to="`/admin/classrooms/${row.id}/tables`">
            {{ t('admin.classrooms.manageTables') }}
          </BaseButton>
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.classrooms.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ClassroomFormModal v-model="modalOpen" :classroom="editingClassroom" @saved="fetch" />
  </div>
</template>
