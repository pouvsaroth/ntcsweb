<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { classStatuses, classesService, type ClassStatus, type SchoolClass } from '@/services/classes'

const { t } = useI18n()

const { items, meta, loading, error, sort, setPage, setSearch, setSort, setFilter, fetch } = usePaginatedResource<SchoolClass>((query) =>
  classesService.list(query),
)

const selectedStatus = ref<ClassStatus | ''>('active')

const statusFilterOptions = computed(() => [
  { value: '', label: t('admin.classes.filterAllStatuses') },
  ...classStatuses.map((status) => ({
    value: status,
    label: t(`admin.classes.status${status.charAt(0).toUpperCase()}${status.slice(1)}`),
  })),
])

function onStatusFilterChange(value: string) {
  selectedStatus.value = value as ClassStatus | ''
  setFilter('status', value || undefined)
}

const columns = [
  { key: 'name', label: t('admin.classes.columnName'), sortable: true },
  { key: 'schedule', label: t('admin.classes.columnSchedule') },
  { key: 'program', label: t('admin.classes.columnProgram') },
  { key: 'books', label: t('admin.classes.columnBooks') },
  { key: 'enrollments_count', label: t('admin.classes.columnEnrollments') },
  { key: 'status', label: t('admin.classes.columnStatus') },
  { key: 'actions', label: t('admin.classes.columnActions'), align: 'text-right' },
]

const statusBadgeVariant: Record<ClassStatus, 'primary' | 'success' | 'neutral' | 'danger'> = {
  upcoming: 'primary',
  active: 'success',
  completed: 'neutral',
  cancelled: 'danger',
}

function scheduleSummary(row: SchoolClass): string {
  if (row.schedules.length === 0) return '—'
  return row.schedules.map((s) => `${s.day_name} ${s.start_time}-${s.end_time}`).join(', ')
}

async function remove(row: SchoolClass) {
  if (!window.confirm(t('admin.classes.deleteConfirm'))) return
  await classesService.remove(row.id)
  await fetch()
}

onMounted(() => setFilter('status', selectedStatus.value || undefined))
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3">
        <input
          type="search"
          :placeholder="t('common.searchPlaceholder')"
          class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          @input="setSearch(($event.target as HTMLInputElement).value)"
        />
        <BaseSelect
          :model-value="selectedStatus"
          :options="statusFilterOptions"
          class="w-48"
          @update:model-value="onStatusFilterChange"
        />
      </div>
      <BaseButton to="/admin/classes/new">{{ t('admin.classes.addClass') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.classes.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-name="{ row }">
        <RouterLink :to="`/admin/classes/${row.id}/students`" class="font-medium text-primary-700 hover:text-primary-800 hover:underline">
          {{ row.name }}
        </RouterLink>
        <p v-if="row.teacher || row.classroom" class="text-xs text-neutral-500">
          {{ [row.teacher?.name, row.classroom?.name].filter(Boolean).join(' · ') }}
        </p>
      </template>
      <template #cell-schedule="{ row }">{{ scheduleSummary(row) }}</template>
      <template #cell-program="{ row }">{{ row.academic_program?.name ?? '—' }}</template>
      <template #cell-books="{ row }">{{ row.books.map((b) => b.title).join(', ') || '—' }}</template>
      <template #cell-enrollments_count="{ row }">{{ row.enrollments_count ?? 0 }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.status]">
          {{ t(`admin.classes.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton :to="`/admin/classes/${row.id}/edit`" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.classes.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
