<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { classesService, type ClassStatus, type SchoolClass } from '@/services/classes'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<SchoolClass>((query) =>
  classesService.list(query),
)

const columns = [
  { key: 'name', label: t('admin.classes.columnName') },
  { key: 'schedule', label: t('admin.classes.columnSchedule') },
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

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.classes.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.classes.pageSubtitle') }}</p>
      </div>
      <BaseButton to="/admin/classes/new">{{ t('admin.classes.addClass') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.classes.emptyMessage')">
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-800">{{ row.name }}</p>
        <p v-if="row.teacher || row.classroom" class="text-xs text-neutral-500">
          {{ [row.teacher?.name, row.classroom?.name].filter(Boolean).join(' · ') }}
        </p>
      </template>
      <template #cell-schedule="{ row }">{{ scheduleSummary(row) }}</template>
      <template #cell-books="{ row }">{{ row.books.map((b) => b.title).join(', ') || '—' }}</template>
      <template #cell-enrollments_count="{ row }">{{ row.enrollments_count ?? 0 }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.status]">
          {{ t(`admin.classes.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <BaseButton :to="`/admin/classes/${row.id}/edit`" variant="ghost" size="sm">{{ t('admin.classes.edit') }}</BaseButton>
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.classes.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>
