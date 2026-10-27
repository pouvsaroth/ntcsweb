<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import ClassroomTableFormModal from '@/components/admin/ClassroomTableFormModal.vue'
import ClassroomTablesBulkModal from '@/components/admin/ClassroomTablesBulkModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { type Classroom, classroomsService } from '@/services/classrooms'
import { type BulkTablesResult, type ClassroomTable, classroomTablesService } from '@/services/classroomTables'
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
  { key: 'sort_order', label: t('admin.classroomTables.columnOrder') },
  { key: 'allow_many_students', label: t('admin.classroomTables.columnAllowMany') },
  { key: 'actions', label: t('admin.classroomTables.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const bulkOpen = ref(false)
const editingTable = ref<ClassroomTable | null>(null)
const savedNote = ref<string | null>(null)

function openCreate() {
  bulkOpen.value = true
}

async function onBulkSaved(result: BulkTablesResult) {
  savedNote.value = result.skipped > 0
    ? t('admin.classroomTables.bulkSavedSkipped', { created: result.created, skipped: result.skipped })
    : t('admin.classroomTables.bulkSaved', { created: result.created })
  await loadTables()
}

// The checkbox in the list saves at once.
const toggling = ref<number | null>(null)
async function toggleAllowMany(table: ClassroomTable) {
  toggling.value = table.id
  deleteError.value = null
  try {
    const updated = await classroomTablesService.update(table.id, { allow_many_students: !table.allow_many_students })
    table.allow_many_students = updated.allow_many_students
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.classroomTables.saveFailed')
  } finally {
    toggling.value = null
  }
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
      <div class="mb-6 flex items-center justify-between gap-4">
        <div>
          <h1 class="text-xl font-semibold text-neutral-900">{{ classroom.name }}</h1>
          <p class="mt-1 text-sm text-neutral-500">{{ t('admin.classroomTables.pageSubtitle') }}</p>
        </div>
        <BaseButton class="shrink-0 whitespace-nowrap" @click="openCreate">{{ t('admin.classroomTables.addTable') }}</BaseButton>
      </div>

      <BaseAlert v-if="savedNote" variant="success" class="mb-4">{{ savedNote }}</BaseAlert>
      <BaseAlert v-if="deleteError" variant="danger" class="mb-4">{{ deleteError }}</BaseAlert>

      <div class="sm:hidden">
        <p v-if="tables.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('admin.classroomTables.emptyMessage') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="row in tables" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800">{{ row.name }}</p>
              <span class="text-xs text-neutral-500">{{ t('admin.classroomTables.columnOrder') }}: {{ row.sort_order }}</span>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3">
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input
                  type="checkbox"
                  :checked="row.allow_many_students"
                  :disabled="toggling === row.id"
                  class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                  @change="toggleAllowMany(row)"
                />
                {{ t('admin.classroomTables.columnAllowMany') }}
              </label>
              <div class="flex gap-3">
                <EditIconButton @click="openEdit(row)" />
                <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.classroomTables.delete') }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <DataTable class="hidden sm:block" :columns="columns" :rows="tables" row-key="id" :empty-message="t('admin.classroomTables.emptyMessage')">
        <template #cell-allow_many_students="{ row }">
          <input
            type="checkbox"
            :checked="row.allow_many_students"
            :disabled="toggling === row.id"
            :aria-label="t('admin.classroomTables.columnAllowMany')"
            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
            @change="toggleAllowMany(row)"
          />
        </template>
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
      <ClassroomTablesBulkModal v-model="bulkOpen" :classroom="classroom" @saved="onBulkSaved" />
    </template>
  </div>
</template>
