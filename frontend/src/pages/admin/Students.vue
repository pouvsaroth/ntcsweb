<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { studentsService, type Student, type StudentStatus } from '@/services/students'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const items = ref<Student[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const error = ref<string | null>(null)
const search = ref('')
const cursor = ref<string | null>(null)
const hasMore = ref(false)

let searchDebounce: ReturnType<typeof setTimeout> | undefined

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

async function fetch(reset: boolean) {
  if (reset) {
    loading.value = true
    cursor.value = null
  } else {
    loadingMore.value = true
  }
  error.value = null

  try {
    const result = await studentsService.list({ search: search.value, cursor: reset ? null : cursor.value })
    items.value = reset ? result.data : [...items.value, ...result.data]
    cursor.value = result.pagination.type === 'cursor' ? result.pagination.next_cursor : null
    hasMore.value = result.pagination.type === 'cursor' ? result.pagination.has_more : false
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.students.loadFailed')
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function onSearchInput() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => fetch(true), 350)
}

onMounted(() => fetch(true))
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.students.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.students.listSubtitle') }}</p>
      </div>
      <BaseButton to="/admin/students/new">{{ t('admin.students.registerTitle') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        v-model="search"
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="onSearchInput"
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
          <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-neutral-100">
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-neutral-800">{{ row.full_name }}</p>
            <p class="truncate text-xs text-neutral-500">{{ row.phone || '—' }}</p>
          </div>
          <RouterLink
            :to="`/admin/students/${row.id}/edit`"
            :aria-label="t('admin.students.edit')"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </RouterLink>
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
          <div class="h-10 w-10 overflow-hidden rounded-full bg-neutral-100">
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </div>
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
          <div class="flex justify-end gap-2">
            <BaseButton :to="`/admin/students/${row.id}/edit`" variant="ghost" size="sm">{{ t('admin.students.edit') }}</BaseButton>
          </div>
        </template>
      </DataTable>
    </div>

    <div v-if="hasMore" class="mt-4 flex justify-center">
      <BaseButton variant="outline" :loading="loadingMore" @click="fetch(false)">{{ t('common.loadMore') }}</BaseButton>
    </div>
  </div>
</template>
