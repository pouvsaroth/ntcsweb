<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetIssuesService, issuePriorities, issueStatuses, type AssetIssue, type IssuePriority, type IssueStatus } from '@/services/assetIssues'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<AssetIssue>((query) => assetIssuesService.list(query))

function priorityKey(priority: IssuePriority): string {
  return priority.charAt(0) + priority.slice(1).toLowerCase()
}

function statusKey(status: IssueStatus): string {
  return status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
}

const priorityVariant: Record<IssuePriority, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  LOW: 'neutral', MEDIUM: 'primary', HIGH: 'warning', CRITICAL: 'danger',
}

const selectedPriority = ref('')
const selectedStatus = ref('')

const priorityOptions = computed(() => issuePriorities.map((p) => ({ value: p, label: t(`admin.assetIssues.priority${priorityKey(p)}`) })))
const statusOptions = computed(() => issueStatuses.map((s) => ({ value: s, label: t(`admin.assetIssues.issueStatus${statusKey(s)}`) })))

function onPriorityFilterChange(value: string) {
  selectedPriority.value = value
  setFilter('priority', value || undefined)
}

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const actionError = ref<string | null>(null)

async function resolveIssue(issue: AssetIssue) {
  if (!window.confirm(t('admin.assetIssues.resolveConfirm'))) return
  actionError.value = null

  try {
    await assetIssuesService.resolve(issue.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.assetIssues.actionFailed')
  }
}

const columns = computed(() => [
  { key: 'issue_number', label: t('admin.assetIssues.columnNumber') },
  { key: 'asset', label: t('admin.assetIssues.columnAsset') },
  { key: 'title', label: t('admin.assetIssues.issueTitle') },
  { key: 'priority', label: t('admin.assetIssues.priority') },
  { key: 'status', label: t('admin.assetIssues.status') },
  { key: 'reported_date', label: t('admin.assetIssues.reportedDate') },
  { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
])

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.assetIssues.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.assetIssues.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('common.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="selectedPriority" :options="priorityOptions" :placeholder="t('admin.assetIssues.filterAllPriorities')" @update:model-value="onPriorityFilterChange" />
      <BaseSelect :model-value="selectedStatus" :options="statusOptions" :placeholder="t('admin.assetIssues.filterAllStatuses')" @update:model-value="onStatusFilterChange" />
    </div>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.assetIssues.emptyMessage')">
      <template #cell-asset="{ row }">
        <RouterLink v-if="row.asset" :to="`/admin/assets/${row.asset.id}`" class="text-primary-700 hover:underline">{{ row.asset.asset_number }}</RouterLink>
        <span v-else>—</span>
      </template>
      <template #cell-priority="{ row }">
        <BaseBadge :variant="priorityVariant[row.priority]">{{ t(`admin.assetIssues.priority${priorityKey(row.priority)}`) }}</BaseBadge>
      </template>
      <template #cell-status="{ row }">{{ t(`admin.assetIssues.issueStatus${statusKey(row.status)}`) }}</template>
      <template #cell-reported_date="{ row }">{{ row.reported_date ? new Date(row.reported_date).toLocaleDateString() : '—' }}</template>
      <template #cell-actions="{ row }">
        <button
          v-if="!['RESOLVED', 'CLOSED', 'CANCELLED'].includes(row.status)"
          type="button"
          class="text-sm font-medium text-primary-700 hover:underline"
          @click="resolveIssue(row)"
        >
          {{ t('admin.assetIssues.resolve') }}
        </button>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
