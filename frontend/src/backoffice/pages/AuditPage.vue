<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDateTime } from '@/utils/date'

import { auditApi, type AuditLog } from '../api'

/** What staff did — read-only. Newest first; filter by action text and date range. */
const { t } = useI18n()

const from = ref('')
const to = ref('')

const { items, meta, loading, error, search, setSearch, setPage, fetch } = usePaginatedResource<AuditLog>((query) =>
  auditApi.list({ ...query, from: from.value || undefined, to: to.value || undefined }),
)

const columns = computed(() => [
  { key: 'created_at', label: t('bo.audit.when') },
  { key: 'action', label: t('bo.audit.action') },
  { key: 'staff', label: t('bo.audit.staff') },
  { key: 'target', label: t('bo.audit.target') },
  { key: 'ip_address', label: t('bo.audit.ip') },
  { key: 'details', label: t('bo.audit.details'), align: 'text-right' },
])

const open = ref<number | null>(null)

function toggle(id: number): void {
  open.value = open.value === id ? null : id
}

function target(row: AuditLog): string {
  const parts = [row.target_type && row.target_id ? `${row.target_type} #${row.target_id}` : null]
  if (row.tenant_id) parts.push(`${t('bo.audit.school')} #${row.tenant_id}`)
  return parts.filter(Boolean).join(' · ') || '—'
}

function hasDetails(row: AuditLog): boolean {
  return row.old_values !== null || row.new_values !== null
}

function setRange(which: 'from' | 'to', value: string): void {
  if (which === 'from') from.value = value
  else to.value = value
  setPage(1)
}

const pretty = (value: unknown) => JSON.stringify(value, null, 2)

onMounted(() => fetch())
</script>

<template>
  <div>
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">{{ t('bo.audit.title') }}</h1>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
      <BaseInput :model-value="search" :label="t('bo.audit.filterAction')" placeholder="staff.created" @update:model-value="setSearch" />
      <BaseInput :model-value="from" :label="t('bo.audit.from')" type="date" @update:model-value="setRange('from', $event)" />
      <BaseInput :model-value="to" :label="t('bo.audit.to')" type="date" @update:model-value="setRange('to', $event)" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.audit.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <BaseBadge :variant="row.action === 'permission.denied' ? 'danger' : 'neutral'">{{ row.action }}</BaseBadge>
            <span class="text-xs text-neutral-500">{{ formatDateTime(row.created_at) }}</span>
          </div>
          <p class="mt-2 text-sm text-neutral-800">{{ row.staff?.name ?? t('bo.audit.system') }}</p>
          <p class="text-xs text-neutral-500">{{ target(row) }} · {{ row.ip_address ?? '—' }}</p>
          <button v-if="hasDetails(row)" type="button" class="mt-2 text-xs font-medium text-secondary-600 hover:underline" @click="toggle(row.id)">
            {{ t('bo.audit.details') }}
          </button>
          <div v-if="open === row.id" class="mt-2 space-y-2">
            <pre v-if="row.old_values" class="overflow-x-auto rounded bg-neutral-100 p-2 text-xs">{{ t('bo.audit.before') }}: {{ pretty(row.old_values) }}</pre>
            <pre v-if="row.new_values" class="overflow-x-auto rounded bg-neutral-100 p-2 text-xs">{{ t('bo.audit.after') }}: {{ pretty(row.new_values) }}</pre>
          </div>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('bo.audit.empty')">
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
        </template>
        <template #cell-action="{ row }">
          <BaseBadge :variant="row.action === 'permission.denied' ? 'danger' : 'neutral'">{{ row.action }}</BaseBadge>
        </template>
        <template #cell-staff="{ row }">{{ row.staff?.name ?? t('bo.audit.system') }}</template>
        <template #cell-target="{ row }">{{ target(row) }}</template>
        <template #cell-ip_address="{ row }">{{ row.ip_address ?? '—' }}</template>
        <template #cell-details="{ row }">
          <div class="text-right">
            <button v-if="hasDetails(row)" type="button" class="text-xs font-medium text-secondary-600 hover:underline" @click="toggle(row.id)">
              {{ t('bo.audit.details') }}
            </button>
            <div v-if="open === row.id" class="mt-2 grid max-w-md gap-2 text-left">
              <pre v-if="row.old_values" class="overflow-x-auto rounded bg-neutral-100 p-2 text-xs">{{ t('bo.audit.before') }}: {{ pretty(row.old_values) }}</pre>
              <pre v-if="row.new_values" class="overflow-x-auto rounded bg-neutral-100 p-2 text-xs">{{ t('bo.audit.after') }}: {{ pretty(row.new_values) }}</pre>
            </div>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>
