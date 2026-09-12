<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { classStatuses, classesService, type ClassStatus, type SchoolClass } from '@/services/classes'

const { t } = useI18n()

const { items, meta, loading, error, sort, setPage, setSearch, setSort, setFilter, fetch } = usePaginatedResource<SchoolClass>((query) =>
  classesService.list(query),
)

const selectedStatus = ref<ClassStatus | ''>('active')
const onlyStudying = ref(true)

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

function onOnlyStudyingChange(checked: boolean) {
  onlyStudying.value = checked
  setFilter('has_active_enrollment', checked ? '1' : undefined)
}

const columns = [
  { key: 'name', label: t('admin.classes.columnName'), sortable: true },
  { key: 'schedule', label: t('admin.classes.columnSchedule') },
  { key: 'program', label: t('admin.classes.columnProgram') },
  { key: 'active_students_count', label: t('admin.classes.columnActiveStudents') },
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

onMounted(() => {
  setFilter('status', selectedStatus.value || undefined)
  setFilter('has_active_enrollment', onlyStudying.value ? '1' : undefined)
})
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
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            :checked="onlyStudying"
            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
            @change="onOnlyStudyingChange(($event.target as HTMLInputElement).checked)"
          />
          {{ t('admin.classes.onlyStudyingFilter') }}
        </label>
      </div>
      <BaseButton to="/admin/classes/new">{{ t('admin.classes.addClass') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens — a table's columns don't have room to breathe
         on a phone; below sm: this replaces the DataTable entirely. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.classes.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <RouterLink :to="`/admin/classes/${row.id}/students`" class="min-w-0 flex-1 truncate font-medium text-primary-700 hover:text-primary-800 hover:underline">
              {{ row.name }}
            </RouterLink>
            <BaseBadge :variant="statusBadgeVariant[row.status]">
              {{ t(`admin.classes.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}
            </BaseBadge>
          </div>
          <p class="mt-1 truncate text-xs text-neutral-500">{{ row.teachers.map((t) => t.name).join(', ') || '—' }}</p>
          <div class="mt-2 flex items-center justify-between">
            <p class="text-xs text-neutral-500">{{ t('admin.classes.columnActiveStudents') }}: <span class="font-medium text-neutral-800">{{ row.active_students_count ?? 0 }}</span></p>
            <div class="flex items-center gap-3">
              <EditIconButton :to="`/admin/classes/${row.id}/edit`" />
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
                {{ t('admin.classes.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
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
          <p v-if="row.teachers.length > 0 || row.classroom" class="text-xs text-neutral-500">
            {{ [row.teachers.map((t) => t.name).join(', ') || null, row.classroom?.name].filter(Boolean).join(' · ') }}
          </p>
        </template>
        <template #cell-schedule="{ row }">{{ scheduleSummary(row) }}</template>
        <template #cell-program="{ row }">{{ row.academic_program?.name ?? '—' }}</template>
        <template #cell-active_students_count="{ row }">{{ row.active_students_count ?? 0 }}</template>
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
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
