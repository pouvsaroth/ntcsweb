<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { studentsService, type Student, type StudentStatus } from '@/services/students'
import { useAdminUiStore } from '@/stores/adminUi'

const { t } = useI18n()
const adminUi = useAdminUiStore()

const previewStudent = ref<Student | null>(null)

function openPhotoPreview(row: Student) {
  if (!row.photo_url) return
  previewStudent.value = row
}

const { items, meta, loading, error, perPage, setPage, setSearch, fetch } = usePaginatedResource<Student>((query) =>
  studentsService.list(query),
)

const perPageOptions = [10, 25, 50, 100]

const columns = [
  { key: 'photo_url', label: t('admin.students.columnPhoto') },
  { key: 'full_name', label: t('admin.students.columnName') },
  { key: 'student_code', label: t('admin.students.columnCode') },
  { key: 'phone', label: t('admin.students.columnPhone') },
  { key: 'guardians', label: t('admin.students.columnGuardians') },
  { key: 'status', label: t('admin.students.columnStatus') },
  { key: 'actions', label: t('admin.students.columnActions'), align: 'text-right' },
]

const statusBadgeVariant: Record<StudentStatus, 'success' | 'neutral' | 'danger' | 'warning'> = {
  active: 'success',
  graduated: 'neutral',
  withdrawn: 'danger',
  inactive: 'warning',
}

function statusLabel(status: StudentStatus): string {
  return t(`admin.students.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.students.title') }}</h1>
      </div>
      <BaseButton to="/admin/students/new">{{ t('admin.students.registerTitle') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens — a table's columns don't have room to breathe
         on a phone; below sm: this replaces the DataTable entirely. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.students.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="flex items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button
            type="button"
            class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-neutral-100"
            :class="row.photo_url ? 'cursor-pointer' : 'cursor-default'"
            @click="openPhotoPreview(row)"
          >
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-neutral-800">{{ row.full_name }}</p>
            <p class="truncate text-xs text-neutral-500">{{ row.phone || '—' }}</p>
          </div>
          <EditIconButton :to="`/admin/students/${row.id}/edit`" />
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable
        :columns="columns"
        :rows="items"
        row-key="id"
        :loading="loading"
        :empty-message="t('admin.students.emptyMessage')"
      >
        <template #cell-photo_url="{ row }">
          <button
            type="button"
            class="h-10 w-10 overflow-hidden rounded-full bg-neutral-100"
            :class="row.photo_url ? 'cursor-pointer' : 'cursor-default'"
            @click="openPhotoPreview(row)"
          >
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </button>
        </template>
        <template #cell-full_name="{ row }">
          <p class="font-medium text-neutral-800">{{ row.full_name }}</p>
          <p v-if="row.english_name" class="text-xs text-neutral-500">{{ row.english_name }}</p>
        </template>
        <template #cell-guardians="{ row }">
          {{ row.guardians_count ?? 0 }}
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusBadgeVariant[row.status as StudentStatus]">
            {{ statusLabel(row.status) }}
          </BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end">
            <EditIconButton :to="`/admin/students/${row.id}/edit`" />
          </div>
        </template>
      </DataTable>
    </div>

    <!-- The per-page selector and pager stick together as one bar — sticky
         only on the BasePagination inside would leave this selector behind
         when the pager pins to the bottom. `fixed`, not `sticky` — see
         BasePagination's `sticky` prop doc for why. -->
    <div
      v-if="meta"
      class="fixed inset-x-0 bottom-0 z-10 mt-4 flex flex-col items-center gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:flex-row sm:justify-between sm:px-6"
      :class="adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64'"
    >
      <label class="flex items-center gap-2 text-sm text-neutral-500">
        {{ t('admin.students.perPage') }}
        <select
          v-model.number="perPage"
          class="rounded-lg border border-neutral-300 py-1.5 pl-2 pr-7 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        >
          <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
        </select>
      </label>

      <BasePagination :meta="meta" @update:page="setPage" />
    </div>

    <BaseModal :model-value="previewStudent !== null" :title="previewStudent?.full_name" @update:model-value="previewStudent = null">
      <img v-if="previewStudent?.photo_url" :src="previewStudent.photo_url" alt="" class="mx-auto max-h-[70vh] w-auto rounded-lg object-contain" />
    </BaseModal>
  </div>
</template>
