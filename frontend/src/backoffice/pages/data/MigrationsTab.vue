<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDateTime } from '@/utils/date'

import { dataApi, type MigrationRunRow } from '../../api'

/** Migrate / create-database runs, one row per school, newest first. Refreshes while any is unfinished. */
const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<MigrationRunRow>((query) => dataApi.migrationRuns(query))

const statusVariant = { queued: 'neutral', running: 'primary', ok: 'success', failed: 'danger' } as const

const columns = computed(() => [
  { key: 'created_at', label: t('bo.data.when') },
  { key: 'school', label: t('bo.data.school') },
  { key: 'kind', label: t('bo.data.kind') },
  { key: 'status', label: t('bo.data.status') },
  { key: 'applied', label: t('bo.data.applied'), align: 'text-right' },
  { key: 'duration_ms', label: t('bo.data.duration'), align: 'text-right' },
])

let timer: ReturnType<typeof setInterval> | undefined
watch(items, (rows) => {
  clearInterval(timer)
  if (rows.some((r) => r.status === 'queued' || r.status === 'running')) timer = setInterval(() => void fetch(), 3000)
})
onBeforeUnmount(() => clearInterval(timer))
onMounted(() => fetch())

const seconds = (ms: number | null) => (ms === null ? '—' : `${(ms / 1000).toFixed(1)} s`)
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading && !items.length" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.data.emptyRuns') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="font-medium text-neutral-900">{{ row.school?.name ?? '—' }}</p>
            <BaseBadge :variant="statusVariant[row.status]">{{ t(`bo.data.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t(`bo.data.kinds.${row.kind}`) }} · {{ formatDateTime(row.created_at) }}{{ row.staff ? ` · ${row.staff}` : '' }}
          </p>
          <p v-if="row.status === 'ok'" class="mt-1 text-xs text-neutral-600">{{ t('bo.data.applied') }}: {{ row.applied }} · {{ seconds(row.duration_ms) }}</p>
          <p v-if="row.error" class="mt-1 font-mono text-xs break-all text-red-700">{{ row.error }}</p>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading && !items.length" :empty-message="t('bo.data.emptyRuns')">
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
          <p v-if="row.staff" class="text-xs text-neutral-500">{{ row.staff }}</p>
        </template>
        <template #cell-school="{ row }">
          <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="hover:underline">{{ row.school.name }}</RouterLink>
          <p v-if="row.error" class="mt-1 max-w-sm font-mono text-xs break-all text-red-700">{{ row.error }}</p>
        </template>
        <template #cell-kind="{ row }">{{ t(`bo.data.kinds.${row.kind}`) }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status as MigrationRunRow['status']]">{{ t(`bo.data.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-applied="{ row }"><span class="tabular-nums">{{ row.applied ?? '—' }}</span></template>
        <template #cell-duration_ms="{ row }"><span class="tabular-nums">{{ seconds(row.duration_ms) }}</span></template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>
