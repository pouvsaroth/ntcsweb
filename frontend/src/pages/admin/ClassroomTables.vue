<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import ClassroomTableFormModal from '@/components/admin/ClassroomTableFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { type Classroom, classroomsService } from '@/services/classrooms'
import { type ClassroomTable, classroomTablesService } from '@/services/classroomTables'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const classroomId = Number(route.params.id)

const classroom = ref<Classroom | null>(null)
const tables = ref<ClassroomTable[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const deleteError = ref<string | null>(null)

const columns = computed(() => [
  { key: 'name', label: t('admin.classroomTables.columnName') },
  { key: 'actions', label: t('admin.classroomTables.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingTable = ref<ClassroomTable | null>(null)

function openCreate() {
  editingTable.value = null
  modalOpen.value = true
}

function openEdit(table: ClassroomTable) {
  editingTable.value = table
  modalOpen.value = true
}

async function remove(table: ClassroomTable) {
  if (!window.confirm(t('admin.classroomTables.deleteConfirm'))) return
  deleteError.value = null

  try {
    await classroomTablesService.remove(table.id)
    await loadTables()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.classroomTables.deleteFailed')
  }
}

async function loadTables(): Promise<void> {
  const result = await classroomTablesService.list({ page: 1, per_page: 200, filter: { classroom_id: String(classroomId) } })
  tables.value = result.data
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    classroom.value = await classroomsService.get(classroomId)
    await loadTables()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classroomTables.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-2">
      <BaseButton variant="outline" size="sm" to="/admin/classrooms">{{ t('admin.classroomTables.backToClassrooms') }}</BaseButton>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="classroom">
      <div class="mb-6 flex items-center justify-between">
        <div>
          <h1 class="text-xl font-semibold text-neutral-900">{{ classroom.name }}</h1>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.classroomTables.pageSubtitle') }}</p>
        </div>
        <BaseButton @click="openCreate">{{ t('admin.classroomTables.addTable') }}</BaseButton>
      </div>

      <BaseAlert v-if="deleteError" variant="danger" class="mb-4">{{ deleteError }}</BaseAlert>

      <DataTable :columns="columns" :rows="tables" row-key="id" :empty-message="t('admin.classroomTables.emptyMessage')">
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="openEdit(row)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.classroomTables.delete') }}
            </button>
          </div>
        </template>
      </DataTable>

      <ClassroomTableFormModal v-model="modalOpen" :classroom="classroom" :table="editingTable" @saved="loadTables" />
    </template>
  </div>
</template>
